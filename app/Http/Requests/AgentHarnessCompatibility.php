<?php

namespace App\Http\Requests;

use App\Services\AgentHarnessResolver;
use Illuminate\Validation\Validator;

final class AgentHarnessCompatibility
{
    public static function validate(
        Validator $validator,
        AgentHarnessResolver $harnesses,
        string $harness,
        ?string $model,
        ?string $reasoningSetting,
    ): void {
        $capabilities = $harnesses->capabilities()[$harness] ?? null;

        if ($capabilities === null) {
            $validator->errors()->add('harness', 'The selected harness is not supported.');

            return;
        }

        if ($model !== null && ! in_array($model, $capabilities['models'], true)) {
            $validator->errors()->add('model', 'The selected model is not supported by this harness.');
        }

        if ($reasoningSetting === null) {
            return;
        }

        if ($model === null) {
            $validator->errors()->add('reasoning_setting', 'A model must be selected before a reasoning setting.');

            return;
        }

        $modelReasoningSettings = $capabilities['reasoning_settings_by_model'][$model] ?? [];

        if (! in_array($reasoningSetting, $modelReasoningSettings, true)) {
            $validator->errors()->add('reasoning_setting', 'The selected reasoning setting is not supported for this model.');
        }
    }
}
