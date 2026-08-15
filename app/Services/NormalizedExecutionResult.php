<?php

namespace App\Services;

final readonly class NormalizedExecutionResult
{
    /**
     * @param  array<string, mixed>|null  $usage
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public ?string $externalRunId = null,
        public ?array $usage = null,
        public array $providerMetadata = [],
    ) {}

    /**
     * @return array{
     *     exit_code: int,
     *     output: string,
     *     error_output: string,
     *     external_run_id: string|null,
     *     usage: array<string, mixed>|null,
     *     provider_metadata: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'output' => $this->output,
            'error_output' => $this->errorOutput,
            'external_run_id' => $this->externalRunId,
            'usage' => $this->usage,
            'provider_metadata' => $this->providerMetadata,
        ];
    }
}
