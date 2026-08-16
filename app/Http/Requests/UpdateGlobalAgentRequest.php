<?php

namespace App\Http\Requests;

use App\AgentHarness;
use App\Models\Agent;
use App\Services\AgentHarnessResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGlobalAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Agent $agent */
        $agent = $this->route('agent');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('agents', 'name')->whereNull('project_id')->ignore($agent->id),
            ],
            'harness' => ['required', Rule::enum(AgentHarness::class)],
            'model' => ['nullable', 'string', 'max:255'],
            'reasoning_setting' => ['nullable', 'string', 'max:255'],
            'default_context' => ['nullable', 'string', 'max:10000'],
            'enabled' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => AgentHarnessCompatibility::validate(
            $validator,
            app(AgentHarnessResolver::class),
            $this->string('harness')->toString(),
            $this->filled('model') ? $this->string('model')->toString() : null,
            $this->filled('reasoning_setting') ? $this->string('reasoning_setting')->toString() : null,
        ));
    }
}
