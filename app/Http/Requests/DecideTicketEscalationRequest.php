<?php

namespace App\Http\Requests;

use App\TicketOperatorAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideTicketEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('direction')) {
            return;
        }

        $direction = $this->input('direction');

        if (is_string($direction)) {
            $this->merge([
                'direction' => trim($direction),
            ]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                Rule::enum(TicketOperatorAction::class),
            ],
            'direction' => [
                Rule::requiredIf(fn (): bool => in_array(
                    $this->input('action'),
                    [
                        TicketOperatorAction::RequestRequesterInformation->value,
                        TicketOperatorAction::ProvideDirection->value,
                    ],
                    true,
                )),
                'nullable',
                'string',
                'max:8000',
            ],
        ];
    }
}
