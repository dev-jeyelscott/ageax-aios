<?php

namespace App\Providers;

use App\Services\AgentHarnessResolver;
use App\Services\AuditLogger;
use App\Services\ClaudeCodeHarness;
use App\Services\CodexHarness;
use App\Services\ContextBudgetedAgentHarness;
use App\Services\ContextBudgetGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            AgentHarnessResolver::class,
            function (Application $app): AgentHarnessResolver {
                $guard = $app->make(ContextBudgetGuard::class);
                $audit = $app->make(AuditLogger::class);

                return new AgentHarnessResolver([
                    new ContextBudgetedAgentHarness(
                        $app->make(CodexHarness::class),
                        $guard,
                        $audit,
                    ),
                    new ContextBudgetedAgentHarness(
                        $app->make(ClaudeCodeHarness::class),
                        $guard,
                        $audit,
                    ),
                ]);
            },
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        DevCommands::artisan('aios:work', 'aios-workers');
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : null,
        );
    }
}
