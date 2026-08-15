<?php

namespace App\Http\Requests;

use App\AgentRole;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSkillRequest extends FormRequest
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

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('skills', 'name')->where('project_id', $project->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['required', 'string', 'max:20000'],
            'constraints' => ['nullable', 'string', 'max:20000'],
            'applicable_roles' => ['array'],
            'applicable_roles.*' => [Rule::in(array_map(fn (AgentRole $role): string => $role->value, AgentRole::cases()))],
            'enabled' => ['boolean'],
        ];
    }
}
