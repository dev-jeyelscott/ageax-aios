<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

test('a database backup is scheduled every fifteen minutes regardless of AIOS agent activity', function () {
    $schedule = new Schedule;
    $schedule->command('aios:database-backup:create', ['--reason' => 'scheduled'])->everyFifteenMinutes();
    expect($schedule->events()[0]->expression)->toBe('*/15 * * * *');

    $registration = File::get(base_path('bootstrap/app.php'));
    expect($registration)->toContain("command('aios:database-backup:create'")
        ->and($registration)->toContain('everyFifteenMinutes()');
});
