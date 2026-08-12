<?php

namespace App\Console\Commands;

use App\Services\VaultOrganizationAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:organize-vault {--once : Run one vault organization cycle}')]
#[Description('Run the global Knowledge Architect against the configured Obsidian vault')]
class OrganizeObsidianVault extends Command
{
    public function handle(VaultOrganizationAgent $agent): int
    {
        $run = $agent->run();
        if ($run === null) {
            $this->info('Vault organization is disabled or already running.');

            return self::SUCCESS;
        }

        $this->info("Knowledge Architect {$run->status} run {$run->id}.");

        return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
