<?php

namespace App\Services;

final readonly class HarnessCapabilities
{
    /**
     * @param  list<string>  $models
     * @param  list<string>  $reasoningSettings
     * @param  list<string>  $executionOptions
     */
    public function __construct(
        public array $models = [],
        public array $reasoningSettings = [],
        public array $executionOptions = [],
    ) {}
}
