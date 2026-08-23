<?php

namespace App\Services;

class RuntimeCommandContext
{
    /** @var list<string> */
    private array $commands = [];

    /**
     * Push the Laravel command name that is currently executing without retaining its arguments.
     */
    public function push(string $command): void
    {
        $command = trim($command);

        if ($command !== '') {
            $this->commands[] = $command;
        }
    }

    /**
     * Remove the most recently started command from the in-process execution context.
     */
    public function pop(): void
    {
        array_pop($this->commands);
    }

    /**
     * Return the active Laravel command name, including support for nested Artisan calls.
     */
    public function current(): ?string
    {
        $key = array_key_last($this->commands);

        return $key === null ? null : $this->commands[$key];
    }
}
