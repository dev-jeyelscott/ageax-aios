<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // AIOS-owned Workflow Recovery Engineer detection/repair scan. Every five minutes,
        // per AGENTS.md; withoutOverlapping guards against a slow scan cycle running twice.
        $schedule->command('aios:recover-workflows')->everyFiveMinutes()->withoutOverlapping();

        // Failure-to-Skill Promotion Queue detection is observational only. It scans existing
        // durable evidence and creates/updates operator-review candidates; it never runs a
        // harness, changes workflow state, or mutates Skills without a later explicit decision.
        $schedule->command('aios:knowledge-improvements:scan')->hourly()->withoutOverlapping();

        // Independent disaster-recovery backup, deliberately unconditional and not gated on any
        // AIOS agent execution: DatabaseProtectionGuard only creates a backup immediately before a
        // protected AIOS-orchestrated execution, so a destructive action taken outside AIOS's own
        // orchestration (a manual command, another tool, a misconfigured external process) would
        // otherwise go uncovered. This keeps a recovery point no more than 15 minutes stale
        // regardless of what caused the need to restore.
        $schedule->command('aios:database-backup:create', ['--reason' => 'scheduled'])->everyFifteenMinutes()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
