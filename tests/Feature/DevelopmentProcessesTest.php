<?php

use Illuminate\Foundation\DevCommands;

test('AIOS runs one development process for each core workflow role', function () {
    $commands = collect(DevCommands::commands());

    expect($commands->firstWhere('name', 'aios-project-manager'))->toMatchArray([
        'name' => 'aios-project-manager',
        'command' => 'php artisan aios:work --role=project_manager',
    ])->and($commands->firstWhere('name', 'aios-coder'))->toMatchArray([
        'name' => 'aios-coder',
        'command' => 'php artisan aios:work --role=coder',
    ])->and($commands->firstWhere('name', 'aios-reviewer'))->toMatchArray([
        'name' => 'aios-reviewer',
        'command' => 'php artisan aios:work --role=reviewer',
    ]);
});

test('the scheduler restarts a bounded AIOS worker cycle every minute', function () {
    $registration = file_get_contents(base_path('bootstrap/app.php'));

    expect($registration)
        ->toContain("command('aios:work --once --role=project_manager')")
        ->toContain("command('aios:work --once --role=coder')")
        ->toContain("command('aios:work --once --role=reviewer')")
        ->toContain('->everyMinute()')
        ->toContain('->runInBackground()')
        ->toContain('->withoutOverlapping();');
});
