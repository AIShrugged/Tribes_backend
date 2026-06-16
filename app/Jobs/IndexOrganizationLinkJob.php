<?php

namespace App\Jobs;

use App\Models\OrganizationLink;
use App\Services\Onboarding\OrganizationContextIndexerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexOrganizationLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(private readonly int $linkId)
    {
        $this->onQueue('heavy');
    }

    public function handle(OrganizationContextIndexerService $service): void
    {
        $link = OrganizationLink::findOrFail($this->linkId);

        try {
            $service->indexLink($link);
        } catch (\Throwable $e) {
            Log::error('Organization link indexing failed', [
                'link_id' => $this->linkId,
                'url'     => $link->url,
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
