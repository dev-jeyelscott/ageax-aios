<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TicketAttachmentStorage
{
    public const DISK = 'local';

    public const MAX_SIZE_KILOBYTES = 5120;

    public const MAX_NAME_CHARACTERS = 180;

    public const MAX_CONTEXT_TEXT_CHARACTERS = 16000;

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
    private const TEXT_EXTENSIONS = [
        'txt',
        'md',
        'log',
        'csv',
        'json',
    ];

    /** @var list<string> */
    private const TEXT_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/csv',
        'application/json',
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

    /**
     * @return array{
     *     original_name: string,
     *     storage_disk: string,
     *     storage_path: string,
     *     mime_type: string,
     *     extension: string,
     *     size_bytes: int,
     *     content_hash: string
     * }
     */
    public function store(Ticket $ticket, UploadedFile $file): array
    {
        $metadata = $this->validateAndInspect($file);
        $directory = 'ticket-attachments/'.$ticket->id;
        $disk = Storage::disk(self::DISK);

        $disk->makeDirectory($directory);

        $this->assertStorageLocationSafe(
            $disk->path($directory),
        );

        $filename = Str::uuid()->toString().'.'.$metadata['extension'];

        $storedPath = $disk->putFileAs(
            $directory,
            $file,
            $filename,
        );

        if (! is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('Ticket attachment storage failed.');
        }

        try {
            $this->assertStoredPathSafe($ticket, $storedPath);
        } catch (Throwable $exception) {
            $disk->delete($storedPath);

            throw $exception;
        }

        return [
            ...$metadata,
            'storage_disk' => self::DISK,
            'storage_path' => $storedPath,
        ];
    }

    public function deleteStored(
        Ticket $ticket,
        string $storagePath,
    ): void {
        $this->assertRelativeTicketPath($ticket, $storagePath);

        Storage::disk(self::DISK)->delete($storagePath);
    }

    public function supportsContextText(
        TicketAttachment $attachment,
    ): bool {
        return $attachment->storage_disk === self::DISK
            && in_array(
                strtolower($attachment->extension),
                self::TEXT_EXTENSIONS,
                true,
            )
            && in_array(
                strtolower($attachment->mime_type),
                self::TEXT_MIME_TYPES,
                true,
            );
    }

    /**
     * @return array{
     *     attachment_id: int,
     *     original_name: string,
     *     mime_type: string,
     *     extension: string,
     *     size_bytes: int,
     *     content_is_untrusted: true,
     *     text_content: ?string
     * }
     */
    public function triageEvidence(
        TicketAttachment $attachment,
    ): array {
        return [
            'attachment_id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'extension' => $attachment->extension,
            'size_bytes' => $attachment->size_bytes,
            'content_is_untrusted' => true,
            'text_content' => $this->boundedTextContent($attachment),
        ];
    }

    public function boundedTextContent(
        TicketAttachment $attachment,
    ): ?string {
        if (! $this->supportsContextText($attachment)) {
            return null;
        }

        $attachment->loadMissing('ticket');

        $this->assertStoredPathSafe(
            $attachment->ticket,
            $attachment->storage_path,
        );

        $content = Storage::disk(self::DISK)
            ->get($attachment->storage_path);

        if (
            hash('sha256', $content)
            !== $attachment->content_hash
        ) {
            throw new RuntimeException(
                'Ticket attachment integrity check failed.',
            );
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        return Str::limit(
            $content,
            self::MAX_CONTEXT_TEXT_CHARACTERS,
            '',
        );
    }

    /**
     * @return array{
     *     original_name: string,
     *     mime_type: string,
     *     extension: string,
     *     size_bytes: int,
     *     content_hash: string
     * }
     */
    private function validateAndInspect(
        UploadedFile $file,
    ): array {
        Validator::make([
            'attachment' => $file,
        ], [
            'attachment' => [
                'required',
                'file',
                'max:'.self::MAX_SIZE_KILOBYTES,
                'mimetypes:'.implode(
                    ',',
                    self::ALLOWED_MIME_TYPES,
                ),
                'extensions:'.implode(
                    ',',
                    self::ALLOWED_EXTENSIONS,
                ),
            ],
        ])->validate();

        $originalName = $file->getClientOriginalName();

        if (
            $originalName === ''
            || $originalName !== trim($originalName)
            || Str::length($originalName)
                > self::MAX_NAME_CHARACTERS
            || str_ends_with($originalName, '.')
            || preg_match(
                '/[\/\\\\\x00-\x1F\x7F]/u',
                $originalName,
            ) !== 0
        ) {
            $this->invalid(
                'The attachment filename is unsafe.',
            );
        }

        $extension = strtolower(
            $file->getClientOriginalExtension(),
        );

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
                $this->invalid(
                    'Executable or script-like double-extension filenames are not allowed.',
                );
            }
        }

        $size = $file->getSize();
        $mimeType = $file->getMimeType();
        $realPath = $file->getRealPath();

        if (! is_int($size) || $size <= 0) {
            $this->invalid(
                'The attachment must not be empty.',
            );
        }

        if (! is_string($mimeType) || $mimeType === '') {
            $this->invalid(
                'The attachment MIME type could not be determined safely.',
            );
        }

        if ($realPath === false) {
            throw new RuntimeException(
                'The uploaded attachment could not be read.',
            );
        }

        $contentHash = hash_file(
            'sha256',
            $realPath,
        );

        if ($contentHash === false) {
            throw new RuntimeException(
                'The uploaded attachment could not be hashed.',
            );
        }

        return [
            'original_name' => $originalName,
            'mime_type' => strtolower($mimeType),
            'extension' => $extension,
            'size_bytes' => $size,
            'content_hash' => $contentHash,
        ];
    }

    private function assertStoredPathSafe(
        Ticket $ticket,
        string $storagePath,
    ): void {
        $this->assertRelativeTicketPath(
            $ticket,
            $storagePath,
        );

        $absolutePath = realpath(
            Storage::disk(self::DISK)
                ->path($storagePath),
        );

        if ($absolutePath === false) {
            throw new RuntimeException(
                'Stored Ticket attachment path could not be resolved.',
            );
        }

        $this->assertStorageLocationSafe(
            $absolutePath,
        );
    }

    private function assertRelativeTicketPath(
        Ticket $ticket,
        string $storagePath,
    ): void {
        $expectedPrefix = 'ticket-attachments/'
            .$ticket->id
            .'/';

        if (
            ! str_starts_with(
                $storagePath,
                $expectedPrefix,
            )
            || str_starts_with($storagePath, '/')
            || str_contains($storagePath, '..')
            || str_contains($storagePath, '\\')
        ) {
            throw new RuntimeException(
                'Ticket attachment path escaped its configured storage namespace.',
            );
        }
    }

    private function assertStorageLocationSafe(
        string $path,
    ): void {
        $resolvedPath = realpath($path);

        $resolvedDiskRoot = realpath(
            Storage::disk(self::DISK)->path(''),
        );

        if (
            $resolvedPath === false
            || $resolvedDiskRoot === false
            || ! $this->pathIsWithin(
                $resolvedPath,
                $resolvedDiskRoot,
            )
        ) {
            throw new RuntimeException(
                'Ticket attachment path escaped the configured local storage root.',
            );
        }

        foreach (
            Project::query()->pluck('path') as $projectPath
        ) {
            if (
                ! is_string($projectPath)
                || $projectPath === ''
            ) {
                continue;
            }

            $resolvedProjectPath = realpath(
                $projectPath,
            );

            if (
                $resolvedProjectPath !== false
                && $this->pathIsWithin(
                    $resolvedPath,
                    $resolvedProjectPath,
                )
            ) {
                throw new RuntimeException(
                    'Ticket attachments cannot be stored inside a managed project repository.',
                );
            }
        }
    }

    private function pathIsWithin(
        string $path,
        string $root,
    ): bool {
        $normalizedPath = rtrim(
            str_replace('\\', '/', $path),
            '/',
        );

        $normalizedRoot = rtrim(
            str_replace('\\', '/', $root),
            '/',
        );

        if ($normalizedRoot === '') {
            $normalizedRoot = '/';
        }

        return $normalizedPath === $normalizedRoot
            || str_starts_with(
                $normalizedPath,
                $normalizedRoot === '/'
                    ? '/'
                    : $normalizedRoot.'/',
            );
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'attachment' => [$message],
        ]);
    }
}
