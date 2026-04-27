<?php

namespace App\Console\Commands;

use App\Jobs\SendDecisionFollowupsJob;
use Illuminate\Console\Command;

class SendDecisionFollowupsCommand extends Command
{
    protected $signature = 'decisions:send-followups';

    protected $description = 'Send Telegram follow-ups for decisions without linked tasks (UC-5.4)';

    public function handle(): int
    {
        $this->info('Dispatching SendDecisionFollowupsJob...');
        SendDecisionFollowupsJob::dispatchSync();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
