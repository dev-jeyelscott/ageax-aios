<?php

namespace App\Http\Requests;

use App\Services\TicketAttachmentStorage;
use App\TicketRequesterCategory;
use App\TicketUrgency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    private const MAX_ATTACHMENTS = 5;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'txt',
        'md',
        'log',
        'csv',
        'json',
        'pdf',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
    ];

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/csv',
        'application/json',
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    /** @var list<string> */
    private const FORBIDDEN_FILENAME_EXTENSIONS = [
        'php',
        'phtml',
        'phar',
        'phps',
        'cgi',
        'pl',
        'py',
        'rb',
        'sh',
        'bash',
        'zsh',
        'fish',
        'ps1',
        'cmd',
        'bat',
        'com',
        'exe',
        'msi',
        'dll',
        'so',
        'dylib',
        'jar',
        'js',
        'mjs',
        'cjs',
        'ts',
        'tsx',
        'jsx',
        'vue',
        'html',
        'htm',
        'svg',
    ];

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
                'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES),
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
                $this->safeFilenameRule(),
            ],
        ];
    }

    private function safeFilenameRule(): Closure
    {
        return function (
            string $attribute,
            mixed $value,
            Closure $fail,
        ): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $originalName = $value->getClientOriginalName();

            if (
                $originalName === ''
                || $originalName !== trim($originalName)
                || Str::length($originalName)
                    > TicketAttachmentStorage::MAX_NAME_CHARACTERS
                || str_ends_with($originalName, '.')
                || preg_match(
                    '/[\/\\\\\x00-\x1F\x7F]/u',
                    $originalName,
                ) !== 0
            ) {
                $fail('The attachment filename is unsafe.');

                return;
            }

            $nameSegments = explode(
                '.',
                strtolower($originalName),
            );

            array_pop($nameSegments);

            foreach ($nameSegments as $segment) {
                if (
                    in_array(
                        trim($segment),
                        self::FORBIDDEN_FILENAME_EXTENSIONS,
                        true,
                    )
                ) {
                    $fail(
                        'Executable or script-like double-extension filenames are not allowed.',
                    );

                    return;
                }
            }
        };
    }
}
