<?php

namespace App\Http\Requests;

use App\TicketMessageType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'message_type' => [
                'required',
                Rule::enum(TicketMessageType::class)->only([
                    TicketMessageType::PublicReply,
                    TicketMessageType::InternalNote,
                ]),
            ],
            'body' => ['required', 'string', 'max:100000'],
        ];
    }
}
