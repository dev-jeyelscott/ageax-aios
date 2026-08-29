<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use App\Models\Project;
use Closure;
use JsonException;

final readonly class ClaudeCodeHarness implements AgentHarness
{
    private const int NormalizationFailureExitCode = 1;

    private const array Models = [
        'claude-fable-5',
        'claude-opus-5',
        'claude-sonnet-5',
        'claude-haiku-4-5-20251001',
    ];

    private const array ReasoningSettings = [
        'low',
        'medium',
        'high',
        'xhigh',
        'max',
    ];

    private const int ConservativeDefaultContextWindowTokens = 200000;

    private const int ConservativeDefaultMaxOutputTokens = 64000;

    public function __construct(
        private ClaudeCodeCliRunner $runner,
        private ContextBudgetedAgentHarness $contextBudget,
    ) {}

    /**
     * Return the Claude Code harness identifier used by persisted Agent configuration.
     */
    public function identifier(): AgentHarnessIdentifier
    {
        return AgentHarnessIdentifier::ClaudeCode;
    }

    /**
     * Return the approved Claude Code model, reasoning, execution, and capacity contract.
     */
    public function capabilities(): HarnessCapabilities
    {
        return new HarnessCapabilities(
            models: self::Models,
            reasoningSettings: self::ReasoningSettings,
            executionOptions: [
                'ephemeral',
                'streaming',
                'heartbeat',
            ],
            configurationFields: [
                'model',
                'reasoning_setting',
            ],
            reasoningSettingsByModel: [
                'claude-fable-5' => self::ReasoningSettings,
                'claude-opus-5' => self::ReasoningSettings,
                'claude-sonnet-5' => self::ReasoningSettings,
                'claude-haiku-4-5-20251001' => [],
            ],
            contextWindowTokensByModel: [
                'claude-fable-5' => 1000000,
                'claude-opus-5' => 1000000,
                'claude-sonnet-5' => 1000000,
                'claude-haiku-4-5-20251001' => 200000,
            ],
            maxOutputTokensByModel: [
                'claude-fable-5' => 128000,
                'claude-opus-5' => 128000,
                'claude-sonnet-5' => 128000,
                'claude-haiku-4-5-20251001' => 64000,
            ],
            defaultContextWindowTokens: self::ConservativeDefaultContextWindowTokens,
            defaultMaxOutputTokens: self::ConservativeDefaultMaxOutputTokens,
            capacityMetadataSource: 'anthropic_model_docs_2026_08_19',
            capacityMetadataVersion: 1,
        );
    }

    /**
     * Apply AIOS Context Budget policy, then execute Claude Code at the exact AIOS-selected workspace when supplied.
     *
     * @param  (Closure(string, string): void)|null  $onOutput
     * @param  (Closure(): mixed)|null  $onHeartbeat
     * @param  array<string, mixed>  $executionSettings
     */
    public function execute(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        array $executionSettings = [],
        ?string $executionPath = null,
    ): NormalizedExecutionResult {
        $this->capabilities()->assertSupports(
            $agent,
            $this->identifier(),
        );

        return $this->contextBudget->execute(
            $this,
            $project,
            $agent,
            $prompt,
            fn (
                string $approvedPrompt,
                ?Closure $outputCallback,
                ?Closure $heartbeatCallback,
                array $approvedExecutionSettings,
            ): NormalizedExecutionResult => $this->executeProvider(
                $project,
                $agent,
                $approvedPrompt,
                $outputCallback,
                $heartbeatCallback,
                $approvedExecutionSettings,
                $executionPath,
            ),
            $onOutput,
            $onHeartbeat,
            $executionSettings,
        );
    }

    /**
     * Dispatch the budget-approved prompt to the Claude Code runner without allowing the provider to choose its workspace.
     *
     * @param  (Closure(string, string): void)|null  $onOutput
     * @param  (Closure(): mixed)|null  $onHeartbeat
     * @param  array<string, mixed>  $executionSettings
     */
    private function executeProvider(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        array $executionSettings = [],
        ?string $executionPath = null,
    ): NormalizedExecutionResult {
        $execution = $executionPath === null
            ? $this->runner->run(
                $project,
                $agent,
                $prompt,
                $onOutput,
                $onHeartbeat,
                $executionSettings,
            )
            : $this->runner->runAtPath(
                $executionPath,
                $agent,
                $prompt,
                $onOutput,
                $onHeartbeat,
                $executionSettings,
            );

        if (
            $execution['failure_type'] !== null
            && $execution['failure_type'] !== 'process_failure'
        ) {
            return $this->failure(
                exitCode: $execution['exit_code'],
                message: $execution['error_output'],
                failureType: $execution['failure_type'],
            );
        }

        $stream = $this->parseStream($execution['output']);
        $result = $stream['result'];

        if ($execution['exit_code'] !== 0) {
            $failureType = $this->isAuthenticationFailure(
                $stream['api_error_category'],
            )
                ? 'authentication_unavailable'
                : 'process_failure';

            $message = $failureType === 'authentication_unavailable'
                ? 'Claude Code authentication failed. Run "claude auth login" outside AIOS using the same OS user, then retry.'
                : 'Claude Code process exited with code '
                    .$execution['exit_code']
                    .'.';

            return $this->failure(
                exitCode: $execution['exit_code'],
                message: $message,
                failureType: $failureType,
                result: $result,
                externalRunId: $stream['session_id'],
                apiErrorCategory: $stream['api_error_category'],
                apiErrorStatus: $stream['api_error_status'],
            );
        }

        // Claude Code can emit a non-stream diagnostic alongside a complete result event. The
        // final result is authoritative; malformed side output is ignored unless no result exists.
        if ($result === null) {
            return $this->failure(
                exitCode: self::NormalizationFailureExitCode,
                message: 'Claude Code returned malformed stream output.',
                failureType: 'malformed_output',
                externalRunId: $stream['session_id'],
                rawOutput: $execution['output'],
            );
        }

        $subtype = is_string($result['subtype'] ?? null)
            ? $result['subtype']
            : null;
        $sessionId = is_string($result['session_id'] ?? null)
            ? $result['session_id']
            : null;

        if ($subtype === null || $sessionId === null) {
            return $this->failure(
                exitCode: self::NormalizationFailureExitCode,
                message: 'Claude Code returned a result without required execution metadata.',
                failureType: 'malformed_output',
                result: $result,
                externalRunId: $stream['session_id'],
                rawOutput: $execution['output'],
            );
        }

        if (
            $subtype !== 'success'
            || ($result['is_error'] ?? false) === true
        ) {
            $failureType = $this->isAuthenticationFailure(
                $stream['api_error_category'],
            )
                ? 'authentication_unavailable'
                : 'provider_failure';

            $message = $failureType === 'authentication_unavailable'
                ? 'Claude Code authentication failed. Run "claude auth login" outside AIOS using the same OS user, then retry.'
                : 'Claude Code ended with result subtype ['
                    .$subtype
                    .']; the AIOS workflow was not advanced.';

            return $this->failure(
                exitCode: self::NormalizationFailureExitCode,
                message: $message,
                failureType: $failureType,
                result: $result,
                externalRunId: $sessionId,
                apiErrorCategory: $stream['api_error_category'],
                apiErrorStatus: $stream['api_error_status'],
            );
        }

        $output = $result['result'] ?? null;

        if (! is_string($output)) {
            return $this->failure(
                exitCode: self::NormalizationFailureExitCode,
                message: 'Claude Code returned a success result without final text output.',
                failureType: 'malformed_output',
                result: $result,
                externalRunId: $sessionId,
            );
        }

        return new NormalizedExecutionResult(
            exitCode: 0,
            output: $output,
            errorOutput: '',
            externalRunId: $sessionId,
            usage: is_array($result['usage'] ?? null)
                ? $result['usage']
                : null,
            providerMetadata: $this->providerMetadata(
                $result,
            ),
        );
    }

    /**
     * Parse Claude Code stream-json output into the normalized result metadata AIOS persists.
     *
     * @return array{
     *     result: ?array<string, mixed>,
     *     session_id: ?string,
     *     api_error_category: ?string,
     *     api_error_status: ?int
     * }
     */
    private function parseStream(string $output): array
    {
        $result = null;
        $sessionId = null;
        $apiErrorCategory = null;
        $apiErrorStatus = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $event = json_decode(
                    $line,
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException) {
                continue;
            }

            if (! is_array($event)) {
                continue;
            }

            if (is_string($event['session_id'] ?? null)) {
                $sessionId = $event['session_id'];
            }

            if (
                ($event['type'] ?? null) === 'system'
                && ($event['subtype'] ?? null) === 'api_retry'
                && is_string($event['error'] ?? null)
            ) {
                $apiErrorCategory = $event['error'];
                $apiErrorStatus = is_int($event['error_status'] ?? null)
                    ? $event['error_status']
                    : null;
            }

            if (($event['type'] ?? null) === 'result') {
                $result = $event;
            }
        }

        return [
            'result' => $result,
            'session_id' => $sessionId,
            'api_error_category' => $apiErrorCategory,
            'api_error_status' => $apiErrorStatus,
        ];
    }

    /**
     * Build one normalized failure result without leaking provider-authored sensitive diagnostics.
     *
     * Raw output is retained only when the provider stream cannot be parsed at all and therefore
     * there is no extracted structured error field available for safe diagnostics.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function failure(
        int $exitCode,
        string $message,
        string $failureType,
        ?array $result = null,
        ?string $externalRunId = null,
        ?string $apiErrorCategory = null,
        ?int $apiErrorStatus = null,
        ?string $rawOutput = null,
    ): NormalizedExecutionResult {
        $resolvedExternalRunId = $externalRunId;
        $usage = null;

        if ($result !== null) {
            if (
                $resolvedExternalRunId === null
                && is_string($result['session_id'] ?? null)
            ) {
                $resolvedExternalRunId = $result['session_id'];
            }

            if (is_array($result['usage'] ?? null)) {
                $usage = $result['usage'];
            }
        }

        return new NormalizedExecutionResult(
            exitCode: $exitCode === 0
                ? self::NormalizationFailureExitCode
                : $exitCode,
            output: $rawOutput ?? '',
            errorOutput: $message,
            externalRunId: $resolvedExternalRunId,
            usage: $usage,
            providerMetadata: [
                ...$this->providerMetadata($result),
                'failure_type' => $failureType,
                ...($apiErrorCategory === null
                    ? []
                    : [
                        'api_error_category' => $apiErrorCategory,
                    ]),
                ...($apiErrorStatus === null
                    ? []
                    : [
                        'api_error_status' => $apiErrorStatus,
                    ]),
            ],
        );
    }

    /**
     * Extract bounded provider metadata from a parsed Claude Code result event.
     *
     * @param  array<string, mixed>|null  $result
     * @return array<string, int|float|string>
     */
    private function providerMetadata(?array $result): array
    {
        $metadata = [
            'provider' => AgentHarnessIdentifier::ClaudeCode->value,
        ];

        if ($result === null) {
            return $metadata;
        }

        foreach ([
            'subtype' => 'result_subtype',
            'duration_ms' => 'duration_ms',
            'duration_api_ms' => 'duration_api_ms',
            'num_turns' => 'num_turns',
            'stop_reason' => 'stop_reason',
            'total_cost_usd' => 'total_cost_usd',
            'terminal_reason' => 'terminal_reason',
        ] as $source => $target) {
            $value = $result[$source] ?? null;

            if (
                is_string($value)
                || is_int($value)
                || is_float($value)
            ) {
                $metadata[$target] = $value;
            }
        }

        return $metadata;
    }

    /**
     * Classify provider API categories that mean Claude Code authentication is unavailable.
     */
    private function isAuthenticationFailure(?string $category): bool
    {
        return in_array(
            $category,
            [
                'authentication_failed',
                'oauth_org_not_allowed',
            ],
            true,
        );
    }
}
