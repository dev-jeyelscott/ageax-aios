<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;

class AuditLogger
{
    /** @param array<string, mixed> $payload */
    public function record(string $eventType, array $payload = [], ?Project $project = null, ?Task $task = null): AuditEvent
    {
        return AuditEvent::create([
            'project_id' => $project === null ? $task?->project_id : $project->id,
            'task_id' => $task?->id,
            'event_type' => $eventType,
            'payload' => $this->sanitizePayload($payload),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $sanitized = $this->sanitizeValue($payload);

        return is_array($sanitized) ? $sanitized : [];
    }

    private function sanitizeValue(mixed $value, ?string $field = null): mixed
    {
        if ($this->isSensitiveField($field)) {
            return '[REDACTED]';
        }

        if (is_string($value)) {
            return $this->redactText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->sanitizeValue(
                $nestedValue,
                is_string($key) ? $key : null,
            );
        }

        return $value;
    }

    private function isSensitiveField(?string $field): bool
    {
        return $field !== null
            && preg_match(
                '/(?:token|secret|password|api[_-]?key|app[_-]?key|private[_-]?key|credential|credentials|authorization)$/i',
                $field,
            ) === 1;
    }

    private function redactText(string $text): string
    {
        $redacted = preg_replace(
            '/-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----.*?-----END (?:[A-Z ]+ )?PRIVATE KEY-----/s',
            '[REDACTED PRIVATE KEY]',
            $text,
        ) ?? $text;

        $redacted = preg_replace(
            '/(?i)(authorization\s*:\s*bearer\s+)[^\s"\']+/',
            '$1[REDACTED]',
            $redacted,
        ) ?? $redacted;

        $redacted = preg_replace(
            '/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16})\b/',
            '[REDACTED]',
            $redacted,
        ) ?? $redacted;

        return preg_replace(
            '/(?i)\b((?=[a-z0-9_]*(?:token|secret|password|api_key|app_key|private_key|credential))[a-z][a-z0-9_]*)\s*=\s*[^\r\n]*/',
            '$1=[REDACTED]',
            $redacted,
        ) ?? $redacted;
    }
}
