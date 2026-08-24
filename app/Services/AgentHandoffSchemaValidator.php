<?php

namespace App\Services;

use App\AgentHandoffType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AgentHandoffSchemaValidator
{
    public const int SchemaVersion = 1;

    private const int MaxPayloadBytes = 32768;

    /**
     * Fields that would allow a handoff to carry executable or durable AIOS authority.
     *
     * @var list<string>
     */
    private const array ForbiddenAuthorityKeys = [
        'apply',
        'apply_now',
        'execute',
        'command',
        'commands',
        'shell',
        'script',
        'code',
        'php',
        'permission',
        'permissions',
        'worker',
        'worker_id',
        'agent_worker_id',
        'agent',
        'agent_id',
        'agent_run_id',
        'from_agent_run_id',
        'project_id',
        'task_id',
        'ticket_id',
        'recovery_incident_id',
        'task_status',
        'status',
        'transition',
        'git',
        'git_command',
        'workflow_definition',
        'route',
        'routing',
        'model',
        'harness',
        'reasoning_setting',
        'credential',
        'credentials',
        'token',
        'tokens',
        'secret',
        'secrets',
        'password',
        'api_key',
        'app_key',
        'private_key',
        'authorization',
        'cookie',
        'cookies',
        'session',
        'env',
        'environment',
        'env_vars',
        'environment_variables',
    ];

    /**
     * Inject the existing recursive sensitive-data sanitizer.
     */
    public function __construct(
        private SensitiveDataSanitizer $sanitizer,
    ) {}

    /**
     * Validate, sanitize, and return one bounded schema-versioned handoff payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(
        AgentHandoffType $type,
        int $schemaVersion,
        array $payload,
    ): array {
        if ($schemaVersion !== self::SchemaVersion) {
            throw ValidationException::withMessages([
                'schema_version' => "Unsupported Agent handoff schema version [{$schemaVersion}].",
            ]);
        }

        $this->assertPayloadSize($payload);
        $this->assertNoForbiddenKeys($payload);

        $validated = Validator::make(
            ['payload' => $payload],
            $this->rulesFor($type),
        )->validate();

        $validatedPayload = $validated['payload'] ?? null;

        if (! is_array($validatedPayload)) {
            throw ValidationException::withMessages([
                'payload' => 'A structured Agent handoff payload is required.',
            ]);
        }

        $sanitized = $this->sanitizer->sanitizePayload(
            $validatedPayload,
        );

        $this->assertPayloadSize($sanitized);

        return $sanitized;
    }

    /**
     * Return the explicit schema-version-one contract for one handoff type.
     *
     * @return array<string, mixed>
     */
    private function rulesFor(AgentHandoffType $type): array
    {
        return match ($type) {
            AgentHandoffType::ImplementationHandoff => [
                'payload' => [
                    'required',
                    'array:summary,changed_files,tests_added_or_updated,verification_attempts,blockers',
                ],
                'payload.summary' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.changed_files' => [
                    'required',
                    'array',
                    'max:100',
                ],
                'payload.changed_files.*' => [
                    'string',
                    'max:512',
                ],
                'payload.tests_added_or_updated' => [
                    'sometimes',
                    'array',
                    'max:50',
                ],
                'payload.tests_added_or_updated.*' => [
                    'string',
                    'max:512',
                ],
                'payload.verification_attempts' => [
                    'sometimes',
                    'array',
                    'max:50',
                ],
                'payload.verification_attempts.*' => [
                    'string',
                    'max:1000',
                ],
                'payload.blockers' => [
                    'sometimes',
                    'array',
                    'max:20',
                ],
                'payload.blockers.*' => [
                    'string',
                    'max:1000',
                ],
            ],

            AgentHandoffType::ReviewRequest => [
                'payload' => [
                    'required',
                    'array:summary,focus_areas',
                ],
                'payload.summary' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.focus_areas' => [
                    'sometimes',
                    'array',
                    'max:20',
                ],
                'payload.focus_areas.*' => [
                    'string',
                    'max:500',
                ],
            ],

            AgentHandoffType::ReviewFinding => [
                'payload' => [
                    'required',
                    'array:summary,findings',
                ],
                'payload.summary' => [
                    'nullable',
                    'string',
                    'max:4000',
                ],
                'payload.findings' => [
                    'required',
                    'array',
                    'min:1',
                    'max:20',
                ],
                'payload.findings.*' => [
                    'required',
                    'array:severity,location,current_implementation,expected_implementation,why_incorrect,required_fix,verification_requirement,implementation_fix_context',
                ],
                'payload.findings.*.severity' => [
                    'required',
                    'string',
                    'max:32',
                ],
                'payload.findings.*.location' => [
                    'nullable',
                    'string',
                    'max:1000',
                ],
                'payload.findings.*.current_implementation' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.findings.*.expected_implementation' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.findings.*.why_incorrect' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.findings.*.required_fix' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.findings.*.verification_requirement' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.findings.*.implementation_fix_context' => [
                    'required',
                    'string',
                    'max:4000',
                ],
            ],

            AgentHandoffType::ContextRequest => [
                'payload' => [
                    'required',
                    'array:request,requested_evidence,reason',
                ],
                'payload.request' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.requested_evidence' => [
                    'required',
                    'array',
                    'min:1',
                    'max:20',
                ],
                'payload.requested_evidence.*' => [
                    'string',
                    'max:500',
                ],
                'payload.reason' => [
                    'required',
                    'string',
                    'max:4000',
                ],
            ],

            AgentHandoffType::RecoveryAdvice => [
                'payload' => [
                    'required',
                    'array:summary,root_cause_category,recommended_focus,changed_files,escalation_reason',
                ],
                'payload.summary' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.root_cause_category' => [
                    'nullable',
                    'string',
                    'max:120',
                ],
                'payload.recommended_focus' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.changed_files' => [
                    'sometimes',
                    'array',
                    'max:100',
                ],
                'payload.changed_files.*' => [
                    'string',
                    'max:512',
                ],
                'payload.escalation_reason' => [
                    'nullable',
                    'string',
                    'max:4000',
                ],
            ],

            AgentHandoffType::KnowledgeReference => [
                'payload' => [
                    'required',
                    'array:evidence_summary,proposed_change,confidence,references',
                ],
                'payload.evidence_summary' => [
                    'required',
                    'string',
                    'max:4000',
                ],
                'payload.proposed_change' => [
                    'nullable',
                    'string',
                    'max:4000',
                ],
                'payload.confidence' => [
                    'required',
                    'string',
                    Rule::in([
                        'low',
                        'medium',
                        'high',
                    ]),
                ],
                'payload.references' => [
                    'required',
                    'array',
                    'min:1',
                    'max:20',
                ],
                'payload.references.*' => [
                    'string',
                    'max:1000',
                ],
            ],
        };
    }

    /**
     * Reject recursively nested keys that attempt to carry AIOS authority or secrets.
     *
     * @param  array<mixed>  $payload
     */
    private function assertNoForbiddenKeys(
        array $payload,
        string $path = 'payload',
    ): void {
        foreach ($payload as $key => $value) {
            $nestedPath = $path;

            if (is_string($key)) {
                $normalizedKey = Str::snake($key);
                $nestedPath .= '.'.$key;

                if (
                    in_array(
                        $normalizedKey,
                        self::ForbiddenAuthorityKeys,
                        true,
                    )
                ) {
                    throw ValidationException::withMessages([
                        $nestedPath => "Agent handoff field [{$key}] is not allowed to carry AIOS authority or sensitive state.",
                    ]);
                }
            }

            if (is_array($value)) {
                $this->assertNoForbiddenKeys(
                    $value,
                    $nestedPath,
                );
            }
        }
    }

    /**
     * Reject payloads that exceed the bounded durable collaboration envelope.
     *
     * @param  array<mixed>  $payload
     */
    private function assertPayloadSize(array $payload): void
    {
        $encoded = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );

        if (strlen($encoded) > self::MaxPayloadBytes) {
            throw ValidationException::withMessages([
                'payload' => 'Agent handoff payload exceeds the 32 KiB maximum.',
            ]);
        }
    }
}
