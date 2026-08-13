<?php

return [
    'workspace_root' => env('AIOS_WORKSPACE_ROOT', dirname(base_path())),
    'obsidian_vault_path' => env('AIOS_OBSIDIAN_VAULT_PATH'),
    'obsidian_context_max_characters' => (int) env('AIOS_OBSIDIAN_CONTEXT_MAX_CHARACTERS', 24000),
    'obsidian_context_max_note_characters' => (int) env('AIOS_OBSIDIAN_CONTEXT_MAX_NOTE_CHARACTERS', 4000),
    'obsidian_context_max_notes' => (int) env('AIOS_OBSIDIAN_CONTEXT_MAX_NOTES', 12),
    'codex_binary' => env('AIOS_CODEX_BINARY', 'codex'),
    'execution_timeout' => (int) env('AIOS_EXECUTION_TIMEOUT', 1800),
    'stale_worker_after_seconds' => (int) env('AIOS_STALE_WORKER_AFTER_SECONDS', 1860),
    'worker_lease_seconds' => (int) env('AIOS_WORKER_LEASE_SECONDS', 60),
    'worker_heartbeat_interval_seconds' => (int) env('AIOS_WORKER_HEARTBEAT_INTERVAL_SECONDS', 5),
    'max_coder_attempts' => (int) env('AIOS_MAX_CODER_ATTEMPTS', 3),
    'max_reviewer_attempts' => (int) env('AIOS_MAX_REVIEWER_ATTEMPTS', 3),
    'roadmap_scan_interval_hours' => (int) env('AIOS_ROADMAP_SCAN_INTERVAL_HOURS', 24),
];
