<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Agent;

final readonly class AgentExecutionProfile
{
    public const int SchemaVersion = 1;

    /**
     * Resolve immutable, privacy-safe execution evidence from existing Agent, context, and
     * harness contracts. This is evidence only; it never grants an Agent additional authority.
     *
     * @param  array<string, mixed>|null  $retrievalManifest
     * @return array<string, mixed>
     */
    public function resolve(Agent $agent, AgentRole $role, AssembledAgentContext $context, string $prompt, ?AgentHarness $harness = null, ?array $retrievalManifest = null): array
    {
        $capabilities = null;

        if ($harness !== null) {
            $harness->capabilities()->assertSupports($agent, $harness->identifier());
            $capabilities = $harness->capabilities()->toArray();
        }

        return [
            'schema_version' => self::SchemaVersion,
            'context' => [
                'schema_version' => $context->contextSchemaVersion,
                'hash' => $context->hash,
                'task_context_keys' => $this->keys($context->taskContext),
                'skill_ids' => array_values(array_filter(array_map(fn (array $skill): ?int => is_int($skill['id'] ?? null) ? $skill['id'] : null, $context->skillsSnapshot))),
                'retrieval_manifest_hash' => $retrievalManifest === null ? null : hash('sha256', $this->encode($this->sanitize($retrievalManifest))),
            ],
            'model' => [
                'agent_id' => $agent->id,
                'role' => $role->value,
                'harness' => $agent->getRawOriginal('harness'),
                'model' => $agent->getRawOriginal('model'),
                'reasoning_setting' => $agent->getRawOriginal('reasoning_setting'),
                'configuration_version' => $agent->configuration_version,
            ],
            'prompt' => [
                'source' => 'aios_role_prompt',
                'context_schema_version' => $context->contextSchemaVersion,
                'hash' => hash('sha256', $prompt),
                'raw_prompt_persisted' => false,
            ],
            'tools' => [
                'capabilities_resolved' => $capabilities !== null,
                'harness_capabilities' => $capabilities,
                'execution_settings' => $this->sanitize($context->executionSettings),
                'workspace_boundary' => 'aios_owned_project_scoped',
                'write_boundary' => $role === AgentRole::Reviewer ? 'read_only' : 'aios_controlled',
                'command_boundary' => 'aios_authorized_deterministic_commands_only',
            ],
        ];
    }

    /** @param array<string, mixed> $value
     * @return list<string>
     */
    private function keys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function sanitize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (preg_match('/token|secret|password|api_?key|app_?key|private_?key|credential/i', $key) === 1) {
                $value[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->sanitize($item);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
