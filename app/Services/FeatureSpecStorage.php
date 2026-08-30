<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeatureSpecStorage
{
    public const int MaxSizeKilobytes = 1024;

    /** @return array{original_filename:string,storage_disk:string,storage_path:string,mime_type:string,size_bytes:int,content_hash:string,content:string} */
    public function store(Project $project, UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mimeType = (string) $file->getMimeType();
        if (! in_array($extension, ['md', 'markdown', 'txt'], true) || ! in_array($mimeType, ['text/plain', 'text/markdown'], true) || $file->getSize() === false || $file->getSize() > self::MaxSizeKilobytes * 1024) {
            throw ValidationException::withMessages(['feature' => 'Feature specifications must be bounded UTF-8 text or Markdown files.']);
        }
        $content = (string) file_get_contents($file->getRealPath());
        if (! mb_check_encoding($content, 'UTF-8') || trim($content) === '') {
            throw ValidationException::withMessages(['feature' => 'Feature specifications must be non-empty UTF-8 text.']);
        }
        $path = 'feature-specs/'.$project->id.'/'.Str::uuid().'.'.($extension === 'markdown' ? 'md' : $extension);
        Storage::disk('local')->put($path, $content);

        return ['original_filename' => Str::limit((string) $file->getClientOriginalName(), 180, ''), 'storage_disk' => 'local', 'storage_path' => $path, 'mime_type' => $mimeType, 'size_bytes' => mb_strlen($content, '8bit'), 'content_hash' => hash('sha256', $content), 'content' => $content];
    }
}
