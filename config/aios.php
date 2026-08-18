<?php

return [
    'workspace_root' => env('AIOS_WORKSPACE_ROOT', dirname(base_path())),
    'obsidian_vault_path' => env('AIOS_OBSIDIAN_VAULT_PATH'),
    'obsidian_context_max_characters' => (int) env('AIOS_OBSIDIAN_CONTEXT_MAX_CHARACTERS', 2000),
    'obsidian_context_max_note_characters' => (int) env('AIOS_OBSIDIAN_CONTEXT_MAX_NOTE_CHARACTERS', 2000),
    'obsidian_context_max_notes' => (int) env('AIOS_OBSIDIAN_CONTEXT_MAX_NOTES', 4),
    'token_observability_window' => (int) env('AIOS_TOKEN_OBSERVABILITY_WINDOW', 20),
    'token_warning_coder' => (int) env('AIOS_TOKEN_WARNING_CODER', 150000),
    'token_warning_reviewer' => (int) env('AIOS_TOKEN_WARNING_REVIEWER', 60000),
    'context_cost_warning_share' => (float) env('AIOS_CONTEXT_COST_WARNING_SHARE', 0.5),
    'codex_binary' => env('AIOS_CODEX_BINARY', 'codex'),
    'claude_code_binary' => env('AIOS_CLAUDE_CODE_BINARY', 'claude'),
    'execution_timeout' => (int) env('AIOS_EXECUTION_TIMEOUT', 1800),
    'stale_worker_after_seconds' => (int) env('AIOS_STALE_WORKER_AFTER_SECONDS', 1860),
    'worker_lease_seconds' => (int) env('AIOS_WORKER_LEASE_SECONDS', 60),
    'worker_heartbeat_interval_seconds' => (int) env('AIOS_WORKER_HEARTBEAT_INTERVAL_SECONDS', 5),
    'worker_task_cooldown_seconds' => (int) env('AIOS_WORKER_TASK_COOLDOWN_SECONDS', 300),
    // Project Manager retrigger cadence is deliberately separate from the Coder/Reviewer task
    // cooldown above: PM (re-)claims a roadmap far less often, so it gets its own, much longer,
    // independently configurable timer instead of sharing worker_task_cooldown_seconds.
    'roadmap_retry_cooldown_seconds' => (int) env('AIOS_ROADMAP_RETRY_COOLDOWN_SECONDS', 3600),
    'max_roadmap_attempts' => (int) env('AIOS_MAX_ROADMAP_ATTEMPTS', 3),
    // Caps how many phases a single Project Manager execution may plan and persist at once.
    // Large roadmaps are decomposed across multiple bounded batches instead of demanding the
    // entire plan as one JSON response (see RunProjectManager/ApplyRoadmapPlan).
    'roadmap_max_phases_per_batch' => (int) env('AIOS_ROADMAP_MAX_PHASES_PER_BATCH', 3),
    'max_coder_attempts' => (int) env('AIOS_MAX_CODER_ATTEMPTS', 3),
    'max_reviewer_attempts' => (int) env('AIOS_MAX_REVIEWER_ATTEMPTS', 3),
    'no_progress_repeat_threshold' => (int) env('AIOS_NO_PROGRESS_REPEAT_THRESHOLD', 1),
    'roadmap_scan_interval_hours' => (int) env('AIOS_ROADMAP_SCAN_INTERVAL_HOURS', 24),

    // Workflow Recovery Engineer (AIOS system/reliability agent, not a project workflow role).
    'recovery_stale_status_after_seconds' => (int) env('AIOS_RECOVERY_STALE_STATUS_AFTER_SECONDS', 90),
    'recovery_claim_stale_after_seconds' => (int) env('AIOS_RECOVERY_CLAIM_STALE_AFTER_SECONDS', 900),
    'recovery_max_attempts' => (int) env('AIOS_RECOVERY_MAX_ATTEMPTS', 3),
    'recovery_engineer_harness' => env('AIOS_RECOVERY_ENGINEER_HARNESS', 'claude_code'),
    'recovery_engineer_model' => env('AIOS_RECOVERY_ENGINEER_MODEL'),
    'recovery_engineer_reasoning_setting' => env('AIOS_RECOVERY_ENGINEER_REASONING_SETTING'),
    'recovery_repository_path' => env('AIOS_RECOVERY_REPOSITORY_PATH', base_path()),
    'recovery_validation_commands' => array_values(array_filter(explode(',', (string) env('AIOS_RECOVERY_VALIDATION_COMMANDS', '')))),
];
