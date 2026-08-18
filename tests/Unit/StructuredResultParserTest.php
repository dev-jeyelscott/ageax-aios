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

test('it extracts a fenced plan even when a nested field contains a single-line JSON object', function () {
    // Regression: project_knowledge.architecture_decisions entries are sometimes rendered as
    // single-line {"title":...,"rationale":...} objects with no "type" key. Before the fenced
    // block was checked first, the codex-style NDJSON line scan matched one of these nested
    // lines as if it were the top-level event, returning that unrelated fragment instead of the
    // real plan and causing roadmap decomposition to fail with an unparseable-output error.
    $output = <<<'TEXT'
    Now producing the plan.

    ```json
    {
      "project_knowledge": {
        "architecture_decisions": [
          {"title": "Starter kit baseline", "rationale": "Nothing custom yet."}
        ]
      },
      "phases": [{"title": "Foundation", "objective": "Set up the base.", "tasks": []}],
      "remaining_work": false
    }
    ```
    TEXT;

    expect(app(StructuredResultParser::class)->parse($output))->toBe([
        'project_knowledge' => [
            'architecture_decisions' => [
                ['title' => 'Starter kit baseline', 'rationale' => 'Nothing custom yet.'],
            ],
        ],
        'phases' => [[
            'title' => 'Foundation',
            'objective' => 'Set up the base.',
            'tasks' => [],
        ]],
        'remaining_work' => false,
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
