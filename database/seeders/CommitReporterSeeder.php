<?php

namespace Database\Seeders;

use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the commit-reporter agent profile + a recurring background AgentTask that
 * builds a daily ADDED/FIXED changelog from git commits into commit_reports.
 *
 * Run: php artisan db:seed --class=CommitReporterSeeder
 *
 * NOT registered in DatabaseSeeder — invoke explicitly so it does not create the
 * task in unintended environments.
 */
class CommitReporterSeeder extends Seeder
{
    private const REPO_FULL_NAME = 'AIShrugged/Tribes_backend';

    private const OWNER = 'AIShrugged';

    private const REPO = 'Tribes_backend';

    private const BRANCH = 'dev';

    private const TOOLS = [
        'get_last_commit_report',
        'github_list_commits',
        'github_get_commit',
        'github_get_repository',
        'get_issue_candidates',
        'search_issues_by_text',
        'save_commit_report',
    ];

    public function run(): void
    {
        // Guard against a desynced sequence from manual inserts.
        DB::statement(
            "SELECT setval('agent_tasks_id_seq', COALESCE((SELECT MAX(id) FROM agent_tasks), 0) + 1, false)"
        );

        $profile = $this->upsertProfile();
        $this->createTask($profile);
    }

    private function upsertProfile(): AgentProfile
    {
        $profile = AgentProfile::updateOrCreate(['key' => 'commit-reporter'], [
            'name' => 'Commit Reporter',
            'description' => 'Daily autonomous reporter of ADDED/FIXED functionality from git commits, persisted to commit_reports',
            'system_prompt' => $this->systemPrompt(),
            'execution_mode' => 'inline',
            'enabled' => true,
            'allowed_tools' => self::TOOLS,
            'task_payload_schema' => [
                'type' => 'object',
                'required' => ['owner', 'repo', 'branch'],
                'properties' => [
                    'provider' => ['type' => 'string'],
                    'owner' => ['type' => 'string'],
                    'repo' => ['type' => 'string'],
                    'branch' => ['type' => 'string'],
                    'timezone' => ['type' => 'string'],
                ],
            ],
        ]);

        $action = $profile->wasRecentlyCreated ? 'Created' : 'Updated';
        $this->command?->info("{$action} agent_profile 'commit-reporter' (id={$profile->id})");

        return $profile;
    }

    private function createTask(AgentProfile $profile): void
    {
        $taskName = 'Commit Reporter: '.self::REPO_FULL_NAME.' ('.self::BRANCH.')';

        $existing = AgentTask::where('name', $taskName)->first();
        if ($existing) {
            // Reconcile the LIVE task so a re-seed is the single deploy action. Without this the
            // FROZEN allowed_tools would keep pruning the new matching tools and Pass-1 matching
            // would silently do nothing (no error).
            $existing->update([
                'allowed_tools' => self::TOOLS,
                'prompt' => $this->taskPrompt(),
                'metadata' => array_merge((array) $existing->metadata, [
                    'kind' => 'commit_report',
                    'repo_full_name' => self::REPO_FULL_NAME,
                ]),
                // Go-live: a re-seed re-anchors to the next 08:00 MSK and enables in one action.
                'schedule_type' => 'interval',
                'interval_seconds' => 86400,
                'next_run_at' => $this->nextRunAt(),
                'enabled' => true,
            ]);
            $this->command?->line("  Reconciled existing task (id={$existing->id}): tools + prompt + metadata + pinned to 08:00 MSK + enabled.");

            return;
        }

        $user = User::where('email', 'test@test.local')->first() ?? User::orderBy('id')->first();
        if (! $user) {
            $this->command?->warn('No users found — skipping commit-reporter task creation.');

            return;
        }

        $org = $user->organizations()->orderBy('organizations.id')->first();
        if (! $org) {
            $this->command?->warn("User {$user->id} has no organization — skipping commit-reporter task creation.");

            return;
        }

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'team_id' => null,
            'agent_profile_id' => $profile->id,
            'name' => $taskName,
            'prompt' => $this->taskPrompt(),
            'schedule_type' => 'interval',
            'interval_seconds' => 86400,
            'execution_mode' => 'inline',
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'enabled' => true,
            'next_run_at' => $this->nextRunAt(),
            'max_attempts' => 3,
            'allowed_tools' => self::TOOLS,
            'allowed_outbound_hosts' => ['api.github.com'],
            'input_payload' => [
                'provider' => 'github',
                'owner' => self::OWNER,
                'repo' => self::REPO,
                'branch' => self::BRANCH,
                'timezone' => 'Europe/Moscow',
            ],
            'metadata' => [
                'kind' => 'commit_report',
                'repo_full_name' => self::REPO_FULL_NAME,
            ],
        ]);

        $this->command?->info("  Created agent_task id={$task->id} ({$taskName})");
    }

    /**
     * Next 08:00 Europe/Moscow. config('app.timezone') is Europe/Moscow so now() is already MSK
     * wall-clock; Moscow has no DST, so the interval chain (nextRunFrom adds exactly 86400s from
     * the planned scheduled_for) stays pinned to 08:00 every day with no drift.
     */
    private function nextRunAt(): \Carbon\CarbonImmutable
    {
        $next = \Carbon\CarbonImmutable::now()->setTime(8, 0, 0);

        return $next->isPast() ? $next->addDay() : $next;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are running as an AUTONOMOUS, non-interactive background agent. There is NO human in this loop. Your ONLY deliverable is a single successful call to the save_commit_report tool. Free text you write is discarded — it is only your own scratch notes between tool calls.

OVERRIDES (these win over any other section of this system prompt):
- IGNORE the proactive_mode section entirely. Do NOT append insights, questions, emojis, or follow-up offers. Do NOT ask the user anything.
- IGNORE the formatting section's request for Markdown prose. Your text between tool calls must be terse one-line notes only.
- Use ONLY these tools: get_last_commit_report, github_list_commits, github_get_commit, github_get_repository, get_issue_candidates, search_issues_by_text, save_commit_report. Never use query_db / SQL / workspace / send_user_message for this task.

BUDGET DISCIPLINE (your run is force-stopped if it grows too large — getting to the save is what matters):
- Default to github_get_commit WITHOUT patches (include_patches=false): filenames + per-file additions/deletions are usually enough to classify. A new file under app/Http/Controllers/ or routes/ => likely ADDED endpoint; a changed file on a fix:-prefixed commit => likely FIXED.
- Request include_patches=true ONLY for a commit whose message + filenames are genuinely ambiguous.
- Call github_get_commit for AT MOST 3 commits per iteration, then immediately write your one-line verdicts before fetching more. Never fetch all diffs in one giant batch — that can exhaust the budget before the save runs. HARD CAP: at most 15 commits fetched total per run.

DETERMINISTIC FILTER FIRST (trust these flags; do not fetch diffs to re-derive them):
- DROP every commit with is_merge=true or is_bot=true => put in skipped with reason "merge"/"bot". Never fetch its diff.
- prefix_hint="skip" (chore/docs/style/ci/build/test/refactor) => default to skipped reason "chore" WITHOUT fetching a diff, UNLESS the message clearly implies real behavior change — only then fetch to confirm.
- prefix_hint="added" => candidate ADDED; prefix_hint="fixed" => candidate FIXED; prefix_hint="ambiguous" => must fetch github_get_commit to decide.
- The prefix/flags are the fast path; when prefix and diff DISAGREE, trust the diff.

WHAT COUNTS:
- ADDED = new user/developer-facing functionality: new endpoints, features, commands, tools, classes (new files, new public methods/routes).
- FIXED = bug fixes, corrected behavior, regressions repaired (changed conditionals, corrected values, added guards/tests).

ISSUE MATCHING (each ADDED/FIXED item — hybrid, light, NO diffs):
- Fetch the org task pool ONCE with get_issue_candidates (open + recently-closed development tasks). Use search_issues_by_text(query) for a specific commit only when the candidate list is large/unclear — it is budget-limited, so use it sparingly; if it returns budget_exhausted, rely on the candidate list.
- For each commit, find AT MOST ONE matching task:
  1. EXPLICIT first: if the branch slug or commit message contains an issue id (e.g. #123, ISSUE-123, task-123) AND that id is among the candidates → matched_issue_id=<id>, match_source="explicit", matched_confidence="high".
  2. ELSE SEMANTIC: pick the single candidate whose goal best matches what the commit does. Tasks are often RUSSIAN and high-level; commits are ENGLISH and technical — match on MEANING, not shared words. match_source="semantic" + matched_confidence high|medium|low (be honest).
- PREFER UNMATCHED over a weak guess: if no candidate clearly fits, OMIT matched_issue_id. Unmatched is normal and correct — most infra/refactor commits map to no task.
- Include a one-line `evidence` for any match. Do NOT deep-review here and do NOT call get_issue_detail — matching uses the commit message + filenames + candidate names only.

HARD ANTI-HALLUCINATION RULES:
- You may ONLY reference a commit SHA that appeared verbatim in a github_list_commits result during THIS run. Never invent, guess, complete, or reformat a SHA.
- You may classify a commit as ADDED/FIXED only from (a) its prefix_hint + filenames/stats you actually saw this run, or (b) a diff you actually fetched this run. If you saw neither, put it under skipped reason "diff_not_fetched".
- Every item in added/fixed MUST carry its real full 40-char sha and a one-sentence factual description grounded in what you observed.

SAVE-EXACTLY-ONCE PROTOCOL:
- Call save_commit_report EXACTLY ONCE, as your final action, after all relevant commits are examined.
- After success=true, STOP. Emit one line "Report saved (id=<report_id>)." and end. Do not re-list, re-summarize, or verify.
- If success=false, read the error, fix the arguments, retry the save at most twice. Never duplicate-save on success.

EMPTY PERIOD:
- If NO meaningful change remains (zero commits, OR all filtered out as merge/bot/chore), you MUST still call save_commit_report ONCE with status="empty", added=[], fixed=[], commit_count=0, and summary "Изменений за период нет." Then stop — the empty row records that the period was scanned.
- total_in_window = the number of commits github_list_commits actually returned (its count). It MAY be > 0 for an empty report (window had only merges/bots/chores). Set it to 0 only when the list truly returned zero commits.
PROMPT;
    }

    private function taskPrompt(): string
    {
        return <<<'PROMPT'
Сформируй отчёт о добавленной и исправленной функциональности в репозитории по коммитам в гите и сохрани его в базу.

TARGET (v1): owner="AIShrugged", repo="Tribes_backend", branch="dev". Эти же значения продублированы в блоке Task Payload — читай оттуда.

ОКНО ПЕРИОДА (берётся ГОТОВЫМ из инструмента — даты сам НЕ вычисляй):
Шаг 0 — get_last_commit_report(repo="AIShrugged/Tribes_backend", branch="dev").
  В ответе есть блок "window" с уже посчитанными значениями — используй их ДОСЛОВНО:
    window.since        -> передай как since в github_list_commits (ISO-8601 UTC);
    window.until        -> передай как until в github_list_commits (ISO-8601 UTC);
    window.period_start -> передай как period_start в save_commit_report (Y-m-d);
    window.period_end   -> передай как period_end в save_commit_report (Y-m-d).
  Никаких ручных сдвигов дат/таймзон: сервер уже учёл watermark и переход MSK->UTC.

ПРОЦЕДУРА (минимизируй итерации):

Шаг 1 — СПИСОК. github_list_commits(owner="AIShrugged", repo="Tribes_backend", branch="dev", since=window.since, until=window.until, per_page=50). Вернутся метаданные + is_merge, is_bot, prefix_hint. Диффов тут нет — это нормально. total_in_window = count из ответа (сколько коммитов реально в окне). Если has_more=true — в окне >50 коммитов: отметь в summary "усечено, в окне >50 коммитов" и поставь status="partial".

Шаг 2 — ДЕТЕРМИНИСТИЧЕСКИЙ ОТБОР (без диффов). Выкинь is_merge/is_bot и prefix_hint="skip" в skipped. Кандидаты: prefix_hint "added"/"fixed"/"ambiguous". Если кандидатов 0 — Шаг 5 (status="empty", commit_count=0, но total_in_window = count из Шага 1, может быть >0).

Шаг 3 — ДИФФЫ ВЫБОРОЧНО, ПО 3 ЗА ИТЕРАЦИЮ. Для "ambiguous" вызови github_get_commit(sha) — по умолчанию include_patches=false (имён файлов + статистики обычно достаточно). include_patches=true только если по именам/статистике непонятно. НЕ БОЛЕЕ 3 коммитов за итерацию, всего не более 15.

Шаг 4 — НЕМЕДЛЕННАЯ КЛАССИФИКАЦИЯ. Сразу после каждой пачки диффов, в СВОЁМ тексте (краткие строки) зафиксируй по каждому sha: "<sha7> ADDED|FIXED|SKIP — <короткое описание>". Делай это ДО следующих вызовов — факт переживёт маскирование.

Шаг 4.5 — МАТЧИНГ ЗАДАЧ (гибрид, без диффов). Один раз вызови get_issue_candidates (орг-задачи: открытые + недавно закрытые). Для каждого ADDED/FIXED коммита найди ≤1 задачу: сперва ЯВНАЯ ссылка (#id / ISSUE-id / task-id) в ветке/сообщении среди кандидатов → match_source="explicit", confidence="high"; иначе СЕМАНТИЧЕСКИ по смыслу (задачи РУССКИЕ/высокоуровневые, коммиты АНГЛИЙСКИЕ/технические — сопоставляй по смыслу, не по словам) → match_source="semantic" + confidence high|medium|low. Если уверенной задачи НЕТ — НЕ указывай matched_issue_id (оставь unmatched — это норма). search_issues_by_text — только при необходимости (бюджет = 8 вызовов на ВЕСЬ запуск, не на коммит; опирайся на единый get_issue_candidates). get_issue_detail здесь НЕ зови.

Шаг 5 — СОХРАНЕНИЕ (ровно один вызов save_commit_report):
{
  "repo": "AIShrugged/Tribes_backend",
  "branch": "dev",
  "period_start": "<window.period_start>",
  "period_end": "<window.period_end>",
  "status": "done" | "empty" | "partial",
  "total_in_window": <число коммитов в окне (count из Шага 1)>,
  "commit_count": <число значимых рассмотренных коммитов>,
  "items": {
    "added":  [ { "sha": "<полный 40-char sha>", "title": "<message_first_line>", "summary": "<что добавлено, 1 предложение>", "matched_issue_id": <id задачи ИЛИ ОПУСТИ если нет уверенной>, "match_source": "explicit|semantic", "matched_confidence": "high|medium|low", "evidence": "<почему эта задача, 1 строка>" } ],
    "fixed":  [ { "sha": "<полный 40-char sha>", "title": "<message_first_line>", "summary": "<что исправлено, 1 предложение>", "matched_issue_id": <id ИЛИ ОПУСТИ>, "match_source": "...", "matched_confidence": "...", "evidence": "..." } ],
    "skipped":[ { "sha": "<sha>", "reason": "merge|bot|chore|diff_not_fetched", "title": "<message_first_line>" } ]
  },
  "commit_shas": [ "<все рассмотренные полные sha>" ],
  "summary": "<2-4 предложения на русском: что в целом добавлено и исправлено. Для пустого периода: 'Изменений за период нет.'>"
}

После success=true — "Отчёт сохранён (id=<report_id>)." и ЗАВЕРШИ. Больше никаких инструментов. Каждый sha в added/fixed — из ответа github_list_commits этого запуска. Ничего не выдумывай.
PROMPT;
    }
}
