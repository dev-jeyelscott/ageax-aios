<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single disaster-recovery snapshot record in AIOS's independent backup ledger. This model
 * deliberately lives on the "aios_backup_ledger" connection (config/database.php), a separate
 * SQLite database stored under aios.backup_path, so it and the artifacts it references remain
 * readable even after the primary AIOS database is deleted or corrupted. It must never gain a
 * foreign key into the primary connection; agent_run_id is plain attribution only.
 *
 * @property int $id
 * @property string $status
 * @property string $reason
 * @property string $driver
 * @property string $connection_name
 * @property ?string $artifact_path
 * @property ?int $agent_run_id
 * @property ?int $size_bytes
 * @property ?string $checksum_sha256
 * @property bool $integrity_verified
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $verified_at
 * @property ?Carbon $restored_at
 * @property ?array<string, mixed> $restore_evidence
 * @property ?string $error
 */
class DatabaseBackup extends Model
{
    protected $connection = 'aios_backup_ledger';

    protected $table = 'database_backups';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'integrity_verified' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'restored_at' => 'datetime',
            'restore_evidence' => 'array',
        ];
    }

    public function isRestorable(): bool
    {
        return $this->status === 'completed'
            && $this->integrity_verified
            && $this->completed_at !== null
            && ($this->size_bytes ?? 0) > 0
            && filled($this->checksum_sha256)
            && filled($this->artifact_path);
    }
}
