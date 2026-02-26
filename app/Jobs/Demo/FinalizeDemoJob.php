<?php

namespace App\Jobs\Demo;

use App\Models\DemoGeneration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalizeDemoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $generationId)
    {
    }

    public function handle(): void
    {
        $generation = DemoGeneration::findOrFail($this->generationId);

        $generation->markReady();

        Log::info('FinalizeDemoJob: demo generation completed', [
            'generation_id' => $this->generationId,
            'user_id'       => $generation->user_id,
        ]);
    }
}
