<?php

namespace App\Services\Transcript;

use App\Domain\DTO\AI\MessageDTO;
use App\Exceptions\ContentNotRelevantException;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight relevance guard for manually uploaded transcripts.
 *
 * Called synchronously after parsing (entries exist) but before TranscriptParsed
 * dispatch — prevents irrelevant content (personal chats, unrelated org recordings,
 * entertainment transcripts) from entering the analysis pipeline.
 *
 * Only runs when org context is set; without it there is no org-specific baseline
 * to compare against, so the check is skipped to avoid false positives.
 *
 * Uses only the first SAMPLE_ENTRIES entries to keep the LLM call cheap.
 */
class TranscriptRelevanceChecker
{
    private const SAMPLE_ENTRIES = 20;

    public function __construct(
        private readonly OpenRouterClient $llm,
    ) {
    }

    /**
     * @param  array<array{speaker?: string, text: string}>  $entries
     * @throws ContentNotRelevantException
     */
    public function check(array $entries, ?string $orgContext): void
    {
        if (blank($orgContext)) {
            return;
        }

        $sample = array_slice($entries, 0, self::SAMPLE_ENTRIES);
        $sampleText = implode("\n", array_map(
            fn ($e) => ($e['speaker'] ?? 'Speaker') . ': ' . ($e['text'] ?? ''),
            $sample,
        ));

        $prompt = <<<PROMPT
Organization context:
{$orgContext}

Below is a sample from a meeting transcript. Evaluate whether this transcript is from a work meeting related to the organization's activities described above.

Set "is_relevant" to false ONLY when the content is clearly:
- A personal or social conversation with no work context
- From a completely different organization with no connection to the context above
- Not a real meeting at all (movie/show/book dialogue, entertainment content)

When in doubt — default to true.

Transcript sample:
{$sampleText}

Respond with JSON only: {"is_relevant": true}
PROMPT;

        try {
            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.followup', config('ai.providers.openrouter.models.followup')),
                maxTokens: 50,
                forceJsonResponse: true,
            );

            if (is_string($json) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                $json = $matches[0];
            }
            $decoded = is_string($json) ? json_decode($json, true) : $json;
            $isRelevant = (bool) ($decoded['is_relevant'] ?? true);
        } catch (\Throwable $e) {
            // LLM failure must not block the upload — fail open.
            Log::warning('TranscriptRelevanceChecker: check failed, allowing upload', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (!$isRelevant) {
            throw new ContentNotRelevantException(
                'The uploaded transcript does not appear to be related to your organization\'s work.',
            );
        }
    }
}
