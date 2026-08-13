<?php

use Illuminate\Foundation\DevCommands;

test('the AIOS worker is included in Laravel development processes', function () {
    $commands = collect(DevCommands::commands());

    expect($commands->firstWhere('name', 'aios-workers'))->toMatchArray([
        'name' => 'aios-workers',
        'command' => 'php artisan aios:work',
    ]);
});
