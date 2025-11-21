<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\EventServiceFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ParseEventsJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Source $source
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $eventService = EventServiceFactory::make($this->source);

        $events = $eventService->getAllByCalendar();

        foreach ($events as $eventDTO) {
            $this->source->calendarEvents()->create($eventDTO->toArray());
        }
    }
}
