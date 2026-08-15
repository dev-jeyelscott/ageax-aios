<?php

namespace App\Http\Requests;

use App\Models\Agent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BindAgentWorkerRequest extends FormRequest
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
            'agent_worker_id' => [
                'required',
                'integer',
                Rule::exists('agent_workers', 'id')->where('project_id', $agent->project_id),
            ],
        ];
    }
}
