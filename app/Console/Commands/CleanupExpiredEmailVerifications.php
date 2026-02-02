<?php

namespace App\Console\Commands;

use App\Services\EmailVerificationService;
use Illuminate\Console\Command;

class CleanupExpiredEmailVerifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:cleanup-verifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove expired email verification records';

    /**
     * Execute the console command.
     */
    public function handle(EmailVerificationService $service): int
    {
        $count = $service->cleanupExpired();
        $this->info("Removed {$count} expired verification records");

        return Command::SUCCESS;
    }
}
