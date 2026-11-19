<?php

namespace App\Console\Commands;

use App\Support\CallService;
use Illuminate\Console\Command;

class ExpireCalls extends Command
{
    protected $signature = 'calls:expire';

    protected $description = 'End ringing calls that were never answered';

    public function handle(): int
    {
        $count = CallService::expireRingingCalls();
        $this->info("Expired {$count} ringing call(s).");

        return self::SUCCESS;
    }
}
