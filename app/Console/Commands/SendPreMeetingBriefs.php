<?php

namespace App\Console\Commands;

use App\Services\PreMeetingBriefService;
use Illuminate\Console\Command;

class SendPreMeetingBriefs extends Command
{
    protected $signature = 'meetings:send-pre-briefs';

    protected $description = 'Send pre-meeting briefs with previous summary and open tasks to configured Telegram chats';

    public function handle(PreMeetingBriefService $service): int
    {
        $sent = $service->sendBriefs();

        $this->info("Sent {$sent} pre-meeting brief(s).");

        return self::SUCCESS;
    }
}
