<?php

use App\Models\AuditEvent;
use App\Models\VaultOrganizationRun;
use App\Services\CodexCliRunner;
use App\Services\VaultOrganizationAgent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\mock;

test('the knowledge architect records an audited, redacted vault organization run', function () {
    Storage::fake('local');
    $vault = storage_path('framework/testing/vault-'.fake()->uuid());
    File::ensureDirectoryExists($vault);
    config()->set('aios.obsidian_vault_path', $vault);
    $report = ['report' => ['vault_architecture' => 'Projects and engineering knowledge.', 'master_structure' => ['Projects'], 'mocs_updated' => ['MASTER.md']]];
    $output = implode("\n", [
        json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($report, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR),
        json_encode(['type' => 'turn.completed', 'usage' => ['input_tokens' => 80, 'output_tokens' => 20]], JSON_THROW_ON_ERROR),
    ]);
    mock(CodexCliRunner::class)
        ->shouldReceive('runAtPath')
        ->once()
        ->withArgs(fn (string $path, string $prompt, mixed $callback): bool => $path === $vault && str_contains($prompt, 'Knowledge Architect') && is_callable($callback))
        ->andReturnUsing(function (string $path, string $prompt, callable $onOutput) use ($output): array {
            $onOutput('stdout', 'APP_KEY=should-not-be-visible');

            return ['exit_code' => 0, 'output' => $output, 'error_output' => ''];
        });

    $run = app(VaultOrganizationAgent::class)->run();

    expect($run)->toBeInstanceOf(VaultOrganizationRun::class)
        ->and($run->status)->toBe('completed')
        ->and($run->token_usage)->toBe(100)
        ->and($run->report)->toBe($report)
        ->and($run->live_output)->toContain('[REDACTED]')
        ->and($run->live_output)->not->toContain('should-not-be-visible')
        ->and(Storage::disk('local')->get($run->log_path))->toContain('vault_architecture')
        ->and(AuditEvent::query()->where('event_type', 'vault_organization.completed')->where('payload->vault_organization_run_id', $run->id)->exists())->toBeTrue();
});

test('the knowledge architect refuses an unavailable vault path', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/missing-'.fake()->uuid()));

    app(VaultOrganizationAgent::class)->run();
})->throws(RuntimeException::class, 'configured Obsidian vault directory is unavailable');
