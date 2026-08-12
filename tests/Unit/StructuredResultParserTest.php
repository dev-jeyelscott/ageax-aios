<?php

use App\Services\StructuredResultParser;

test('it extracts a project plan from a codex agent message event', function () {
    $output = <<<'JSONL'
{"type":"thread.started"}
{"type":"item.completed","item":{"type":"agent_message","text":"{\"phases\":[{\"title\":\"Foundation\",\"objective\":\"Set up the base.\",\"tasks\":[]}] }"}}
{"type":"turn.completed"}
JSONL;

    expect(app(StructuredResultParser::class)->parse($output))->toBe([
        'phases' => [[
            'title' => 'Foundation',
            'objective' => 'Set up the base.',
            'tasks' => [],
        ]],
    ]);
});
