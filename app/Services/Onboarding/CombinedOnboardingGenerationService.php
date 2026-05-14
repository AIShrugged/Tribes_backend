<?php

namespace App\Services\Onboarding;

use App\Exceptions\AppException;
use App\Models\Organization;
use App\Models\Participant;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class CombinedOnboardingGenerationService extends OnboardingLlmBase
{
    /**
     * Generate org structure, goals with tasks, and team map in one LLM call.
     *
     * @param  array{description?: string|null, upload_token?: string|null, links?: string[]}  $payload
     * @return array{
     *   organization: array{name: string, description: string},
     *   goals: list<array{title: string, description: string, tasks: list<array{title: string, description: string, type: string, priority: int}>}>,
     *   team: list<array{name: string, email: string|null, role: string|null, found_in: string[], already_in_system: bool, system_user_id: int|null}>
     * }
     */
    public function generate(Organization $org, array $payload, int $userId): array
    {
        $description  = $payload['description'] ?? null;
        $uploadToken  = $payload['upload_token'] ?? null;
        $links        = $payload['links'] ?? [];
        $template     = $payload['template'] ?? null;

        $fileTexts     = $this->readUploadedFiles($uploadToken, $userId, $org->id);
        $participants  = $this->loadParticipantNames($org);
        $existingUsers = $org->users()->select(['users.id', 'users.name', 'users.email'])->get();

        $model = config('ai.providers.openrouter.models.onboarding');

        if (!empty($links)) {
            $raw = $this->runWithBrowsing($org, $description, $fileTexts, $participants, $existingUsers, $links, $model, $template);
        } else {
            $messages = $this->buildMessages($org, $description, $fileTexts, $participants, $existingUsers, $template);
            $raw      = OpenRouterClient::chat($messages, $model, 8192, true);
        }

        $result = $this->parseResponse($org, $raw);

        if ($result['needs_more_info'] ?? false) {
            return $result;
        }

        return $this->enrichTeamWithSystemUsers($result, $existingUsers);
    }

    // -------------------------------------------------------------------------

    private function runWithBrowsing(
        Organization $org,
        ?string $description,
        string $fileTexts,
        string $participants,
        \Illuminate\Support\Collection $existingUsers,
        array $links,
        string $model,
        ?string $template,
    ): string {
        $messages = $this->buildBrowsingMessages($org, $description, $fileTexts, $participants, $existingUsers, $links, $template);
        $tool     = $this->fetchUrlToolDefinition();

        for ($i = 0; $i < self::MAX_BROWSE_ITERATIONS; $i++) {
            $response = OpenRouterClient::chatWithTools($messages, [$tool], $model, 8192);
            $message  = $response['choices'][0]['message'];

            $messages[] = $message;

            if (empty($message['tool_calls'])) {
                return $this->extractTextContent($message['content'] ?? '');
            }

            foreach ($message['tool_calls'] as $toolCall) {
                $args    = json_decode($toolCall['function']['arguments'] ?? '{}', true);
                $url     = $args['url'] ?? '';
                $content = $this->isSafeUrl($url)
                    ? $this->fetchSingleUrl($url)
                    : 'Error: URL is not allowed (private network or invalid scheme)';

                Log::info('Onboarding browse', [
                    'iteration' => $i,
                    'url'       => $url,
                    'chars'     => strlen($content),
                ]);

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content'      => $content,
                ];
            }
        }

        throw new AppException(
            'Не удалось завершить анализ ссылок за отведённое количество шагов',
            'ONBOARDING_GENERATION_FAILED',
            422,
        );
    }

    // -------------------------------------------------------------------------

    private function buildMessages(
        Organization $org,
        ?string $description,
        string $fileTexts,
        string $participants,
        \Illuminate\Support\Collection $existingUsers,
        ?string $template,
    ): array {
        $system = $this->systemPrompt();

        $userParts   = $this->buildCommonParts($org, $description, $fileTexts, $participants, $existingUsers, $template);
        $userParts[] = $this->taskSection();

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => implode("\n\n", $userParts)],
        ];
    }

    private function buildBrowsingMessages(
        Organization $org,
        ?string $description,
        string $fileTexts,
        string $participants,
        \Illuminate\Support\Collection $existingUsers,
        array $links,
        ?string $template,
    ): array {
        $system = $this->systemPromptBrowsing();

        $userParts   = $this->buildCommonParts($org, $description, $fileTexts, $participants, $existingUsers, $template);
        $linkList    = implode("\n", array_map(fn($l) => "- {$l}", array_slice($links, 0, 5)));
        $userParts[] = "## Links to investigate:\n{$linkList}";
        $userParts[] = $this->taskSectionBrowsing();

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => implode("\n\n", $userParts)],
        ];
    }

    private function buildCommonParts(
        Organization $org,
        ?string $description,
        string $fileTexts,
        string $participants,
        \Illuminate\Support\Collection $existingUsers,
        ?string $template,
    ): array {
        $parts   = [];
        $parts[] = "## Organization\nName: {$org->name}\nCurrent description: " . ($org->context ?? 'not set');

        if ($template !== null) {
            $hints = match ($template) {
                'IT' => 'Use software engineering terminology. Goals should reflect product delivery, infrastructure, and technical quality. Task types: "development" for coding/technical work, "organization" for process/management work.',
                default => '',
            };
            if ($hints !== '') {
                $parts[] = "## Domain template: {$template}\n{$hints}";
            }
        }

        if ($description) {
            $parts[] = "## Description from the product owner:\n{$description}";
        }

        if ($fileTexts !== '') {
            $parts[] = "## Uploaded documents:\n{$fileTexts}";
        }

        if ($participants !== '') {
            $parts[] = "## Meeting participants (from transcripts):\n{$participants}";
        }

        if ($existingUsers->isNotEmpty()) {
            $userList = $existingUsers->map(fn($u) => "- ID:{$u->id} {$u->name} <{$u->email}>")->join("\n");
            $parts[]  = "## Existing team members already in the system:\n{$userList}";
        }

        return $parts;
    }

    // -------------------------------------------------------------------------

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a business analyst and product planning expert.
Analyze all provided information to produce a comprehensive organization setup plan.

IMPORTANT: Do NOT invent or fabricate details. If the provided description is too vague to produce a meaningful
and specific organization plan (e.g. just "we are a startup doing AI" with no further context), you MUST
respond with the needs_more_info format instead of guessing.

Respond with strict valid JSON only — no extra commentary.
PROMPT;
    }

    private function systemPromptBrowsing(): string
    {
        return <<<'PROMPT'
You are a business analyst and product planning expert with web browsing capability.
Use the fetch_url tool to gather information from the provided links before writing your analysis.
For repositories, explore sub-pages: contributor lists, commit history, README, open/closed issues, milestones.
For GitHub repositories, also try the GitHub REST API (https://api.github.com/repos/{owner}/{repo}/contributors, /commits, /issues, /readme).

IMPORTANT: Do NOT invent or fabricate details. If after browsing all links and reading all documents the
information is still too vague to produce a meaningful organization plan, respond with the needs_more_info format.

Your final response must be strict valid JSON only — no extra commentary.
PROMPT;
    }

    private function taskSection(): string
    {
        return $this->jsonTaskInstructions();
    }

    private function taskSectionBrowsing(): string
    {
        return <<<'TASK'
## Your task
Use fetch_url to explore the provided links and gather:
- Team members: names, emails (look in commits and contributor pages)
- Repository state: last commit date, open issues count, primary language
- Completed work: closed issues, merged PRs, finished milestones
- Technologies and architecture

Then produce the JSON described below.

TASK
            . $this->jsonTaskInstructions();
    }

    private function jsonTaskInstructions(): string
    {
        return <<<'TASK'
## Task
Based on all provided information:
1. Write a concise organization description (2–4 sentences, professional tone).
   You may refine the name if the context clearly implies a more accurate one.
2. Identify 3–7 strategic goals (epics). For each goal, decompose it into 3–10 concrete tasks.
   Each task has a type: "development" (coding/technical) or "organization" (process/non-technical).
3. Map the team: list every person you can identify from documents, links, commits, or transcripts.
   For each person, note where they were found and whether they match an existing system member.
   The "role" field must be one of: "manager" or "employee". Use "manager" only for owners, leads,
   and decision-makers; use "employee" for everyone else.

## IMPORTANT — insufficient information
If the information provided is too vague or generic to generate a specific and meaningful plan
(for example: only "we are a startup, we do AI" with nothing else), do NOT invent content.
Instead respond with this JSON:
{
  "needs_more_info": true,
  "message": "A short explanation of why more detail is needed (in the language the user wrote in)",
  "questions": [
    "Specific question 1 that would unlock the analysis",
    "Specific question 2",
    "..."
  ]
}
Include 2–5 targeted questions that, once answered, would provide enough context to proceed.

## Response format when information IS sufficient (strict JSON, no extra text):
{
  "organization": { "name": "...", "description": "..." },
  "goals": [
    {
      "title": "<verb + outcome>",
      "description": "1–3 sentences",
      "tasks": [
        { "title": "...", "description": "...", "type": "development|organization", "priority": 0 }
      ]
    }
  ],
  "team": [
    {
      "name": "...",
      "email": "user@example.com or null if unknown",
      "role": "manager|employee",
      "found_in": ["github", "documents", "transcripts"]
    }
  ]
}
IMPORTANT: if email is unknown, set it to JSON null — never use "N/A", "n/a", "unknown", or empty string.
TASK;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{needs_more_info: true, message: string, questions: string[]}
     *       | array{organization: array{name: string, description: string}, goals: list<array{}>, team: list<array{}>}
     */
    private function parseResponse(Organization $org, string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            preg_match('/\{[\s\S]*\}/s', $json, $matches);
            $data = json_decode($matches[0] ?? '{}', true);
        }

        if (($data['needs_more_info'] ?? false) === true) {
            return [
                'needs_more_info' => true,
                'message'         => (string) ($data['message'] ?? ''),
                'questions'       => array_values(array_filter(
                    array_map('strval', (array) ($data['questions'] ?? [])),
                    fn($q) => $q !== '',
                )),
            ];
        }

        if (!isset($data['organization']['name'], $data['goals']) || !is_array($data['goals'])) {
            throw new AppException(
                'Некорректный ответ ИИ при генерации структуры организации',
                'ONBOARDING_GENERATION_FAILED',
                422,
            );
        }

        $goals = array_values(array_filter(
            array_map(fn($g) => [
                'title'       => (string) ($g['title'] ?? ''),
                'description' => (string) ($g['description'] ?? ''),
                'tasks'       => $this->normalizeTasks($g['tasks'] ?? []),
            ], $data['goals']),
            fn($g) => $g['title'] !== '',
        ));

        $validRoles    = ['manager', 'employee'];
        $nullishEmails = ['n/a', 'na', 'null', 'none', 'unknown', ''];

        $team = array_values(array_filter(
            array_map(fn($m) => [
                'name'     => (string) ($m['name'] ?? ''),
                'email'    => $this->normalizeEmail($m['email'] ?? null, $nullishEmails),
                'role'     => in_array($m['role'] ?? '', $validRoles, true) ? $m['role'] : 'employee',
                'found_in' => is_array($m['found_in'] ?? null) ? $m['found_in'] : [],
            ], $data['team'] ?? []),
            fn($m) => $m['name'] !== '',
        ));

        return [
            'organization' => [
                'name'        => (string) ($data['organization']['name'] ?? $org->name),
                'description' => (string) ($data['organization']['description'] ?? ''),
            ],
            'goals' => $goals,
            'team'  => $team,
        ];
    }

    private function normalizeTasks(mixed $tasks): array
    {
        if (!is_array($tasks)) {
            return [];
        }

        $allowed = ['development', 'organization'];

        return array_values(array_filter(
            array_map(fn($t) => [
                'title'       => (string) ($t['title'] ?? ''),
                'description' => (string) ($t['description'] ?? ''),
                'type'        => in_array($t['type'] ?? '', $allowed, true) ? $t['type'] : 'development',
                'priority'    => (int) ($t['priority'] ?? 0),
            ], $tasks),
            fn($t) => $t['title'] !== '',
        ));
    }

    /**
     * Cross-reference team members against existing org users by email, then by name.
     */
    private function enrichTeamWithSystemUsers(array $result, \Illuminate\Support\Collection $existingUsers): array
    {
        $byEmail = $existingUsers->keyBy(fn($u) => strtolower($u->email));

        $result['team'] = array_map(function (array $member) use ($byEmail, $existingUsers) {
            $match = null;

            if ($member['email']) {
                $match = $byEmail->get(strtolower($member['email']));
            }

            if (!$match) {
                $name  = strtolower($member['name']);
                $match = $existingUsers->first(fn($u) => strtolower($u->name) === $name);
            }

            return array_merge($member, [
                'already_in_system' => $match !== null,
                'system_user_id'    => $match?->id,
            ]);
        }, $result['team']);

        return $result;
    }

    private function normalizeEmail(mixed $value, array $nullish): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = strtolower(trim((string) $value));

        if (in_array($str, $nullish, true) || !filter_var($str, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $str;
    }

    private function loadParticipantNames(Organization $org): string
    {
        $teamIds = $org->teams()->pluck('id');

        if ($teamIds->isEmpty()) {
            return '';
        }

        $eventIds = \Illuminate\Support\Facades\DB::table('followups')
            ->whereIn('team_id', $teamIds)
            ->whereNotNull('calendar_event_id')
            ->pluck('calendar_event_id');

        if ($eventIds->isEmpty()) {
            return '';
        }

        $names = Participant::whereIn('calendar_event_id', $eventIds)
            ->distinct('name')
            ->pluck('name')
            ->filter()
            ->take(50);

        return $names->join(', ');
    }
}
