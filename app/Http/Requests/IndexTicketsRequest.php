<?php

namespace App\Http\Requests;

use App\TicketCategory;
use App\TicketPriority;
use App\TicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'category' => ['nullable', Rule::enum(TicketCategory::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
        ];
    }
}
