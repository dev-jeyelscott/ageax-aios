<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class RouteVoiceIntentRequest extends FormRequest
{
    /**
     * Reuse the existing authenticated application boundary for voice intent requests.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate one explicitly confirmed and bounded transcript before routing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'transcript' => ['required', 'string', 'max:4000'],
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
