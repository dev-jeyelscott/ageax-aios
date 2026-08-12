<?php

use App\Models\AuditEvent;
use LogicException;

test('audit events are append-only through the application model', function () {
    $event = AuditEvent::factory()->create(['event_type' => 'project.created']);

    expect(fn () => $event->update(['event_type' => 'project.updated']))->toThrow(LogicException::class, 'Audit events are append-only.')
        ->and(fn () => $event->delete())->toThrow(LogicException::class, 'Audit events are append-only.')
        ->and($event->fresh()->event_type)->toBe('project.created');
});
