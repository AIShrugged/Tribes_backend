<?php

namespace App\Services\Agent\Tools;

use App\Models\CalendarEvent;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class GetTranscriptTool implements ToolInterface
{
    /** Max transcript chars before delegating to sub-agent summarization */
    private const SUB_AGENT_THRESHOLD = 10000;

    private const SUB_AGENT_MODEL = 'anthropic/claude-3.5-sonnet';

    public function getName(): string
    {
        return 'get_transcript';
    }

    public function getDescription(): string
    {
        return 'Get and analyze the transcript of a meeting/calendar event by its ID. '
            . 'Provide a question to get a focused analysis instead of the raw transcript. '
            . 'If the transcript is very long, it will be automatically analyzed by a sub-agent and a summary returned.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the calendar event to fetch transcript for',
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'What you want to know from this transcript. E.g.: "What decisions were made?", "Summarize the meeting", "What did John say about the project?"',
                ],
            ],
            'required' => ['calendar_event_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $calendarEventId = $parameters['calendar_event_id'] ?? null;
        $question = $parameters['question'] ?? null;

        if (! $calendarEventId) {
            return [
                'success' => false,
                'error' => 'calendar_event_id is required',
            ];
        }

        $event = CalendarEvent::with(['participants.profile', 'transcriptEntries.participant.profile'])->find($calendarEventId);

        if (! $event) {
            return [
                'success' => false,
                'error' => 'Calendar event not found',
            ];
        }

        if ($event->transcriptEntries->isEmpty()) {
            return [
                'success' => false,
                'error' => 'Transcript not available for this event',
            ];
        }

        $eventMeta = [
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
        ];

        // Format transcript as readable text
        $transcriptText = $this->formatTranscript($event);

        // If transcript is small enough and no question — return raw
        if (strlen($transcriptText) <= self::SUB_AGENT_THRESHOLD && ! $question) {
            return [
                'success' => true,
                'event' => $eventMeta,
                'transcript_text' => $transcriptText,
                'entries_count' => $event->transcriptEntries->count(),
            ];
        }

        // Large transcript or specific question — delegate to sub-agent
        return $this->analyzeWithSubAgent($eventMeta, $transcriptText, $question, $event->transcriptEntries->count());
    }

    private function formatTranscript(CalendarEvent $event): string
    {
        $lines = [];

        foreach ($event->transcriptEntries as $entry) {
            $speaker = $entry->participant?->profile?->name
                ?? $entry->participant?->name
                ?? 'Unknown';

            $timestamp = $entry->start_relative
                ? '[' . gmdate('H:i:s', (int) $entry->start_relative) . ']'
                : '';

            $lines[] = "{$timestamp} {$speaker}: {$entry->text}";
        }

        return implode("\n", $lines);
    }

    private function analyzeWithSubAgent(array $eventMeta, string $transcriptText, ?string $question, int $entriesCount): array
    {
        $defaultQuestion = 'Provide a detailed summary of this meeting: main topics, key decisions, assigned tasks, important discussion points.';
        $actualQuestion = $question ?: $defaultQuestion;

        $prompt = <<<PROMPT
You are analyzing a meeting transcript. Answer the user's question based on the transcript.

## Meeting
Title: {$eventMeta['title']}
Start: {$eventMeta['starts_at']}
End: {$eventMeta['ends_at']}

## Question
{$actualQuestion}

## Transcript
{$transcriptText}

## Instructions
- Answer specifically and in a structured manner
- Indicate who said what when it's important
- If the question is about decisions/tasks — highlight them separately
PROMPT;

        try {
            Log::info('GetTranscriptTool: delegating to sub-agent', [
                'event_id' => $eventMeta['id'],
                'transcript_chars' => strlen($transcriptText),
                'entries_count' => $entriesCount,
                'question' => $actualQuestion,
            ]);

            $analysis = OpenRouterClient::chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: self::SUB_AGENT_MODEL,
                maxTokens: 4096,
            );

            return [
                'success' => true,
                'event' => $eventMeta,
                'entries_count' => $entriesCount,
                'analysis' => $analysis,
                '_note' => 'Transcript was analyzed by sub-agent due to large size. Ask for specific details if needed.',
            ];
        } catch (\Exception $e) {
            Log::error('GetTranscriptTool sub-agent failed', [
                'event_id' => $eventMeta['id'],
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to analyze transcript: ' . $e->getMessage(),
                'event' => $eventMeta,
                'entries_count' => $entriesCount,
            ];
        }
    }
}
