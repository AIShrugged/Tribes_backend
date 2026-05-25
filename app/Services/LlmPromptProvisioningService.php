<?php

namespace App\Services;

use App\Models\LlmPrompt;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LlmPromptProvisioningService
{
    public function __construct(
        private readonly LlmPromptDefaultRegistry $registry,
    ) {
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function provisionForOrganization(Organization $organization, bool $overwrite = false): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $definitions = $this->registry->all();

        if (! Schema::hasTable('llm_prompts')) {
            $stats['skipped'] = count($definitions);

            return $stats;
        }

        DB::transaction(function () use ($organization, $overwrite, $definitions, &$stats): void {
            foreach ($definitions as $definition) {
                $prompt = LlmPrompt::query()
                    ->where('organization_id', $organization->id)
                    ->where('slug', $definition['slug'])
                    ->first();

                $attributes = [
                    'name' => $definition['name'],
                    'prompt' => $this->registry->render($definition['view']),
                ];

                if (! $prompt) {
                    LlmPrompt::query()->create([
                        'organization_id' => $organization->id,
                        'slug' => $definition['slug'],
                        ...$attributes,
                    ]);
                    $stats['created']++;
                    continue;
                }

                if ($overwrite) {
                    $prompt->update($attributes);
                    $stats['updated']++;
                    continue;
                }

                $stats['skipped']++;
            }
        });

        return $stats;
    }
}
