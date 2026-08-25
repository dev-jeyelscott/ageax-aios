<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

final class VoiceAudioTranscriber
{
    /**
     * Map the conservative server-detected MIME allowlist to trusted temporary extensions.
     *
     * @var array<string, string>
     */
    private const MIME_EXTENSIONS = [
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/vnd.wave' => 'wav',
    ];

    /**
     * Create the temporary voice ingestion boundary around the existing speech service.
     */
    public function __construct(
        private SpeechToText $speechToText,
    ) {}

    /**
     * Determine whether one server-detected MIME type is accepted for local transcription.
     */
    public static function supportsMimeType(?string $mimeType): bool
    {
        return is_string($mimeType)
            && array_key_exists($mimeType, self::MIME_EXTENSIONS);
    }

    /**
     * Transcribe one validated upload while guaranteeing temporary audio cleanup.
     */
    public function transcribe(UploadedFile $audio): SpeechToTextResult
    {
        $upload = $this->inspectUpload($audio);
        $temporary = $this->stageUpload(
            $upload['path'],
            $upload['extension'],
        );

        try {
            return $this->speechToText->transcribe(
                $temporary['path'],
            );
        } finally {
            $this->cleanupTemporaryAudio(
                $temporary['path'],
                $temporary['directory'],
            );
        }
    }

    /**
     * Independently validate the uploaded file before any temporary copy is created.
     *
     * @return array{path: string, extension: string}
     */
    private function inspectUpload(UploadedFile $audio): array
    {
        if (! $audio->isValid()) {
            throw new RuntimeException(
                'The uploaded audio sample is invalid.',
            );
        }

        $maxAudioBytes = (int) config(
            'aios.voice_stt_max_audio_bytes',
            0,
        );

        if ($maxAudioBytes < 1) {
            throw new RuntimeException(
                'The local speech transcription size limit is invalid.',
            );
        }

        $size = $audio->getSize();

        if (! is_int($size) || $size < 1) {
            throw new RuntimeException(
                'The uploaded audio sample could not be inspected.',
            );
        }

        if ($size > $maxAudioBytes) {
            throw new RuntimeException(
                'The uploaded audio sample exceeds the configured size limit.',
            );
        }

        try {
            $mimeType = $audio->getMimeType();
        } catch (Throwable) {
            $mimeType = null;
        }

        $extension = is_string($mimeType)
            ? (self::MIME_EXTENSIONS[$mimeType] ?? null)
            : null;

        if ($extension === null) {
            throw new RuntimeException(
                'The uploaded audio sample type is not supported.',
            );
        }

        $sourcePath = $audio->getRealPath();
        $resolvedSourcePath = $sourcePath === false
            ? false
            : realpath($sourcePath);

        if (
            $resolvedSourcePath === false
            || ! is_file($resolvedSourcePath)
            || ! is_readable($resolvedSourcePath)
        ) {
            throw new RuntimeException(
                'The uploaded audio sample is unavailable.',
            );
        }

        return [
            'path' => $resolvedSourcePath,
            'extension' => $extension,
        ];
    }

    /**
     * Copy one trusted upload into an isolated random private temporary location.
     *
     * @return array{path: string, directory: string}
     */
    private function stageUpload(
        string $sourcePath,
        string $extension,
    ): array {
        $temporaryParent = realpath(sys_get_temp_dir());

        if (
            $temporaryParent === false
            || ! is_dir($temporaryParent)
            || ! is_writable($temporaryParent)
        ) {
            throw new RuntimeException(
                'The temporary voice directory is unavailable.',
            );
        }

        $directory = null;
        $temporaryPath = null;

        try {
            $directory = $this->createPrivateTemporaryDirectory(
                $temporaryParent,
            );

            $this->assertTemporaryDirectoryIsIsolated(
                $directory,
            );

            $temporaryPath = $directory
                .DIRECTORY_SEPARATOR
                .bin2hex(random_bytes(16))
                .'.'
                .$extension;

            if (! copy($sourcePath, $temporaryPath)) {
                throw new RuntimeException(
                    'The uploaded audio sample could not be staged.',
                );
            }

            if (! @chmod($temporaryPath, 0600)) {
                throw new RuntimeException(
                    'The temporary audio permissions could not be secured.',
                );
            }

            $resolvedTemporaryPath = realpath($temporaryPath);

            if (
                $resolvedTemporaryPath === false
                || dirname($resolvedTemporaryPath) !== $directory
                || ! is_file($resolvedTemporaryPath)
                || ! is_readable($resolvedTemporaryPath)
            ) {
                throw new RuntimeException(
                    'The temporary audio file is unavailable.',
                );
            }

            $permissions = fileperms($resolvedTemporaryPath);

            if (
                $permissions === false
                || ($permissions & 0111) !== 0
            ) {
                throw new RuntimeException(
                    'The temporary audio file permissions are unsafe.',
                );
            }

            return [
                'path' => $resolvedTemporaryPath,
                'directory' => $directory,
            ];
        } catch (Throwable $exception) {
            $this->cleanupTemporaryAudio(
                $temporaryPath,
                $directory,
                false,
            );

            throw $exception;
        }
    }

    /**
     * Create one cryptographically unpredictable private directory below the OS temp root.
     */
    private function createPrivateTemporaryDirectory(
        string $temporaryParent,
    ): string {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = $temporaryParent
                .DIRECTORY_SEPARATOR
                .'ageax-aios-voice-'
                .bin2hex(random_bytes(16));

            if (! @mkdir($candidate, 0700)) {
                continue;
            }

            @chmod($candidate, 0700);

            $resolved = realpath($candidate);
            $permissions = $resolved === false
                ? false
                : fileperms($resolved);

            if (
                $resolved !== false
                && is_dir($resolved)
                && $permissions !== false
                && ($permissions & 0077) === 0
            ) {
                return $resolved;
            }

            @rmdir($candidate);
        }

        throw new RuntimeException(
            'A private temporary voice directory could not be created.',
        );
    }

    /**
     * Fail closed when temporary voice storage overlaps AIOS or its managed workspace.
     */
    private function assertTemporaryDirectoryIsIsolated(
        string $directory,
    ): void {
        $installationRoot = realpath(base_path());
        $workspaceRoot = $this->resolveComparablePath(
            (string) config('aios.workspace_root', ''),
        );

        if (
            $installationRoot === false
            || $workspaceRoot === null
        ) {
            throw new RuntimeException(
                'Temporary voice storage isolation could not be verified.',
            );
        }

        if (
            $this->pathsOverlap($directory, $installationRoot)
            || $this->pathsOverlap($directory, $workspaceRoot)
        ) {
            throw new RuntimeException(
                'Temporary voice storage overlaps a protected repository location.',
            );
        }
    }

    /**
     * Resolve an absolute existing or safely comparable configured path.
     */
    private function resolveComparablePath(
        string $path,
    ): ?string {
        if (
            $path === ''
            || ! str_starts_with($path, DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        $resolved = realpath($path);

        if ($resolved !== false) {
            return rtrim(
                $resolved,
                DIRECTORY_SEPARATOR,
            ) ?: DIRECTORY_SEPARATOR;
        }

        $parent = realpath(dirname($path));

        if ($parent === false) {
            return null;
        }

        return rtrim(
            $parent
                .DIRECTORY_SEPARATOR
                .basename($path),
            DIRECTORY_SEPARATOR,
        ) ?: DIRECTORY_SEPARATOR;
    }

    /**
     * Determine whether either canonical path contains the other.
     */
    private function pathsOverlap(
        string $left,
        string $right,
    ): bool {
        $normalizedLeft = rtrim(
            $left,
            DIRECTORY_SEPARATOR,
        ) ?: DIRECTORY_SEPARATOR;

        $normalizedRight = rtrim(
            $right,
            DIRECTORY_SEPARATOR,
        ) ?: DIRECTORY_SEPARATOR;

        if ($normalizedLeft === $normalizedRight) {
            return true;
        }

        $leftPrefix = rtrim(
            $normalizedLeft,
            DIRECTORY_SEPARATOR,
        ).DIRECTORY_SEPARATOR;

        $rightPrefix = rtrim(
            $normalizedRight,
            DIRECTORY_SEPARATOR,
        ).DIRECTORY_SEPARATOR;

        return str_starts_with(
            $leftPrefix,
            $rightPrefix,
        ) || str_starts_with(
            $rightPrefix,
            $leftPrefix,
        );
    }

    /**
     * Remove temporary audio content and its private directory on every execution path.
     */
    private function cleanupTemporaryAudio(
        ?string $temporaryPath,
        ?string $directory,
        bool $throwOnFailure = true,
    ): void {
        if (
            $temporaryPath !== null
            && file_exists($temporaryPath)
            && ! @unlink($temporaryPath)
        ) {
            @file_put_contents(
                $temporaryPath,
                '',
            );
            @chmod(
                $temporaryPath,
                0600,
            );
            @unlink($temporaryPath);
        }

        if (
            $directory !== null
            && is_dir($directory)
        ) {
            @rmdir($directory);
        }

        $fileStillExists = $temporaryPath !== null
            && file_exists($temporaryPath);

        $directoryStillExists = $directory !== null
            && is_dir($directory);

        if (
            $throwOnFailure
            && ($fileStillExists || $directoryStillExists)
        ) {
            throw new RuntimeException(
                'Temporary voice audio cleanup failed.',
            );
        }
    }
}
