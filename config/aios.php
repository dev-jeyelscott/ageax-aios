<?php

return [
    'workspace_root' => env('AIOS_WORKSPACE_ROOT', dirname(base_path())),
    'obsidian_vault_path' => env('AIOS_OBSIDIAN_VAULT_PATH'),
    'obsidian_context_max_characters' => (int) env('AIOS_OBSIDIAN_CONTEXT_MAX_CHARACTERS', 24000),
    'codex_binary' => env('AIOS_CODEX_BINARY', 'codex'),
    'execution_timeout' => (int) env('AIOS_EXECUTION_TIMEOUT', 1800),
    'stale_worker_after_seconds' => (int) env('AIOS_STALE_WORKER_AFTER_SECONDS', 1860),
    'max_coder_attempts' => (int) env('AIOS_MAX_CODER_ATTEMPTS', 3),
    'max_reviewer_attempts' => (int) env('AIOS_MAX_REVIEWER_ATTEMPTS', 3),
    'vault_organization_enabled' => (bool) env('AIOS_VAULT_ORGANIZATION_ENABLED', true),
    'vault_organization_time' => env('AIOS_VAULT_ORGANIZATION_TIME', '02:00'),
];
