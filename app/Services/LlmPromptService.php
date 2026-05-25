<?php

namespace App\Services;

use App\Models\LlmPrompt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LlmPromptService
{
    /**
     * @param array<string, mixed> $variables
     */
    public function render(
        string $slug,
        ?int $organizationId,
        string $fallbackPrompt,
        array $variables = [],
        ?string $name = null,
    ): string {
        $prompt = $this->resolve($slug, $organizationId, $fallbackPrompt, $name)?->prompt ?? $fallbackPrompt;

        if ($variables === []) {
            return $prompt;
        }

        $substitutions = [];
        foreach ($variables as $key => $value) {
            $substitutions['{'.$key.'}'] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return strtr($prompt, $substitutions);
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function renderView(
        string $slug,
        ?int $organizationId,
        string $fallbackView,
        array $variables = [],
        ?string $name = null,
    ): string {
        return $this->render(
            slug: $slug,
            organizationId: $organizationId,
            fallbackPrompt: view($fallbackView)->render(),
            variables: $variables,
            name: $name,
        );
    }

    public function resolve(
        string $slug,
        ?int $organizationId,
        string $fallbackPrompt,
        ?string $name = null,
    ): ?LlmPrompt {
        try {
            $prompt = LlmPrompt::query()
                ->where('slug', $slug)
                ->where(function ($query) use ($organizationId): void {
                    $query->whereNull('organization_id');

                    if ($organizationId !== null) {
                        $query->orWhere('organization_id', $organizationId);
                    }
                })
                ->orderByRaw('CASE WHEN organization_id = ? THEN 0 ELSE 1 END', [$organizationId ?? 0])
                ->first();

            if ($prompt) {
                return $prompt;
            }

            return LlmPrompt::query()->firstOrCreate(
                ['organization_id' => null, 'slug' => $slug],
                [
                    'name' => $name ?: Str::headline(str_replace(['.', '_', '-'], ' ', $slug)),
                    'prompt' => $fallbackPrompt,
                ],
            );
        } catch (QueryException $e) {
            Log::warning('LLM prompt lookup failed, using fallback prompt', [
                'slug' => $slug,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
