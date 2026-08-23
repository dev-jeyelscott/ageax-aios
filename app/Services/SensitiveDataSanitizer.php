<?php

namespace App\Services;

class SensitiveDataSanitizer
{
    /**
     * Sanitize a structured payload recursively before it becomes durable evidence.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizePayload(array $payload): array
    {
        $sanitized = $this->sanitizeValue($payload);

        return is_array($sanitized) ? $sanitized : [];
    }

    /**
     * Redact known credential, cookie, session, private-key, token, and environment patterns.
     */
    public function sanitizeText(string $text): string
    {
        $redacted = preg_replace(
            '/-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----.*?-----END (?:[A-Z ]+ )?PRIVATE KEY-----/s',
            '[REDACTED PRIVATE KEY]',
            $text,
        ) ?? $text;

        $redacted = preg_replace(
            '/(?i)\b(authorization|proxy-authorization)\s*:\s*[^\r\n]+/',
            '$1: [REDACTED]',
            $redacted,
        ) ?? $redacted;

        $redacted = preg_replace(
            '/(?i)\b(set-cookie|cookie)\s*:\s*[^\r\n]+/',
            '$1: [REDACTED]',
            $redacted,
        ) ?? $redacted;

        $redacted = preg_replace(
            '/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16}|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\b/',
            '[REDACTED]',
            $redacted,
        ) ?? $redacted;

        $redacted = preg_replace(
            '/(?i)\b((?=[a-z0-9_.-]*(?:token|secret|password|api[_-]?key|app[_-]?key|private[_-]?key|credential|authorization|cookie|session))[a-z][a-z0-9_.-]*)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/',
            '$1=[REDACTED]',
            $redacted,
        ) ?? $redacted;

        $redacted = preg_replace(
            '/\b([a-z][a-z0-9+.-]*:\/\/)[^\s\/@:]+:[^\s\/@]+@/i',
            '$1[REDACTED]@',
            $redacted,
        ) ?? $redacted;

        return preg_replace(
            '/\b([A-Z][A-Z0-9_]{1,})\s*=\s*(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s\r\n]+)/',
            '$1=[REDACTED]',
            $redacted,
        ) ?? $redacted;
    }

    /**
     * Sanitize one recursively nested value while respecting sensitive field names.
     */
    private function sanitizeValue(mixed $value, ?string $field = null): mixed
    {
        if ($this->isSensitiveField($field)) {
            return '[REDACTED]';
        }

        if (is_string($value)) {
            return $this->sanitizeText($value);
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

    /**
     * Identify structured fields whose values must never be persisted verbatim.
     */
    private function isSensitiveField(?string $field): bool
    {
        if ($field === null) {
            return false;
        }

        if (preg_match(
            '/(?:token|secret|password|api[_-]?key|app[_-]?key|private[_-]?key|credential|credentials|authorization|cookie|cookies|session|session[_-]?id|set[_-]?cookie)$/i',
            $field,
        ) === 1) {
            return true;
        }

        if (preg_match(
            '/(?:^|[_-])internal[_-]?notes?(?:$|[_-])/i',
            $field,
        ) === 1) {
            return true;
        }

        return preg_match(
            '/^(?:env|environment|env[_-]?vars?|environment[_-]?variables?|private[_-]?environment(?:[_-]?values?)?|host[_-]?environment|process[_-]?environment|execution[_-]?environment)$/i',
            $field,
        ) === 1;
    }
}
