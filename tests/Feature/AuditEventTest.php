<?php

use App\Models\AuditEvent;
use App\Models\Project;
use App\Services\AuditLogger;
use LogicException;

test('audit events are append-only through the application model', function () {
    $event = AuditEvent::factory()->create([
        'event_type' => 'project.created',
    ]);

    expect(
        fn () => $event->update([
            'event_type' => 'project.updated',
        ]),
    )
        ->toThrow(
            LogicException::class,
            'Audit events are append-only.',
        )
        ->and(
            fn () => $event->delete(),
        )
        ->toThrow(
            LogicException::class,
            'Audit events are append-only.',
        )
        ->and($event->fresh()->event_type)
        ->toBe('project.created');
});

test('audit logger redacts internal notes and private environment evidence', function () {
    $project = Project::factory()->create();

    $event = app(AuditLogger::class)->record(
        'ticket.security_audit_test',
        [
            'ticket_id' => 123,
            'decision' => 'approved',
            'internal_note' => 'PRIVATE-INTERNAL-NOTE',
            'internal_note_body' => 'PRIVATE-INTERNAL-NOTE-BODY',
            'environment' => [
                'HOME' => '/home/private-user',
                'PATH' => '/private/bin',
            ],
            'env_vars' => [
                'APP_ENV' => 'production',
            ],
            'nested' => [
                'private_environment_values' => [
                    'WORKSPACE' => '/private/workspace',
                ],
                'diagnostic' => 'safe-diagnostic',
                'raw_dump' => 'HOME=/home/private-user PATH=/private/bin',
            ],
        ],
        $project,
    );

    $serialized = json_encode(
        $event->payload,
        JSON_THROW_ON_ERROR,
    );

    expect($event->payload['ticket_id'])
        ->toBe(123)
        ->and($event->payload['decision'])
        ->toBe('approved')
        ->and($event->payload['internal_note'])
        ->toBe('[REDACTED]')
        ->and($event->payload['internal_note_body'])
        ->toBe('[REDACTED]')
        ->and($event->payload['environment'])
        ->toBe('[REDACTED]')
        ->and($event->payload['env_vars'])
        ->toBe('[REDACTED]')
        ->and(
            $event->payload['nested'][
                'private_environment_values'
            ],
        )
        ->toBe('[REDACTED]')
        ->and(
            $event->payload['nested']['diagnostic'],
        )
        ->toBe('safe-diagnostic')
        ->and(
            $event->payload['nested']['raw_dump'],
        )
        ->not->toContain('/home/private-user')
        ->not->toContain('/private/bin')
        ->and($serialized)
        ->not->toContain('PRIVATE-INTERNAL-NOTE')
        ->not->toContain('/home/private-user')
        ->not->toContain('/private/workspace')
        ->not->toContain('/private/bin');
});
