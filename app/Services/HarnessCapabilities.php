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
     */
    public function __construct(
        public array $models = [],
        public array $reasoningSettings = [],
        public array $executionOptions = [],
        public array $configurationFields = [],
        public array $reasoningSettingsByModel = [],
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
     *     configuration_fields: list<string>,
     *     models: list<string>,
     *     reasoning_settings: list<string>,
     *     reasoning_settings_by_model: array<string, list<string>>,
     *     execution_options: list<string>
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
}
