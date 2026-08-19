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
    private const array ForbiddenArtisanCommands = [
        'db:seed',
        'db:wipe',
        'migrate',
        'migrate:fresh',
        'migrate:install',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'model:prune',
        'schema:dump',
    ];

    public function __construct(
        private WorkspacePathResolver $paths,
        private AgentRunRecorder $runs,
        private SanitizedExecutionEnvironment $environment,
    ) {}

    /** @return array{passed: bool, checks: array<string, bool>, evidence: array<string, array<string, mixed>>} */
    public function validate(Task $task): array
    {
        $diff = $this->runManagedProjectProcess($task, ['git', 'diff', '--check']);
        $secrets = $this->runManagedProjectProcess($task, ['rg', '--hidden', '--glob', '!.git/**', '--glob', '!node_modules/**', '(AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|ghp_[A-Za-z0-9]{36})']);
        $status = $this->runManagedProjectProcess($task, ['git', 'status', '--porcelain']);
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

            $result = $this->runManagedProjectProcess(
                $task,
                preg_split('/\s+/', trim($command)) ?: [],
                (int) config('aios.execution_timeout'),
            );

            $commandEvidence = $this->processEvidence('task_verification_command', $result->successful(), $command, $result);
            $evidence[] = $commandEvidence;
            if ($result->failed()) {
                return ['passed' => false, 'evidence' => ['name' => 'task_verification', 'passed' => false, 'verification_identifier' => 'task_verification_commands', 'exit_code' => $result->exitCode(), 'commands' => $evidence, 'summary' => 'A task verification command failed.']];
            }
        }

        return ['passed' => true, 'evidence' => ['name' => 'task_verification', 'passed' => true, 'verification_identifier' => 'task_verification_commands', 'exit_code' => 0, 'commands' => $evidence, 'summary' => null]];
    }

    /**
     * Every TaskValidator subprocess crosses into a managed project. Keep execution centralized
     * here so no validation check can silently inherit AIOS database credentials or application
     * secrets from the worker process.
     *
     * @param  list<string>  $command
     */
    private function runManagedProjectProcess(Task $task, array $command, ?int $timeout = null): ProcessResult
    {
        $pending = Process::path($this->paths->assertProjectPath($task->project->path));

        if ($timeout !== null) {
            $pending = $pending->timeout($timeout);
        }

        return $pending->run($this->environment->wrap($command));
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

    /** @param list<string> $commands */
    public function verificationCommandsAreSafe(array $commands): bool
    {
        return ! collect($commands)->contains(
            fn (string $command): bool => ! $this->isSafeVerificationCommand($command),
        );
    }

    private function isSafeVerificationCommand(string $command): bool
    {
        if (preg_match('~^[A-Za-z0-9_./:=+,@-]+(?: [A-Za-z0-9_./:=+,@-]+)*$~', $command) !== 1) {
            return false;
        }

        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        $executable = $tokens[0] ?? '';

        if ($executable === 'docker') {
            return $this->isSafeDockerComposeVerificationCommand($tokens);
        }

        if (! in_array($executable, $this->verificationExecutables(), true)) {
            return false;
        }

        return ! $this->isForbiddenArtisanInvocation($tokens);
    }

    /** @param list<string> $tokens */
    private function isSafeDockerComposeVerificationCommand(array $tokens): bool
    {
        if (($tokens[1] ?? null) !== 'compose') {
            return false;
        }

        $subcommand = $tokens[2] ?? null;
        if ($subcommand === 'ps') {
            return true;
        }

        if ($subcommand === 'config') {
            $option = $tokens[3] ?? null;

            return count($tokens) === 4 && $option !== null && in_array($option, ['--services', '--images', '--profiles', '--quiet'], true);
        }

        if ($subcommand !== 'exec') {
            return false;
        }

        $index = 3;
        while (in_array($tokens[$index] ?? null, ['-T', '--no-TTY'], true)) {
            $index++;
        }

        $service = $tokens[$index] ?? null;
        $innerExecutable = $tokens[$index + 1] ?? null;
        if ($service === null || preg_match('/^[A-Za-z0-9_.-]+$/', $service) !== 1 || $innerExecutable === null) {
            return false;
        }

        if ($innerExecutable === 'psql') {
            return array_slice($tokens, $index + 2) === ['--version'];
        }

        if ($innerExecutable === 'pg_isready') {
            return true;
        }

        if (! in_array($innerExecutable, $this->verificationExecutables(), true)) {
            return false;
        }

        return ! $this->isForbiddenArtisanInvocation(array_slice($tokens, $index + 1));
    }

    /** @param list<string> $tokens */
    private function isForbiddenArtisanInvocation(array $tokens): bool
    {
        if (($tokens[0] ?? null) !== 'php') {
            return false;
        }

        $artisanIndex = null;
        foreach ($tokens as $index => $token) {
            if (in_array($token, ['artisan', './artisan'], true)) {
                $artisanIndex = $index;
                break;
            }
        }

        if ($artisanIndex === null) {
            return false;
        }

        return collect(array_slice($tokens, $artisanIndex + 1))
            ->map(fn (string $token): string => strtolower($token))
            ->contains(fn (string $token): bool => in_array($token, self::ForbiddenArtisanCommands, true));
    }

    /** @return list<string> */
    private function verificationExecutables(): array
    {
        return ['php', 'composer', 'npm', 'pnpm', 'yarn', 'bun', 'npx', 'git', 'vendor/bin/pest', './vendor/bin/pest', 'vendor/bin/phpstan', './vendor/bin/phpstan', 'vendor/bin/pint', './vendor/bin/pint'];
    }
}
