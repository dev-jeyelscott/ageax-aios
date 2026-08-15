<?php

namespace App\Services;

class IsolatedProcessEnvironment
{
    /** @var list<string> */
    private const array PASSTHROUGH_KEYS = ['HOME', 'PATH', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'CODEX_HOME'];

    /** @param list<string> $command
     * @return list<string>
     */
    public function command(array $command): array
    {
        return ['/usr/bin/env', '-i', ...$this->assignments(), ...$command];
    }

    /** @return list<string> */
    private function assignments(): array
    {
        $assignments = [];

        foreach (self::PASSTHROUGH_KEYS as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $assignments[] = "{$key}={$value}";
            }
        }

        return $assignments;
    }
}
