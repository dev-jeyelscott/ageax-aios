<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;

class TaskValidator
{
    private const int EvidenceSummaryLimit = 4000;

    /** @var list<string> */
    private const array VERIFICATION_EXECUTABLES = [
        'php',
        'composer',
        'npm',
        'pnpm',
        'yarn',
        'bun',
        'npx',
        'git',
        'vendor/bin/pest',
        './vendor/bin/pest',
        'vendor/bin/phpstan',
        './vendor/bin/phpstan',
        'vendor/bin/pint',
        './vendor/bin/pint',
    ];

    public function __construct(private WorkspacePathResolver $paths, private AgentRunRecorder $runs) {}

    /** @return array{passed: bool, checks: array<string, bool>, evidence: array<string, array<string, mixed>>} */
    public function validate(Task $task): array
    {
        $path = $this->paths->assertProjectPath($task->project->path);
        $diff = Process::path($path)->run(['git', 'diff', '--check']);
        $secrets = Process::path($path)->run(['rg', '--hidden', '--glob', '!.git/**', '--glob', '!node_modules/**', '(AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|ghp_[A-Za-z0-9]{36})']);
        $status = Process::path($path)->run(['git', 'status', '--porcelain']);
        $changedFiles = collect(preg_split('/\R/', $status->output()) ?: [])
            ->filter(fn (string $line): bool => $line !== '')
            ->map(fn (string $line): string => trim(substr($line, 3)))
            ->filter()
            ->values();
        $forbiddenFiles = $changedFiles->contains(fn (string $file): bool => $this->isForbiddenFile($file));
        $verification = $this->runVerificationCommands($task);
        $checks = [
            'git_diff_check' => $diff->successful(),
            'secret_scan' => $secrets->exitCode() === 1,
            'forbidden_file_check' => ! $forbiddenFiles,
            'task_verification' => $verification['passed'],
        ];

        return [
            'passed' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'evidence' => [
                'git_diff_check' => $this->processEvidence('git_diff_check', $checks['git_diff_check'], 'git diff --check', $diff),
                'secret_scan' => $this->secretScanEvidence($checks['secret_scan'], $secrets),
                'forbidden_file_check' => [
                    'name' => 'forbidden_file_check',
                    'passed' => $checks['forbidden_file_check'],
                    'verification_identifier' => 'git status --porcelain',
                    'exit_code' => $status->exitCode(),
                    'files' => $forbiddenFiles ? $changedFiles->filter(fn (string $file): bool => $this->isForbiddenFile($file))->values()->all() : [],
                    'summary' => $forbiddenFiles ? 'Forbidden files were detected in the working tree.' : null,
                ],
                'task_verification' => $verification['evidence'],
            ],
        ];
    }

    private function isForbiddenFile(string $file): bool
    {
        $filename = basename($file);

        return $filename === '.env'
            || (Str::startsWith($filename, '.env.') && $filename !== '.env.example')
            || preg_match('/^(?:id_rsa|.*\.(?:pem|key))$/', $filename) === 1;
    }

    /** @return array{passed: bool, evidence: array<string, mixed>} */
    private function runVerificationCommands(Task $task): array
    {
        $commands = $this->verificationCommands($task);
        if ($commands === null) {
            return ['passed' => false, 'evidence' => ['name' => 'task_verification', 'passed' => false, 'verification_identifier' => 'configured_verification_commands', 'exit_code' => null, 'commands' => [], 'summary' => 'Verification commands could not be decoded.']];
        }

        $evidence = [];
        foreach ($commands as $command) {
            if (! $this->isSafeVerificationCommand($command)) {
                return ['passed' => false, 'evidence' => ['name' => 'task_verification', 'passed' => false, 'verification_identifier' => 'configured_verification_commands', 'exit_code' => null, 'commands' => $evidence, 'summary' => 'A configured verification command is not allowed.']];
            }

            $result = Process::path($this->paths->assertProjectPath($task->project->path))
                ->timeout((int) config('aios.execution_timeout'))
                ->run(preg_split('/\s+/', trim($command)) ?: []);

            $commandEvidence = $this->processEvidence('task_verification_command', $result->successful(), $command, $result);
            $evidence[] = $commandEvidence;
            if ($result->failed()) {
                return ['passed' => false, 'evidence' => ['name' => 'task_verification', 'passed' => false, 'verification_identifier' => 'task_verification_commands', 'exit_code' => $result->exitCode(), 'commands' => $evidence, 'summary' => 'A task verification command failed.']];
            }
        }

        return ['passed' => true, 'evidence' => ['name' => 'task_verification', 'passed' => true, 'verification_identifier' => 'task_verification_commands', 'exit_code' => 0, 'commands' => $evidence, 'summary' => null]];
    }

    /** @return array{name: string, passed: bool, verification_identifier: string, exit_code: ?int, summary: ?string} */
    private function processEvidence(string $name, bool $passed, string $identifier, ProcessResult $result): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'verification_identifier' => $identifier,
            'exit_code' => $result->exitCode(),
            'summary' => $passed ? null : $this->boundedSummary($result->output(), $result->errorOutput()),
        ];
    }

    /** @return array{name: string, passed: bool, verification_identifier: string, exit_code: ?int, summary: ?string} */
    private function secretScanEvidence(bool $passed, ProcessResult $result): array
    {
        return [
            'name' => 'secret_scan',
            'passed' => $passed,
            'verification_identifier' => 'secret_scan',
            'exit_code' => $result->exitCode(),
            'summary' => $passed ? null : ($result->exitCode() === 0 ? 'Potential secret material was detected. Match details were intentionally omitted.' : 'The secret scan did not complete successfully.'),
        ];
    }

    private function boundedSummary(string $output, string $errorOutput): ?string
    {
        $summary = trim($this->runs->redact($output."\n".$errorOutput));
        if ($summary === '') {
            return null;
        }

        return Str::length($summary) > self::EvidenceSummaryLimit
            ? '…'.Str::substr($summary, -self::EvidenceSummaryLimit)
            : $summary;
    }

    /** @return array<int, string>|null */
    private function verificationCommands(Task $task): ?array
    {
        $rawCommands = $task->getRawOriginal('verification_commands');
        if ($rawCommands === null) {
            return [];
        }

        try {
            $commands = json_decode($rawCommands, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($commands) || collect($commands)->contains(fn (mixed $command): bool => ! is_string($command))) {
            return null;
        }

        return array_values($commands);
    }

    private function isSafeVerificationCommand(string $command): bool
    {
        if (preg_match('~^[A-Za-z0-9_./:=+,@-]+(?: [A-Za-z0-9_./:=+,@-]+)*$~', $command) !== 1) {
            return false;
        }

        $arguments = preg_split('/\s+/', trim($command)) ?: [];
        $executable = $arguments[0] ?? '';

        if (in_array($executable, self::VERIFICATION_EXECUTABLES, true)) {
            return true;
        }

        return $this->isSafeDockerComposeVerification($arguments)
            || $this->isSafeSailVerification($arguments);
    }

    /** @param list<string> $arguments */
    private function isSafeDockerComposeVerification(array $arguments): bool
    {
        if (($arguments[0] ?? null) !== 'docker' || ($arguments[1] ?? null) !== 'compose') {
            return false;
        }

        $index = 2;
        if (($arguments[$index] ?? null) === '-f') {
            if (! in_array($arguments[$index + 1] ?? null, ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'], true)) {
                return false;
            }

            $index += 2;
        }

        if (($arguments[$index] ?? null) !== 'exec' || ($arguments[$index + 1] ?? null) !== '-T') {
            return false;
        }

        $service = $arguments[$index + 2] ?? '';
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $service) !== 1) {
            return false;
        }

        $innerExecutable = $arguments[$index + 3] ?? '';

        return in_array($innerExecutable, self::VERIFICATION_EXECUTABLES, true) && $innerExecutable !== 'git';
    }

    /** @param list<string> $arguments */
    private function isSafeSailVerification(array $arguments): bool
    {
        if (! in_array($arguments[0] ?? null, ['vendor/bin/sail', './vendor/bin/sail'], true)) {
            return false;
        }

        return in_array($arguments[1] ?? null, ['artisan', 'php', 'composer', 'npm', 'pnpm', 'yarn', 'bun', 'npx', 'pest', 'test'], true);
    }
}
