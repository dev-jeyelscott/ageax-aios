<?php

namespace App\Http\Requests;

use App\Models\TaskOperatorValidation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTaskOperatorValidationRequest extends FormRequest
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
            'build_sha' => ['required', 'string', 'regex:/^[a-fA-F0-9]{7,64}$/'],
            'build_completed_at' => ['required', 'date', 'before_or_equal:now'],
            'results' => ['required', 'array', 'size:6'],
            'results.*.target' => ['required', 'string', Rule::in(array_keys(TaskOperatorValidation::Targets)), 'distinct'],
            'results.*.browser_version' => ['required', 'string', 'max:100'],
            'results.*.operating_system_version' => ['required', 'string', 'max:100'],
            'results.*.camera_label' => ['nullable', 'string', 'max:255'],
            'results.*.permission' => ['required', Rule::in(['pass', 'fail'])],
            'results.*.enumeration' => ['required', Rule::in(['pass', 'fail'])],
            'results.*.switching' => ['required', Rule::in(['pass', 'fail'])],
            'results.*.capture' => ['required', Rule::in(['pass', 'fail'])],
            'results.*.upload' => ['required', Rule::in(['pass', 'fail'])],
            'results.*.fullscreen' => ['required', Rule::in(['pass', 'fail'])],
            'results.*.follow_up_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'attested' => ['required', 'accepted'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->input('results', []) as $index => $result) {
                if (! is_array($result)) {
                    continue;
                }

                $hasFailure = collect(TaskOperatorValidation::Checks)
                    ->keys()
                    ->contains(fn (string $check): bool => ($result[$check] ?? null) === 'fail');

                if ($hasFailure && blank($result['follow_up_reference'] ?? null)) {
                    $validator->errors()->add("results.{$index}.follow_up_reference", 'A follow-up Task or Ticket reference is required for a failed target.');
                }
            }
        }];
    }
}
