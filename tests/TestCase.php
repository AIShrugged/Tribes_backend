<?php

namespace Tests;

use Illuminate\Foundation\Testing\Concerns\InteractsWithContainer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithContainer;

    protected bool $mockLlm = true;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->mockLlm) {
            return;
        }

        Http::fake([
            'openrouter.ai/*' => fn (Request $request) => $this->fakeLlmResponse($request),
        ]);
    }

    private function fakeLlmResponse(Request $request)
    {
        $payload = $request->data();
        $messages = $payload['messages'] ?? [];
        $prompt = collect($messages)
            ->pluck('content')
            ->filter(fn ($content) => is_string($content))
            ->implode("\n\n");

        if (! empty($payload['tools'])) {
            return Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Mocked testing response',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200);
        }

        return Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $this->fakeLlmContent($payload, $prompt),
                ],
                'finish_reason' => 'stop',
            ]],
        ], 200);
    }

    private function fakeLlmContent(array $payload, string $prompt): string
    {
        $forceJson = ($payload['response_format']['type'] ?? null) === 'json_object';

        if (! $forceJson) {
            return 'Mocked LLM response';
        }

        if (str_contains($prompt, '"new_tasks"') && str_contains($prompt, '"status_updates"')) {
            return json_encode([
                'new_tasks' => [],
                'status_updates' => [],
            ]);
        }

        if (str_contains($prompt, '"participants"') && str_contains($prompt, '"relationships"')) {
            return json_encode([
                'participants' => [],
                'relationships' => [],
            ]);
        }

        if (str_contains($prompt, 'Return ONLY a JSON array of relevant category names')) {
            return json_encode([]);
        }

        if (str_contains($prompt, '"relationship_type": "collaborative|conflicting|hierarchical|neutral"')) {
            return json_encode([
                'summary' => 'Mocked relationship summary',
                'key_observations' => [],
                'positive_interactions' => [],
                'negative_interactions' => [],
                'relationship_type' => 'neutral',
            ]);
        }

        if (str_contains($prompt, '"title": "Краткое название встречи') || str_contains($prompt, '"key_points"')) {
            return json_encode([
                'title' => 'Mocked Meeting',
                'summary' => 'Mocked summary',
                'key_points' => [],
                'decisions' => [],
            ]);
        }

        if (str_contains($prompt, 'Return a JSON array of tasks in the following format')) {
            return json_encode([]);
        }

        if (str_contains($prompt, 'Return a JSON array in the following format') && str_contains($prompt, 'participant_id')) {
            return json_encode([]);
        }

        if (str_contains($prompt, 'Текст с методикой:') || str_contains($prompt, 'prompts.methodology_prompt')) {
            return json_encode([
                'summary' => 'Mocked followup',
                'action_items' => [],
            ]);
        }

        return json_encode(new \stdClass());
    }
}
