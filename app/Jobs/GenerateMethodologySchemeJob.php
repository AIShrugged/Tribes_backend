<?php

namespace App\Jobs;

use App\Enums\MethodologySchemeVersion;
use App\Models\AgentActivityLog;
use App\Models\Methodology;
use App\Models\User;
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
        $this->onQueue('heavy');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $version = MethodologySchemeVersion::VER_2->value;

        $scheme = app(MethodologySchemeGenerator::class)->generate($this->methodology->text, $version);

        $this->methodology->update([
            'scheme'         => $scheme,
            'scheme_version' => $version,
        ]);

        if ($this->methodology->user_id) {
            $user = User::find($this->methodology->user_id);
            if ($user) {
                AgentActivityLog::recordActivity(
                    user: $user,
                    toolName: 'methodology_scheme_generated',
                    toolResult: [
                        'methodology_id' => $this->methodology->id,
                        'version' => $version,
                    ],
                );
            }
        }
    }
}
