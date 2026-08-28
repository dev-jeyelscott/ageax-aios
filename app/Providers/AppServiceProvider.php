<?php

namespace App\Providers;

use App\Services\AgentHarnessResolver;
use App\Services\ClaudeCodeHarness;
use App\Services\CodexHarness;
use App\Services\PiperTextToSpeech;
use App\Services\RuntimeCommandContext;
use App\Services\SpeechToText;
use App\Services\TextToSpeech;
use App\Services\WhisperCppSpeechToText;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services and process-local runtime execution context.
     */
    public function register(): void
    {
        $this->app->singleton(
            AgentHarnessResolver::class,
            fn (Application $app): AgentHarnessResolver => new AgentHarnessResolver([
                $app->make(CodexHarness::class),
                $app->make(ClaudeCodeHarness::class),
            ]),
        );

        $this->app->bind(
            SpeechToText::class,
            WhisperCppSpeechToText::class,
        );

        $this->app->bind(
            TextToSpeech::class,
            PiperTextToSpeech::class,
        );

        $this->app->singleton(
            RuntimeCommandContext::class,
            fn (): RuntimeCommandContext => new RuntimeCommandContext,
        );
    }

    /**
     * Bootstrap application defaults, development commands, and safe command identity tracking.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRuntimeCommandContext();
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

    /**
     * Track only the Laravel command name so exception capture never needs raw console arguments.
     */
    private function configureRuntimeCommandContext(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            app(RuntimeCommandContext::class)->push($event->command);
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            app(RuntimeCommandContext::class)->pop();
        });
    }
}
