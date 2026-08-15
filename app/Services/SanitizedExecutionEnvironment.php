<?php

namespace App\Services;

class SanitizedExecutionEnvironment
{
    /** @var list<string> */
    private const array AllowedKeys = ['HOME', 'PATH', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'CODEX_HOME'];

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    public function wrap(array $command): array
    {
        return ['/usr/bin/env', '-i', ...$this->arguments(), ...$command];
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return array_values(collect(self::AllowedKeys)
            ->mapWithKeys(function (string $key): array {
                $value = getenv($key);

                return $value === false || $value === '' ? [] : [$key => $value];
            })
            ->map(fn (string $value, string $key): string => "{$key}={$value}")
            ->values()
            ->all());
    }
}
