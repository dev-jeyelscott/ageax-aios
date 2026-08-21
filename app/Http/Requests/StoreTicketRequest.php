<?php

namespace App\Http\Requests;

use App\Services\TicketAttachmentStorage;
use App\TicketRequesterCategory;
use App\TicketUrgency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    private const MAX_ATTACHMENTS = 5;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'requester_category' => [
                'required',
                Rule::enum(TicketRequesterCategory::class),
            ],
            'expected_behavior' => ['nullable', 'string', 'max:10000'],
            'actual_behavior' => ['nullable', 'string', 'max:10000'],
            'reproduction_steps' => ['nullable', 'string', 'max:20000'],
            'environment_version' => ['nullable', 'string', 'max:5000'],
            'requester_urgency' => [
                'nullable',
                Rule::enum(TicketUrgency::class),
            ],
            'attachments' => [
                'nullable',
                'array',
                'max:'.self::MAX_ATTACHMENTS,
            ],
            'attachments.*' => [
                'file',
                'max:'.TicketAttachmentStorage::MAX_SIZE_KILOBYTES,
            ],
        ];
    }
}
