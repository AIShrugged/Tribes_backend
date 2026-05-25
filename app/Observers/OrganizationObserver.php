<?php

namespace App\Observers;

use App\Models\Organization;
use App\Services\LlmPromptProvisioningService;
use Illuminate\Support\Facades\Log;

class OrganizationObserver
{
    public function created(Organization $organization): void
    {
        try {
            app(LlmPromptProvisioningService::class)->provisionForOrganization($organization);
        } catch (\Throwable $e) {
            Log::warning('Failed to provision default LLM prompts for organization', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
