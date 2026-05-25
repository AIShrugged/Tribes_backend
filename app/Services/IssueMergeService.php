<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Decisions\DecisionAuthorResolver;
use App\Support\NameNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IssueMergeService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly IssueTypeResolver $issueTypeResolver,
        private readonly DecisionAuthorResolver $authorResolver,
    ) {}

    /**
     * Persist extracted items, deduplicating against existing open issues.
     *
     * @param  array<int, array>  $items  Raw items from Pass 1 LLM response
     * @return Collection<int, Issue>
     */
    public function persist(array $items, CalendarEvent $event, Team $team, User $user): Collection
    {
        if (empty($items)) {
            return collect();
        }

        // Load FULL models (no select(): Issue::saving hook calls IssueTypeResolver on EVERY
        // save and uses the model's `type` + `organization_id` + `issue_type_id`. A partial-
        // select hydration would leave those null, the resolver would fall back to default,
        // and the saving hook would silently overwrite type to 'development' on update —
        // demoting epics among others.
        $existingIssues = Issue::query()
            ->where('team_id', $team->id)
            ->where('status', '!=', MeetingTaskStatus::DONE->value)
            ->get();

        if ($existingIssues->isEmpty()) {
            return $this->createAll($items, $event, $team, $user);
        }

        $decisions = $this->getDecisions($items, $existingIssues, $event);

        if ($decisions === null) {
            Log::warning('Issue merge fallback: creating all items without deduplication', [
                'calendar_event_id' => $event->id,
                'team_id' => $team->id,
            ]);

            return $this->createAll($items, $event, $team, $user);
        }

        return $this->applyDecisions($decisions, $items, $existingIssues, $event, $team, $user);
    }

    private function getDecisions(array $items, Collection $existingIssues, CalendarEvent $event): ?array
    {
        $existingList = $existingIssues->map(fn (Issue $issue) => [
            'id'                  => $issue->id,
            'name'                => $issue->name,
            'description_excerpt' => mb_substr(strip_tags($issue->description ?? ''), 0, 300),
        ])->values()->all();

        $newList = array_map(fn (int $i, array $item) => [
            'index'       => $i,
            'name'        => $item['name'],
            'description' => $item['description'] ?? '',
        ], array_keys($items), $items);

        $userMessage = json_encode([
            'meeting_title' => $event->title,
            'meeting_date'  => $event->starts_at->toDateString(),
            'new_issues'    => array_values($newList),
            'existing_issues' => $existingList,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $json = $this->llm->chat(
                messages: [
                    new MessageDTO('system', $this->buildMergeSystemPrompt()),
                    new MessageDTO('user', $userMessage),
                ],
                model: Setting::get('model.followup', config('ai.providers.openrouter.models.followup')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            if (is_string($json) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                $json = $matches[0];
            }

            $decoded = is_string($json) ? json_decode($json, true) : $json;

            return $decoded['decisions'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Issue merge LLM call failed', [
                'calendar_event_id' => $event->id,
                'error'             => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function applyDecisions(
        array $decisions,
        array $items,
        Collection $existingIssues,
        CalendarEvent $event,
        Team $team,
        User $user
    ): Collection {
        return DB::transaction(function () use ($decisions, $items, $existingIssues, $event, $team, $user) {
            $result = collect();
            $existingById = $existingIssues->keyBy('id');
            $processedIndexes = [];

            foreach ($decisions as $decision) {
                $index = $decision['index'] ?? null;
                $action = $decision['action'] ?? 'create';

                if ($index === null || !isset($items[$index])) {
                    continue;
                }

                $processedIndexes[] = $index;

                if ($action === 'skip') {
                    continue;
                }

                if ($action === 'update' && !empty($decision['existing_issue_id'])) {
                    $existing = $existingById->get($decision['existing_issue_id']);

                    if (!$existing) {
                        $result->push($this->createIssue($items[$index], $event, $team, $user));
                        continue;
                    }

                    $result->push($this->updateIssue($existing, $decision, $event, $team, $user));
                    continue;
                }

                $result->push($this->createIssue($items[$index], $event, $team, $user));
            }

            // Items the LLM skipped entirely — create them to avoid silent data loss
            foreach (array_keys($items) as $index) {
                if (!in_array($index, $processedIndexes, strict: true)) {
                    $result->push($this->createIssue($items[$index], $event, $team, $user));
                }
            }

            return $result;
        });
    }

    private function updateIssue(Issue $existing, array $decision, CalendarEvent $event, Team $team, User $user): Issue
    {
        $updates = [];

        if (!empty($decision['assignee_name'])) {
            $updates['assignee_name'] = $decision['assignee_name'];
            $updates['assignee_id'] = $this->resolveAssigneeId($decision['assignee_name'], $event, $team);
        }

        if (!empty($decision['due_date'])) {
            $parsed = $this->parseDueDate($decision['due_date']);
            if ($parsed !== null) {
                $updates['due_date'] = $parsed;
            }
        }

        if (!empty($decision['priority'])) {
            $updates['priority'] = $this->mapPriority($decision['priority']);
        }

        if (!empty($updates)) {
            $existing->update($updates);
        }

        $updateText  = $decision['update_description'] ?? '';
        $dateStr     = $event->starts_at->toDateString();
        $commentAuthorId = $this->resolveCommentAuthorUserId($decision['author_name'] ?? null, $event, $user);

        IssueComment::create([
            'issue_id'          => $existing->id,
            'user_id'           => $commentAuthorId,
            'parent_id'         => null,
            'calendar_event_id' => $event->id,
            'content'           => "**Обновление по встрече \"{$event->title}\" от {$dateStr}:**\n\n{$updateText}",
        ]);

        Log::info('Issue updated via merge', [
            'issue_id'          => $existing->id,
            'calendar_event_id' => $event->id,
        ]);

        return $existing->fresh();
    }

    /**
     * Resolve a comment author. Always returns a user id — falls back to the caller user
     * when the LLM didn't emit an author or the resolver couldn't match the name (US-6.8:
     * comment.author_id must be populated, never null).
     */
    private function resolveCommentAuthorUserId(?string $authorName, CalendarEvent $event, User $fallback): int
    {
        if (!blank($authorName)) {
            $resolved = $this->authorResolver->resolve($event, $authorName)['user_id'] ?? null;
            if ($resolved) {
                return $resolved;
            }
        }

        return $fallback->id;
    }

    private function createAll(array $items, CalendarEvent $event, Team $team, User $user): Collection
    {
        return collect($items)->map(fn (array $item) => $this->createIssue($item, $event, $team, $user));
    }

    private function createIssue(array $item, CalendarEvent $event, Team $team, User $user): Issue
    {
        $assigneeName = $item['assignee_name'] ?? null;
        $authorName   = $item['author_name'] ?? null;

        return Issue::create([
            'user_id'         => $this->resolveAuthorUserId($authorName, $event, $user),
            'organization_id' => $team->organization_id,
            'team_id'         => $team->id,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id'   => $event->id,
            'name'            => trim($item['name'] ?? ''),
            'description'     => $item['description'] ?? null,
            'type'            => $this->issueTypeResolver->resolve(
                $team->organization_id,
                $team->id,
                $item['type'] ?? null
            )?->key ?? Issue::TYPE_DEVELOPMENT,
            'status'          => MeetingTaskStatus::OPEN->value,
            'assignee_name'   => $assigneeName,
            'assignee_id'     => $this->resolveAssigneeId($assigneeName, $event, $team),
            'due_date'        => $this->parseDueDate($item['due_date'] ?? null),
            'priority'        => $this->mapPriority($item['priority'] ?? null),
        ]);
    }

    private function resolveAuthorUserId(?string $authorName, CalendarEvent $event, User $fallback): int
    {
        if (blank($authorName)) {
            return $fallback->id;
        }

        $resolved = $this->authorResolver->resolve($event, $authorName);

        return $resolved['user_id'] ?? $fallback->id;
    }

    private function resolveAssigneeId(?string $assigneeName, CalendarEvent $event, Team $team): ?int
    {
        if (blank($assigneeName)) {
            return null;
        }

        // Pass 1: event profiles (most precise — only matched participants)
        $event->loadMissing('profiles.user');
        $user = $event->profiles
            ->map(fn ($profile) => $profile->user)
            ->filter()
            ->first(fn (User $user) => $this->nameMatches($user->name, $assigneeName));

        if ($user) {
            return $user->id;
        }

        // Pass 2: all team members by name (covers participants whose profiles have no user_id)
        return $team->users()
            ->get(['users.id', 'users.name'])
            ->first(fn (User $user) => $this->nameMatches($user->name, $assigneeName))
            ?->id;
    }

    private function nameMatches(string $userName, string $needle): bool
    {
        $haystack = NameNormalizer::normalize($userName);
        $needle = NameNormalizer::normalize($needle);

        return $haystack === $needle
            || str_contains($haystack, $needle)
            || str_contains($needle, $haystack);
    }

    private function mapPriority(?string $value): int
    {
        return match (strtolower(trim((string) $value))) {
            'critical' => Issue::PRIORITY_CRITICAL,
            'high'     => Issue::PRIORITY_HIGH,
            'low'      => Issue::PRIORITY_LOW,
            'minimal'  => Issue::PRIORITY_MINIMAL,
            default    => Issue::PRIORITY_NORMAL,
        };
    }

    private function parseDueDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildMergeSystemPrompt(): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'issue.merge.system',
            organizationId: null,
            fallbackView: 'llm-prompts.issue.merge-system',
            name: 'Issue merge system prompt',
        );
    }
}
