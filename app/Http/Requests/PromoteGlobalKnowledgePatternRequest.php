<?php

namespace App\Http\Requests;

use App\Models\GlobalKnowledgePattern;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoteGlobalKnowledgePatternRequest extends FormRequest
{
    /**
     * Allow only authenticated operators through the existing protected route group.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the bounded reusable-pattern contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:160',
            ],
            'category' => [
                'required',
                'string',
                Rule::in(GlobalKnowledgePattern::allowedCategories()),
            ],
            'applicable_roles' => [
                'required',
                'array',
                'min:1',
                'max:3',
            ],
            'applicable_roles.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(GlobalKnowledgePattern::allowedRoles()),
            ],
            'validated_guidance' => [
                'required',
                'string',
                'max:4000',
            ],
        ];
    }
}
