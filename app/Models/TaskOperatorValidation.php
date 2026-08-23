<?php

namespace App\Models;

use Database\Factories\TaskOperatorValidationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['task_id', 'user_id', 'build_sha', 'build_completed_at', 'results', 'notes'])]
class TaskOperatorValidation extends Model
{
    /** @use HasFactory<TaskOperatorValidationFactory> */
    use HasFactory;

    /** @var array<string, string> */
    public const array Targets = [
        'safari_ipados' => 'Safari on iPadOS',
        'chrome_android_tablet' => 'Chrome on Android tablet',
        'chrome_desktop' => 'Chrome desktop',
        'edge_desktop' => 'Edge desktop',
        'laptop_webcam' => 'Laptop webcam',
        'external_usb_webcam' => 'Supported external USB webcam',
    ];

    /** @var array<string, string> */
    public const array Checks = [
        'permission' => 'Camera permission',
        'enumeration' => 'Camera enumeration',
        'switching' => 'Camera switching',
        'capture' => 'Capture',
        'upload' => 'Upload',
        'fullscreen' => 'Full-screen kiosk UX',
    ];

    protected function casts(): array
    {
        return ['build_completed_at' => 'immutable_datetime', 'results' => 'array'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function isApplicableTo(Task $task): bool
    {
        $contract = collect([
            $task->objective,
            ...($task->acceptance_criteria ?? []),
            ...($task->constraints ?? []),
        ])
            ->filter(fn (mixed $value): bool => is_string($value))
            ->join("\n");
        $normalized = Str::lower($contract);

        return Str::contains($normalized, ['camera', 'browser', 'device'])
            && Str::contains($normalized, ['manual', 'hardware', 'physical']);
    }
}
