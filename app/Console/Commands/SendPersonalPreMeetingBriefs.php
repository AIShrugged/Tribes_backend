<?php

namespace App\Console\Commands;

use App\Services\PersonalPreMeetingBriefService;
use Illuminate\Console\Command;

class SendPersonalPreMeetingBriefs extends Command
{
    protected $signature = 'meetings:send-personal-pre-briefs
        {--test-user= : Send all messages only to this Telegram user ID}';

    protected $description = 'Send personal pre-meeting briefs to each internal participant 10-20 min before meeting.';

    public function handle(PersonalPreMeetingBriefService $service): int
    {
        $testUser = $this->option('test-user');
        $sent = $service->sendBriefs($testUser);

        $this->info("Sent {$sent} personal pre-meeting brief(s).");

        return self::SUCCESS;
    }
}
