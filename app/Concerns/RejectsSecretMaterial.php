<?php

namespace App\Concerns;

trait RejectsSecretMaterial
{
    private function containsSecretMaterial(string $value): bool
    {
        return preg_match('/-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----/s', $value) === 1
            || preg_match('/(?i)authorization\s*:\s*bearer\s+[^\s"\']+/', $value) === 1
            || preg_match('/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16})\b/', $value) === 1
            || preg_match('/(?im)^\s*((?=[a-z0-9_]*(?:token|secret|password|api_key|app_key|private_key|credential))[a-z][a-z0-9_]*)\s*=\s*\S.*$/', $value) === 1
            || preg_match('/(?im)(?:^|[,{])\s*["\']?(?:[a-z0-9]+[_-])*(?:token|secret|password|api[_-]?key|app[_-]?key|private[_-]?key|credential(?:s)?)["\']?\s*:\s*(?:"[^"\r\n]+"|\'[^\'\r\n]+\'|[^\s#,\]}]+)(?=\s*(?:[,}\r\n#]|$))/', $value) === 1
            || preg_match('/(?i)\b[a-z][a-z0-9+.-]*:\/\/[^\s\/:@]+:[^\s\/@]+@[^\s\/?#]+/', $value) === 1;
    }
}
