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
    'coder_low_complexity_execution_timeout' => (int) env('AIOS_CODER_LOW_COMPLEXITY_EXECUTION_TIMEOUT', 180),
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
    'max_task_planning_revisions' => (int) env('AIOS_MAX_TASK_PLANNING_REVISIONS', 3),
    'max_reviewer_attempts' => (int) env('AIOS_MAX_REVIEWER_ATTEMPTS', 3),
    'no_progress_repeat_threshold' => (int) env('AIOS_NO_PROGRESS_REPEAT_THRESHOLD', 1),
    // Stops a valid Coder -> Reviewer rejection loop when each rejection has the same task
    // contract and no repository progress. This blocks for an operator; it never approves
    // or cancels a task whose acceptance criteria remain unmet.
    'review_no_progress_block_threshold' => (int) env('AIOS_REVIEW_NO_PROGRESS_BLOCK_THRESHOLD', 3),
    'knowledge_improvement_occurrence_threshold' => (int) env('AIOS_KNOWLEDGE_IMPROVEMENT_OCCURRENCE_THRESHOLD', 3),
    'knowledge_improvement_reopen_threshold' => (int) env('AIOS_KNOWLEDGE_IMPROVEMENT_REOPEN_THRESHOLD', 3),
    'knowledge_improvement_scan_limit' => (int) env('AIOS_KNOWLEDGE_IMPROVEMENT_SCAN_LIMIT', 500),
    'knowledge_improvement_lookback_days' => (int) env('AIOS_KNOWLEDGE_IMPROVEMENT_LOOKBACK_DAYS', 180),
    'roadmap_scan_interval_hours' => (int) env('AIOS_ROADMAP_SCAN_INTERVAL_HOURS', 24),

    // Local-first speech-to-text adapter. This capability is disabled until an operator explicitly
    // configures an absolute local whisper.cpp binary and model path. Audio remains non-durable.
    'voice_stt_enabled' => (bool) env('AIOS_VOICE_STT_ENABLED', false),
    'voice_stt_binary_path' => env('AIOS_VOICE_STT_BINARY_PATH'),
    'voice_stt_model_path' => env('AIOS_VOICE_STT_MODEL_PATH'),
    'voice_stt_timeout_seconds' => (int) env('AIOS_VOICE_STT_TIMEOUT_SECONDS', 120),
    'voice_stt_max_audio_bytes' => (int) env('AIOS_VOICE_STT_MAX_AUDIO_BYTES', 10485760),
    'voice_stt_max_duration_seconds' => (int) env('AIOS_VOICE_STT_MAX_DURATION_SECONDS', 60),

    // Optional local text-to-speech presentation adapter. This capability is disabled until an
    // operator explicitly configures an absolute local Piper binary and ONNX voice model path.
    // TTS output is presentation-only and must never become workflow or durable application state.
    'voice_tts_enabled' => (bool) env('AIOS_VOICE_TTS_ENABLED', false),
    'voice_tts_binary_path' => env('AIOS_VOICE_TTS_BINARY_PATH'),
    'voice_tts_model_path' => env('AIOS_VOICE_TTS_MODEL_PATH'),
    'voice_tts_timeout_seconds' => (int) env('AIOS_VOICE_TTS_TIMEOUT_SECONDS', 60),
    'voice_tts_max_text_characters' => (int) env('AIOS_VOICE_TTS_MAX_TEXT_CHARACTERS', 1000),
    'voice_tts_max_audio_bytes' => (int) env('AIOS_VOICE_TTS_MAX_AUDIO_BYTES', 16777216),

    // Global Orchestrator bootstrap defaults. These values provision only the configurable
    // advisory Agent identity. They do not schedule, invoke, route, or mutate anything.
    'orchestrator_harness' => env('AIOS_ORCHESTRATOR_HARNESS', 'claude_code'),
    'orchestrator_model' => env('AIOS_ORCHESTRATOR_MODEL'),
    'orchestrator_reasoning_setting' => env('AIOS_ORCHESTRATOR_REASONING_SETTING'),

    // Workflow Recovery Engineer (AIOS system/reliability agent, not a project workflow role).
    'recovery_stale_status_after_seconds' => (int) env('AIOS_RECOVERY_STALE_STATUS_AFTER_SECONDS', 90),
    'recovery_claim_stale_after_seconds' => (int) env('AIOS_RECOVERY_CLAIM_STALE_AFTER_SECONDS', 900),
    'recovery_max_attempts' => (int) env('AIOS_RECOVERY_MAX_ATTEMPTS', 3),
    'recovery_engineer_harness' => env('AIOS_RECOVERY_ENGINEER_HARNESS', 'claude_code'),
    'recovery_engineer_model' => env('AIOS_RECOVERY_ENGINEER_MODEL'),
    'recovery_engineer_reasoning_setting' => env('AIOS_RECOVERY_ENGINEER_REASONING_SETTING'),
    'recovery_repository_path' => env('AIOS_RECOVERY_REPOSITORY_PATH', base_path()),
    'recovery_validation_commands' => array_values(array_filter(explode(',', (string) env('AIOS_RECOVERY_VALIDATION_COMMANDS', '')))),

    // Independent disaster-recovery backup subsystem (P0 database protection hardening). This
    // lives outside both the AIOS repository and any managed project workspace so it survives
    // deletion of the primary AIOS database or repository-local files. The ledger itself is a
    // separate SQLite database (see the "aios_backup_ledger" connection below) stored under this
    // same path, not a table in the primary application database.
    // Which connection is "the primary AIOS database" to snapshot; defaults to database.default
    // when unset. Kept as its own key so it can be pinned independently in unusual deployments.
    'database_connection' => env('AIOS_DATABASE_CONNECTION'),
    'backup_path' => env('AIOS_BACKUP_PATH', getenv('HOME') !== false ? getenv('HOME').'/.local/share/ageax-aios/backups' : storage_path('app/aios-backups')),
    'backup_retention_count' => (int) env('AIOS_BACKUP_RETENTION_COUNT', 20),
    // How long a verified backup remains an acceptable "recovery point" for DatabaseProtectionGuard
    // before a fresh one is created ahead of the next protected execution.
    'database_protection_freshness_seconds' => (int) env('AIOS_DATABASE_PROTECTION_FRESHNESS_SECONDS', 3600),
    'database_restore_lock_filename' => env('AIOS_DATABASE_RESTORE_LOCK_FILENAME', 'restore.lock'),
];
