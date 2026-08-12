<?php

namespace App\Http\Requests;

use App\AgentRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskOperatorMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipient_role' => ['required', Rule::enum(AgentRole::class)->only([AgentRole::Coder, AgentRole::Reviewer])],
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}
