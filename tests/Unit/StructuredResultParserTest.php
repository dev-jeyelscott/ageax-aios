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

test('it extracts a review decision from a pretty-printed fenced JSON block', function () {
    $output = <<<'TEXT'
    ```json
    {
      "outcome": "approved",
      "summary": "Phase reviewed at HEAD against base. Everything checks out."
    }
    ```
    TEXT;

    expect(app(StructuredResultParser::class)->parseAgentMessage($output))->toBe([
        'outcome' => 'approved',
        'summary' => 'Phase reviewed at HEAD against base. Everything checks out.',
    ]);
});

test('it extracts a review decision from pretty-printed JSON with no fence', function () {
    $output = <<<'TEXT'
    {
      "outcome": "changes_required",
      "summary": null,
      "findings": [
        {
          "severity": "high",
          "location": "app/Models/Foo.php",
          "current_implementation": "does X",
          "expected_implementation": "should do Y",
          "why_incorrect": "breaks Z",
          "required_fix": "change X to Y",
          "verification_requirement": "add a test",
          "implementation_fix_context": "see spec"
        }
      ]
    }
    TEXT;

    $result = app(StructuredResultParser::class)->parseAgentMessage($output);

    expect($result['outcome'])->toBe('changes_required')
        ->and($result['findings'])->toHaveCount(1);
});
