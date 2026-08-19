<?php

namespace App\Console\Commands;

use App\Actions\AutoCloseInactiveTickets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:tickets:close-inactive')]
#[Description('Close requester-dependent Tickets whose 72-hour response deadline has expired')]
class CloseInactiveTickets extends Command
{
    public function handle(AutoCloseInactiveTickets $tickets): int
    {
        $tickets->handle();

        return self::SUCCESS;
    }
}
