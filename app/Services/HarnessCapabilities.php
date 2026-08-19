<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use LogicException;

final readonly class HarnessCapabilities
{
    /**
     * @param  list<string>  $models
     * @param  list<string>  $reasoningSettings
     * @param  list<string>  $executionOptions
     * @param  list<string>  $configurationFields
     * @param  array<string, list<string>>  $reasoningSettingsByModel
     * @param  array<string, int>  $contextWindowTokensByModel
     * @param  array<string, int>  $maxOutputTokensByModel
     */
    public function __construct(
        public array $models = [],
        public array $reasoningSettings = [],
        public array $executionOptions = [],
        public array $configurationFields = [],
        public array $reasoningSettingsByModel = [],
        public array $contextWindowTokensByModel = [],
        public array $maxOutputTokensByModel = [],
        public ?int $defaultContextWindowTokens = null,
        public ?int $defaultMaxOutputTokens = null,
        public string $capacityMetadataSource = 'unspecified',
        public int $capacityMetadataVersion = 1,
    ) {}

    public function assertSupports(
        Agent $agent,
        AgentHarnessIdentifier $harness,
    ): void {
        $model = $this->configurationValue($agent, 'model');
        $reasoningSetting = $this->configurationValue(
            $agent,
            'reasoning_setting',
        );

        if ($model !== null) {
            if (! in_array('model', $this->configurationFields, true)) {
                throw new LogicException(
                    "Harness [{$harness->value}] does not support agent model configuration.",
                );
            }

            if (! in_array($model, $this->models, true)) {
                throw new LogicException(
                    "Agent model [{$model}] is not supported by harness [{$harness->value}].",
                );
            }
        }

        if ($reasoningSetting === null) {
            return;
        }

        if (
            ! in_array(
                'reasoning_setting',
                $this->configurationFields,
                true,
            )
        ) {
            throw new LogicException(
                "Harness [{$harness->value}] does not support agent reasoning configuration.",
            );
        }

        if ($model === null) {
            throw new LogicException(
                "Agent reasoning setting [{$reasoningSetting}] requires an explicit model for harness [{$harness->value}].",
            );
        }

        $modelReasoningSettings =
            $this->reasoningSettingsByModel[$model] ?? [];

        if (
            ! in_array(
                $reasoningSetting,
                $this->reasoningSettings,
                true,
            )
            || ! in_array(
                $reasoningSetting,
                $modelReasoningSettings,
                true,
            )
        ) {
            throw new LogicException(
                "Agent reasoning setting [{$reasoningSetting}] is not supported for model [{$model}] by harness [{$harness->value}].",
            );
        }
    }

    /**
     * @return array{
     *     harness: string,
     *     model: string|null,
     *     resolved_capacity_tokens: int,
     *     max_output_tokens: int|null,
     *     capacity_source: string,
     *     capacity_source_version: int,
     *     fallback: bool
     * }
     */
    public function resolveContextCapacity(
        Agent $agent,
        AgentHarnessIdentifier $harness,
    ): array {
        $this->assertSupports($agent, $harness);

        $model = $this->configurationValue($agent, 'model');

        if ($model === null) {
            $capacity = $this->positiveCapacity(
                $this->defaultContextWindowTokens,
                "Harness [{$harness->value}] has no conservative context-capacity fallback for a null/default model configuration.",
            );

            return [
                'harness' => $harness->value,
                'model' => null,
                'resolved_capacity_tokens' => $capacity,
                'max_output_tokens' => $this->positiveNullableCapacity(
                    $this->defaultMaxOutputTokens,
                ),
                'capacity_source' => $this->capacityMetadataSource.':default_fallback',
                'capacity_source_version' => $this->capacityMetadataVersion,
                'fallback' => true,
            ];
        }

        $capacity = $this->positiveCapacity(
            $this->contextWindowTokensByModel[$model] ?? null,
            "Harness [{$harness->value}] model [{$model}] has no deterministic context-capacity metadata.",
        );

        return [
            'harness' => $harness->value,
            'model' => $model,
            'resolved_capacity_tokens' => $capacity,
            'max_output_tokens' => $this->positiveNullableCapacity(
                $this->maxOutputTokensByModel[$model] ?? null,
            ),
            'capacity_source' => $this->capacityMetadataSource.':model',
            'capacity_source_version' => $this->capacityMetadataVersion,
            'fallback' => false,
        ];
    }

    /**
     * Conservative provider-default capacity evidence for compatibility paths that explicitly
     * elect to use the harness default rather than infer capacity from another model.
     *
     * @return array{
     *     resolved_capacity_tokens: int,
     *     max_output_tokens: int|null,
     *     capacity_source: string,
     *     capacity_source_version: int,
     *     fallback: true
     * }
     */
    public function resolveLegacyDefaultCapacity(
        AgentHarnessIdentifier $harness,
    ): array {
        return [
            'resolved_capacity_tokens' => $this->positiveCapacity(
                $this->defaultContextWindowTokens,
                "Harness [{$harness->value}] has no conservative legacy context-capacity fallback.",
            ),
            'max_output_tokens' => $this->positiveNullableCapacity(
                $this->defaultMaxOutputTokens,
            ),
            'capacity_source' => $this->capacityMetadataSource.':legacy_default_fallback',
            'capacity_source_version' => $this->capacityMetadataVersion,
            'fallback' => true,
        ];
    }

    /**
     * @return array{
     *     configuration_fields: list<string>,
     *     models: list<string>,
     *     reasoning_settings: list<string>,
     *     reasoning_settings_by_model: array<string, list<string>>,
     *     execution_options: list<string>,
     *     context_capacity: array{
     *         context_window_tokens_by_model: array<string, int>,
     *         max_output_tokens_by_model: array<string, int>,
     *         default_context_window_tokens: int|null,
     *         default_max_output_tokens: int|null,
     *         metadata_source: string,
     *         metadata_version: int
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'configuration_fields' => $this->configurationFields,
            'models' => $this->models,
            'reasoning_settings' => $this->reasoningSettings,
            'reasoning_settings_by_model' => $this->reasoningSettingsByModel,
            'execution_options' => $this->executionOptions,
            'context_capacity' => [
                'context_window_tokens_by_model' => $this->contextWindowTokensByModel,
                'max_output_tokens_by_model' => $this->maxOutputTokensByModel,
                'default_context_window_tokens' => $this->defaultContextWindowTokens,
                'default_max_output_tokens' => $this->defaultMaxOutputTokens,
                'metadata_source' => $this->capacityMetadataSource,
                'metadata_version' => $this->capacityMetadataVersion,
            ],
        ];
    }

    private function configurationValue(
        Agent $agent,
        string $attribute,
    ): ?string {
        $value = $agent->getRawOriginal($attribute);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new LogicException(
                "Agent configuration field [{$attribute}] must be null or a non-empty string.",
            );
        }

        return $value;
    }

    private function positiveCapacity(?int $value, string $message): int
    {
        if ($value === null || $value <= 0) {
            throw new LogicException($message);
        }

        return $value;
    }

    private function positiveNullableCapacity(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
    }
}
