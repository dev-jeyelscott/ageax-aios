<?php

namespace App\Http\Requests;

use App\OrchestrationRecommendationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrchestrationRecommendationStatusRequest extends FormRequest
{
    /**
     * Require an authenticated operator before accepting a lifecycle decision.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Allow only the two explicit terminal recommendation lifecycle decisions.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    OrchestrationRecommendationStatus::Dismissed->value,
                    OrchestrationRecommendationStatus::Superseded->value,
                ]),
            ],
        ];
    }
}
