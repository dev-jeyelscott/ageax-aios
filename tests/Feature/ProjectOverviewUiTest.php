<?php

test('project overview keeps the deterministic PM coder reviewer execution graph', function () {
    $source = file_get_contents(
        resource_path('js/components/agent-office.tsx'),
    );

    expect($source)
        ->toContain(
            "const preferredRoleOrder = ['project_manager', 'coder', 'reviewer'] as const;",
        )
        ->toContain('AI Engineering Workflow')
        ->toContain('data-workflow-role={worker.role}')
        ->toContain('data-active-stage=')
        ->toContain('pmToCoderState')
        ->toContain('coderToReviewerState')
        ->toContain('coderToReviewerReverse')
        ->toContain('currentWorkflow.task?.return_from_reviewer === true')
        ->toContain('if (coderToReviewerReverse)')
        ->toContain("coderToReviewerState = 'active'")
        ->toContain('workflow-connector--reverse')
        ->toContain("currentWorkflow?.role === 'coder'")
        ->toContain("currentWorkflow?.role === 'reviewer'");
});

test('project overview uses thumbnail assets instead of the procedural robot presentation', function () {
    $source = file_get_contents(
        resource_path('js/components/agent-office.tsx'),
    );

    expect($source)
        ->toContain("project_manager: '/action-gif/pm-idle.gif'")
        ->toContain("coder: '/action-gif/coder-idle.gif'")
        ->toContain("reviewer: '/action-gif/reviewer-idle.gif'")
        ->toContain('avatar thumbnail')
        ->not->toContain('AgeaxRobot')
        ->not->toContain('@react-three/fiber')
        ->not->toContain('<Canvas');
});

test('project overview distinguishes a completed run from a completed task', function () {
    $source = file_get_contents(
        resource_path('js/components/agent-office.tsx'),
    );

    expect($source)
        ->toContain('Completed ${labelForRole(worker.role)} run for')
        ->toContain("worker.activity_mode === 'recent' && worker.run !== null")
        ->toContain('? worker.run.status')
        ->toContain("worker.activity_mode !== 'recent' &&")
        ->not->toContain('Completed ${worker.task.key} · ${worker.task.title}.');
});

test('project overview exposes durable operational evidence without inventing missing values', function () {
    $source = file_get_contents(
        resource_path('js/components/agent-office.tsx'),
    );

    expect($source)
        ->toContain('Roadmap Progress')
        ->toContain('Current Operation')
        ->toContain('Next Stage')
        ->toContain('Repository · Git Evidence')
        ->toContain('Validation State')
        ->toContain('Execution / Token Usage')
        ->toContain('Health & Warnings')
        ->toContain('Not recorded')
        ->toContain('git_evidence')
        ->toContain('token_usage_total')
        ->toContain('token_observability')
        ->toContain('harness_usage')
        ->toContain('token_usage_evidence')
        ->toContain('usage_window')
        ->toContain('Usage recorded for')
        ->toContain('Raw harness totals are observational');
});

test('project overview keeps pause navigation and live polling behavior', function () {
    $source = file_get_contents(
        resource_path('js/pages/projects/show.tsx'),
    );

    expect($source)
        ->toContain('usePoll(')
        ->toContain('2_000')
        ->toContain('updateStatus.form(project.id)')
        ->toContain("{ value: 'overview', label: 'Overview' }")
        ->toContain("{ value: 'agents', label: 'Agents' }")
        ->toContain("{ value: 'skills', label: 'Skills' }")
        ->toContain("{ value: 'tasks', label: 'Tasks' }")
        ->toContain("{ value: 'activity', label: 'Recent Activity' }");
});

test('execution graph supports responsive vertical handoffs and reduced motion', function () {
    $source = file_get_contents(
        resource_path('js/components/agent-office.css'),
    );

    expect($source)
        ->toContain('.execution-workflow-grid')
        ->toContain('.workflow-connector--active')
        ->toContain('execution-energy-flow')
        ->toContain('execution-particle-flow')
        ->toContain('@media (max-width: 63.999rem)')
        ->toContain('grid-template-columns: minmax(0, 1fr)')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});
