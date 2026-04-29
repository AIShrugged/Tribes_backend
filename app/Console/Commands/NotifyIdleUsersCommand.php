<?php

namespace App\Console\Commands;

use App\Services\Today\IdleUserNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyIdleUsersCommand extends Command
{
    protected $signature = 'tasks:notify-idle-users
        {--window-end= : ISO timestamp; defaults to start of current hour}';

    protected $description = 'Notify users who closed their last task in the previous hour and now have 0 open tasks. Also notify their organization managers.';

    public function __construct(private readonly IdleUserNotifier $notifier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $windowEnd = $this->option('window-end')
            ? Carbon::parse($this->option('window-end'))
            : now()->startOfHour();
        $windowStart = $windowEnd->copy()->subHour();

        $stats = $this->notifier->notifyForWindow($windowStart, $windowEnd);

        $this->info(json_encode($stats, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
