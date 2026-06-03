<?php

namespace App\Services\Onboarding;

use App\Exceptions\AppException;
use App\Models\Organization;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use App\Support\IssueDescriptionFormatter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $existingUsers = $org->users()->select(['users.id', 'users.name', 'users.email'])->get();
        $browseEvidence = '';

        $model = config('ai.providers.openrouter.models.onboarding');

        if (!empty($links)) {
            ['raw' => $raw, 'evidence' => $browseEvidence] = $this->runWithBrowsing($org, $description, $fileTexts, $links, $model, $template);
        } else {
            $messages = $this->buildMessages($org, $description, $fileTexts, $template);
            $raw      = app(OpenRouterClient::class)->chat($messages, $model, 8192, true);
        }

        $result = $this->parseResponse($org, $raw);

        if ($result['needs_more_info'] ?? false) {
            return $result;
        }

        $teamEvidence = $this->buildTeamEvidenceText($description, $fileTexts, $browseEvidence);
        $result = $this->filterTeamByEvidence($result, $teamEvidence);
        $result = $this->normalizeTeamEmailsByEvidence($result, $teamEvidence);

        return $this->enrichTeamWithSystemUsers($result, $existingUsers);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{raw: string, evidence: string}
     */
    private function runWithBrowsing(
        Organization $org,
        ?string $description,
        string $fileTexts,
        array $links,
        string $model,
        ?string $template,
    ): array {
        $messages = $this->buildBrowsingMessages($org, $description, $fileTexts, $links, $template);
        $tool     = $this->fetchUrlToolDefinition();
        $evidence = [];

        for ($i = 0; $i < self::MAX_BROWSE_ITERATIONS; $i++) {
            $response = app(OpenRouterClient::class)->chatWithTools($messages, [$tool], $model, 8192);
            $message  = $response['choices'][0]['message'];

            $messages[] = $message;

            if (empty($message['tool_calls'])) {
                return [
                    'raw' => $this->extractTextContent($message['content'] ?? ''),
                    'evidence' => implode("\n\n", $evidence),
                ];
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

                $evidence[] = $content;

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
        ?string $template,
    ): array {
        $system = $this->systemPrompt();

        $userParts   = $this->buildCommonParts($org, $description, $fileTexts, $template);
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
        array $links,
        ?string $template,
    ): array {
        $system = $this->systemPromptBrowsing();

        $userParts   = $this->buildCommonParts($org, $description, $fileTexts, $template);
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
        ?string $template,
    ): array {
        $parts   = [];
        $parts[] = "## Organization\nName: {$org->name}";

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

        return $parts;
    }

    // -------------------------------------------------------------------------

    private function systemPrompt(): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'onboarding.combined.system',
            organizationId: null,
            fallbackView: 'llm-prompts.onboarding.combined-system',
            name: 'Combined onboarding system prompt',
        );
    }

    private function systemPromptBrowsing(): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'onboarding.combined_browsing.system',
            organizationId: null,
            fallbackView: 'llm-prompts.onboarding.combined-browsing-system',
            name: 'Combined onboarding browsing system prompt',
        );
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
   Only include a person if their name appears verbatim in the provided source material.
   Do NOT add people merely because they are listed as existing system members; that list is for matching only.
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
        {
          "title": "...",
          "description": "## Context\nWhy this task is needed in the onboarding plan.\n\n## Steps\n1. Concrete step 1\n2. Concrete step 2\n\n## Definition of done\nHow to know the task is complete.",
          "type": "development|organization",
          "priority": 0
        }
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
IMPORTANT: every task description must contain exactly these markdown sections: "## Context", "## Steps", and "## Definition of done".
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
                'tasks'       => $this->normalizeTasks(
                    $g['tasks'] ?? [],
                    (string) ($g['title'] ?? ''),
                    (string) ($g['description'] ?? ''),
                ),
            ], $data['goals']),
            fn($g) => $g['title'] !== '',
        ));

        $validRoles    = ['manager', 'employee'];
        $nullishEmails = ['n/a', 'na', 'null', 'none', 'unknown', ''];

        $team = array_values(array_filter(
            array_map(function ($m) use ($nullishEmails, $validRoles) {
                $name = (string) ($m['name'] ?? '');

                return [
                    'name'     => $name,
                    'email'    => $this->normalizeEmail($m['email'] ?? null, $nullishEmails),
                    'role'     => in_array($m['role'] ?? '', $validRoles, true) ? $m['role'] : 'employee',
                    'found_in' => is_array($m['found_in'] ?? null) ? $m['found_in'] : [],
                ];
            }, $data['team'] ?? []),
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

    private function normalizeTasks(mixed $tasks, string $goalTitle = '', ?string $goalDescription = null): array
    {
        if (!is_array($tasks)) {
            return [];
        }

        $allowed = ['development', 'organization'];

        return array_values(array_filter(
            array_map(fn($t) => [
                'title'       => (string) ($t['title'] ?? ''),
                'description' => IssueDescriptionFormatter::onboardingTask(
                    (string) ($t['description'] ?? ''),
                    $goalTitle,
                    $goalDescription,
                ),
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

    private function filterTeamByEvidence(array $result, string $evidence): array
    {
        $normalizedEvidence = $this->normalizeEvidenceText($evidence);

        $result['team'] = array_values(array_filter(
            $result['team'] ?? [],
            fn(array $member): bool => $this->nameAppearsInEvidence((string) ($member['name'] ?? ''), $normalizedEvidence),
        ));

        return $result;
    }

    private function normalizeTeamEmailsByEvidence(array $result, string $evidence): array
    {
        $normalizedEvidence = $this->normalizeEvidenceText($evidence);

        $result['team'] = array_map(function (array $member) use ($normalizedEvidence): array {
            $email = $member['email'] ?? null;

            if (is_string($email) && $email !== '' && $this->emailAppearsInEvidence($email, $normalizedEvidence)) {
                return $member;
            }

            return array_merge($member, [
                'email' => $this->fallbackEmailForName((string) ($member['name'] ?? '')),
            ]);
        }, $result['team'] ?? []);

        return $result;
    }

    private function buildTeamEvidenceText(?string $description, string $fileTexts, string $browseEvidence): string
    {
        return implode("\n\n", array_filter([
            $description,
            $fileTexts,
            $browseEvidence,
        ], fn($part): bool => is_string($part) && trim($part) !== ''));
    }

    private function nameAppearsInEvidence(string $name, string $normalizedEvidence): bool
    {
        $normalizedName = $this->normalizeEvidenceText($name);

        if ($normalizedName === '' || $normalizedEvidence === '') {
            return false;
        }

        $pattern = '/(?<![\pL\pN])' . preg_quote($normalizedName, '/') . '(?![\pL\pN])/u';

        return preg_match($pattern, $normalizedEvidence) === 1;
    }

    private function emailAppearsInEvidence(string $email, string $normalizedEvidence): bool
    {
        $normalizedEmail = $this->normalizeEvidenceText($email);

        return $normalizedEmail !== '' && str_contains($normalizedEvidence, $normalizedEmail);
    }

    private function normalizeEvidenceText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
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

    private function fallbackEmailForName(string $name): string
    {
        $username = Str::slug($name, '.');

        if ($username === '') {
            $username = 'user';
        }

        return "{$username}@shrugged.ai";
    }

}
