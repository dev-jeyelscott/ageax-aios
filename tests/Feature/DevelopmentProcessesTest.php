<?php

use Illuminate\Foundation\DevCommands;

test('the AIOS worker is included in Laravel development processes', function () {
    $commands = collect(DevCommands::commands());

    expect($commands->firstWhere('name', 'aios-workers'))->toMatchArray([
        'name' => 'aios-workers',
        'command' => 'php artisan aios:work',
    ]);
});

test('the scheduler restarts a bounded AIOS worker cycle every minute', function () {
    $registration = file_get_contents(base_path('bootstrap/app.php'));

    expect($registration)
        ->toContain("command('aios:work --once')")
        ->toContain('->everyMinute()')
        ->toContain('->runInBackground()')
        ->toContain('->withoutOverlapping();');
});
