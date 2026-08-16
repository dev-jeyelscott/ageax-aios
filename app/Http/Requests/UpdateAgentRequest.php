<?php

namespace App\Http\Requests;

use App\AgentHarness;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\AgentHarnessResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');
        /** @var Agent $agent */
        $agent = $this->route('agent');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('agents', 'name')->where('project_id', $project->id)->ignore($agent->id),
            ],
            'role' => ['required', Rule::in([AgentRole::ProjectManager->value, AgentRole::Coder->value, AgentRole::Reviewer->value])],
            'harness' => ['required', Rule::enum(AgentHarness::class)],
            'model' => ['nullable', 'string', 'max:255'],
            'reasoning_setting' => ['nullable', 'string', 'max:255'],
            'default_context' => ['nullable', 'string', 'max:10000'],
            'enabled' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            AgentHarnessCompatibility::validate(
                $validator,
                app(AgentHarnessResolver::class),
                $this->string('harness')->toString(),
                $this->filled('model') ? $this->string('model')->toString() : null,
                $this->filled('reasoning_setting') ? $this->string('reasoning_setting')->toString() : null,
            );

            $this->guardCoreRoleBinding($validator);
            $this->guardBoundRoleChange($validator);
        });
    }

    private function guardCoreRoleBinding(Validator $validator): void
    {
        if ($this->boolean('enabled', true)) {
            return;
        }

        /** @var Project $project */
        $project = $this->route('project');
        /** @var Agent $agent */
        $agent = $this->route('agent');

        if (ProjectStatus::from((string) $project->getRawOriginal('status')) !== ProjectStatus::Running) {
            return;
        }

        $isBound = AgentWorker::query()
            ->where('project_id', $project->id)
            ->where('agent_id', $agent->id)
            ->exists();

        if ($isBound) {
            $validator->errors()->add(
                'enabled',
                'This agent is bound to a running workflow role. Bind the role to another enabled agent before disabling it.',
            );
        }
    }

    private function guardBoundRoleChange(Validator $validator): void
    {
        /** @var Agent $agent */
        $agent = $this->route('agent');
        $newRole = $this->input('role');

        if ($newRole === $agent->getRawOriginal('role')) {
            return;
        }

        $isBound = AgentWorker::query()
            ->where('project_id', $agent->project_id)
            ->where('agent_id', $agent->id)
            ->exists();

        if ($isBound) {
            $validator->errors()->add(
                'role',
                'This agent is bound to a workflow worker. Unbind it before changing its role.',
            );
        }
    }
}
