<?php

namespace App\Jobs;

use App\Enums\MethodologySchemeVersion;
use App\Models\Methodology;
use App\Services\MethodologySchemeGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

class GenerateMethodologySchemeJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Methodology $methodology
    )
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $version = MethodologySchemeVersion::VER_1->value;

        $scheme = app(MethodologySchemeGenerator::class)->generate($this->methodology->text, $version);

        $this->methodology->update([
            'scheme'         => $scheme,
            'scheme_version' => $version,
        ]);
    }
}
