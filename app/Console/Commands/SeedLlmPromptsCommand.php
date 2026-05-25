<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\LlmPromptDefaultRegistry;
use App\Services\LlmPromptProvisioningService;
use Illuminate\Console\Command;

class SeedLlmPromptsCommand extends Command
{
    protected $signature = 'llm-prompts:seed
        {organization? : Organization id or slug}
        {--all : Seed prompts for all organizations}
        {--overwrite : Replace existing organization prompts with current defaults}';

    protected $description = 'Seed default LLM prompts for an existing organization';

    public function handle(
        LlmPromptProvisioningService $provisioning,
        LlmPromptDefaultRegistry $registry,
    ): int {
        $organizations = $this->resolveOrganizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $this->info('Default prompt definitions: '.count($registry->all()));

        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($organizations as $organization) {
            $stats = $provisioning->provisionForOrganization(
                $organization,
                overwrite: (bool) $this->option('overwrite'),
            );

            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }

            $this->line(sprintf(
                'Organization #%d (%s): created=%d updated=%d skipped=%d',
                $organization->id,
                $organization->slug,
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            'Done: organizations=%d created=%d updated=%d skipped=%d',
            $organizations->count(),
            $totals['created'],
            $totals['updated'],
            $totals['skipped'],
        ));

        return self::SUCCESS;
    }

    private function resolveOrganizations(): ?\Illuminate\Support\Collection
    {
        if ($this->option('all')) {
            return Organization::query()->orderBy('id')->get();
        }

        $identifier = $this->argument('organization');
        if (! $identifier) {
            $this->error('Pass an organization id/slug or use --all.');
            return null;
        }

        $organization = Organization::query()
            ->where('slug', $identifier)
            ->when(is_numeric($identifier), fn ($query) => $query->orWhere('id', (int) $identifier))
            ->first();

        if (! $organization) {
            $this->error("Organization [{$identifier}] not found.");
            return null;
        }

        return collect([$organization]);
    }
}
