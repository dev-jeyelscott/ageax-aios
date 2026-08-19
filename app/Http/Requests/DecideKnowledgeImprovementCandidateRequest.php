<?php

namespace App\Http\Requests;

use App\KnowledgeImprovementCandidateStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideKnowledgeImprovementCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in([
                    KnowledgeImprovementCandidateStatus::Approved->value,
                    KnowledgeImprovementCandidateStatus::Rejected->value,
                    KnowledgeImprovementCandidateStatus::Dismissed->value,
                ]),
            ],
        ];
    }
}
