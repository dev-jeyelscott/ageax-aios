<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use LogicException;

final class AgentHarnessResolver
{
    /** @var array<string, AgentHarness> */
    private array $harnesses = [];

    /** @param list<AgentHarness> $harnesses */
    public function __construct(array $harnesses = [])
    {
        foreach ($harnesses as $harness) {
            $identifier = $harness->identifier()->value;

            if (array_key_exists($identifier, $this->harnesses)) {
                throw new LogicException(
                    "Duplicate agent harness implementation for [{$identifier}].",
                );
            }

            $this->harnesses[$identifier] = $harness;
        }
    }

    public function resolve(Agent $agent): AgentHarness
    {
        $persistedIdentifier = $agent->getRawOriginal('harness');

        if (
            ! is_string($persistedIdentifier)
            || AgentHarnessIdentifier::tryFrom($persistedIdentifier) === null
        ) {
            throw new LogicException(
                'Unsupported agent harness identifier ['
                .$this->displayIdentifier($persistedIdentifier)
                .'].',
            );
        }

        $harness = $this->harnesses[$persistedIdentifier] ?? null;

        if ($harness === null) {
            throw new LogicException(
                "Agent harness [{$persistedIdentifier}] has no executable implementation.",
            );
        }

        $harness->capabilities()->assertSupports(
            $agent,
            $harness->identifier(),
        );

        return $harness;
    }

    /**
     * @return array<string, array{
     *     configuration_fields: list<string>,
     *     models: list<string>,
     *     reasoning_settings: list<string>,
     *     reasoning_settings_by_model: array<string, list<string>>,
     *     execution_options: list<string>
     * }>
     */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach ($this->harnesses as $identifier => $harness) {
            $capabilities[$identifier] = $harness
                ->capabilities()
                ->toArray();
        }

        return $capabilities;
    }

    private function displayIdentifier(mixed $identifier): string
    {
        return is_string($identifier) && $identifier !== ''
            ? $identifier
            : '(missing)';
    }
}
