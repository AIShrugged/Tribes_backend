<?php

namespace Database\Seeders;

use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the meeting-prep-monitor agent profile and a default agent task for each team.
 *
 * Run: php artisan db:seed --class=MeetingPrepMonitorSeeder
 */
class MeetingPrepMonitorSeeder extends Seeder
{
    public function run(): void
    {
        // Reset sequence in case it was desynced by manual inserts
        \Illuminate\Support\Facades\DB::statement(
            "SELECT setval('agent_tasks_id_seq', COALESCE((SELECT MAX(id) FROM agent_tasks), 0) + 1, false)"
        );

        $profile = $this->upsertProfile();
        $this->createTasksForTeams($profile);
    }

    private function upsertProfile(): AgentProfile
    {
        $systemPrompt = <<<'PROMPT'
Ты проактивный менеджер команды. Твоя задача — контролировать выполнение задач между митингами и своевременно информировать участников.

## Алгоритм работы

1. Получи список членов команды (get_team_members).
2. Найди ближайший предстоящий митинг команды (search_meetings).
3. Получи все открытые задачи команды (get_open_issues).
4. Для каждой задачи прими решение:
   - Если митинг через ≤ 48 часов И задача в статусе open/in_progress → напомни исполнителю через Telegram (send_user_message).
   - Если задача не обновлялась ≥ 7 дней → напомни исполнителю независимо от митинга.
   - Если задача в статусе done → уведоми owner_user_id о завершении (get_user_info для получения имени).
5. Перед каждой отправкой проверь agent memories (search_agent_memories) — не отправляй то же самое напоминание дважды за сутки. После отправки сохрани факт (update_memory).

## Формат напоминания (Telegram)

Напоминания должны быть краткими и конкретными:
- «[Tribes] Завтра митинг. У тебя открыта задача: {название}. Как дела с ней?»
- «[Tribes] Задача "{название}" не обновлялась уже {N} дней. Нужна помощь?»

## Важно

- Работай только с задачами той команды, для которой запущен (team_id из input_payload).
- Не придумывай задачи. Если задач нет — просто ничего не делай.
- Не отправляй общий отчёт всей команде — только персональные сообщения исполнителям.
PROMPT;

        $attributes = [
            'name'                => 'Meeting Prep Monitor',
            'description'         => 'Проактивный контроль задач между митингами — напоминания о дедлайнах и зависших задачах',
            'system_prompt'       => $systemPrompt,
            'execution_mode'      => 'inline',
            'enabled'             => true,
            'allowed_tools'       => [
                'get_team_members',
                'get_open_issues',
                'search_meetings',
                'get_user_info',
                'send_user_message',
                'search_agent_memories',
                'update_memory',
            ],
            'task_payload_schema' => json_encode([
                'type'       => 'object',
                'required'   => ['team_id'],
                'properties' => [
                    'team_id'         => ['type' => 'integer'],
                    'organization_id' => ['type' => 'integer'],
                ],
            ]),
        ];

        $profile = AgentProfile::updateOrCreate(['key' => 'meeting-prep-monitor'], $attributes);

        $action = $profile->wasRecentlyCreated ? 'Created' : 'Updated';
        $this->command->info("{$action} agent_profile 'meeting-prep-monitor' (id={$profile->id})");

        return $profile;
    }

    private function createTasksForTeams(AgentProfile $profile): void
    {
        $teams = Team::limit(50)->get();

        if ($teams->isEmpty()) {
            $this->command->warn('No teams found — skipping agent_task creation.');
            return;
        }

        $owner = User::orderBy('id')->first();
        if (! $owner) {
            $this->command->warn('No users found — skipping agent_task creation.');
            return;
        }

        $prompt = 'Monitor open tasks for the team and send proactive reminders to assignees '
            . 'before meetings and when tasks are stale. Follow the system prompt instructions.';

        foreach ($teams as $team) {
            $exists = AgentTask::where('agent_profile_id', $profile->id)
                ->where('team_id', $team->id)
                ->exists();

            if ($exists) {
                $this->command->line("  Skipping team '{$team->name}' — task already exists");
                continue;
            }

            $task = AgentTask::create([
                'user_id'               => $owner->id,
                'name'                  => "Meeting Prep Monitor: {$team->name}",
                'prompt'                => $prompt,
                'agent_profile_id'      => $profile->id,
                'schedule_type'         => 'interval',
                'interval_seconds'      => 21600,
                'execution_mode'        => 'inline',
                'agent_task_type'       => 'background',
                'output_mode'           => 'plain',
                'enabled'               => true,
                'next_run_at'           => now(),
                'team_id'               => $team->id,
                'organization_id'       => $team->organization_id,
                'allowed_tools'         => [],
                'allowed_outbound_hosts' => [],
                'max_attempts'          => 3,
                'input_payload'         => array_filter([
                    'team_id'         => $team->id,
                    'organization_id' => $team->organization_id,
                ]),
            ]);

            $this->command->info("  Created agent_task id={$task->id} for team '{$team->name}'");
        }
    }
}
