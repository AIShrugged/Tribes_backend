<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class AgentActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'chat_id',
        'agent_run_uuid',
        'agent_task_run_id',
        'tool_name',
        'description',
        'success',
        'tool_args',
        'tool_result',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'success'     => 'boolean',
            'tool_args'   => 'array',
            'tool_result' => 'array',
            'created_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function agentTaskRun(): BelongsTo
    {
        return $this->belongsTo(AgentTaskRun::class);
    }

    /**
     * Generate a human-readable description from tool name and result.
     */
    public static function descriptionFor(string $toolName, mixed $result): string
    {
        $descriptions = [
            'agent_run_started' => 'Начал обработку запроса',
            'agent_run_completed' => 'Завершил обработку запроса',
            'create_artifact'          => 'Создал артефакт',
            'update_artifact'          => 'Обновил артефакт',
            'save_methodology'         => 'Сохранил методологию',
            'fetch_document'           => 'Загрузил документ',
            'get_user_organizations'   => 'Запросил список организаций',
            'get_organization_teams'   => 'Запросил список команд',
            'search_meetings'          => 'Искал встречи',
            'get_meeting_summary'      => 'Получил саммари встречи',
            'get_meeting_tasks'        => 'Получил задачи встречи',
            'get_transcript'           => 'Получил транскрипт',
            'get_followup'             => 'Получил фоллоуап',
            'regenerate_followup'      => 'Поставил фоллоуап на регенерацию',
            'get_user_info'            => 'Запросил информацию о пользователе',
            'get_user_insights'        => 'Получил инсайты',
            'get_team_members'         => 'Получил участников команды',
            'create_issue'             => 'Создал задачу',
            'create_entity'            => 'Создал объект',
            'create_agent_task'        => 'Создал задачу агента',
            'update_agent_task'        => 'Обновил задачу агента',
            'update_task_status'       => 'Обновил статус задачи',
            'execute_sql_query'        => 'Выполнил SQL запрос',
            'update_memory'            => 'Обновил память',
            'search_agent_memories'    => 'Искал в памяти',
            'send_user_message'        => 'Отправил сообщение пользователю',
            'github_create_branch'     => 'Создал ветку в GitHub',
            'github_create_pull_request' => 'Создал PR в GitHub',
            'github_create_or_update_file' => 'Изменил файл в GitHub',
            'github_get_repository'    => 'Запросил репозиторий',
            'github_get_tree'          => 'Запросил дерево файлов',
            'github_get_file_contents' => 'Прочитал файл из GitHub',
            'github_get_branch'        => 'Запросил ветку GitHub',
            'github_download_archive'  => 'Скачал архив из GitHub',
            'create_workspace'         => 'Создал рабочее пространство',
            'delete_workspace'         => 'Удалил рабочее пространство',
            'write_workspace_file'     => 'Записал файл в workspace',
            'read_workspace_file'      => 'Прочитал файл из workspace',
            'list_workspaces'          => 'Получил список workspace',
            'list_workspace_files'     => 'Получил файлы workspace',
            'meeting_tasks_extracted'  => 'Заэкстрактил задачи встречи',
            'issues_extracted'         => 'Заэкстрактил задачи из транскрипта',
            'meeting_summary_generated' => 'Сгенерировал саммари встречи',
            'meeting_review_generated' => 'Сгенерировал review встречи',
            'followup_generated'       => 'Сгенерировал follow-up',
            'agenda_generated_general' => 'Сгенерировал общую повестку встречи',
            'agenda_generated_personal' => 'Сгенерировал персональную повестку',
            'upcoming_agenda_generated' => 'Сгенерировал повестку для следующей встречи',
            'participant_profiles_matched' => 'Сопоставил участников с профилями',
            'insight_extracted'        => 'Извлёк инсайты из транскрипта',
            'insight_evolved'          => 'Обновил профиль инсайтов',
            'insight_relationship_updated' => 'Обновил связи между профилями',
            'insight_context_selected' => 'Выбрал релевантные категории памяти',
            'telegram_insights_extracted' => 'Извлёк инсайты из Telegram',
            'telegram_tasks_processed' => 'Обработал задачи из Telegram',
            'demo_personas_generated'  => 'Сгенерировал персонажей для демо',
            'demo_transcript_generated' => 'Сгенерировал демо-транскрипт',
            'methodology_scheme_generated' => 'Сгенерировал схему методологии',
            'transcript_analyzed'      => 'Проанализировал транскрипт встречи',
            'sandbox_llm_completion'   => 'Выполнил sandbox LLM completion',
            'paperclip_created'        => 'Агент Paperclip создал объект',
            'paperclip_updated'        => 'Агент Paperclip обновил объект',
            'paperclip_commented'      => 'Агент Paperclip оставил комментарий',
            'paperclip_approved'       => 'Агент Paperclip подтвердил действие',
            'paperclip_cancelled'      => 'Агент Paperclip отменил объект',
            'paperclip_deleted'        => 'Агент Paperclip удалил объект',
        ];

        $base = $descriptions[$toolName] ?? $toolName;

        if (is_array($result) && array_key_exists('count', $result) && is_numeric($result['count'])) {
            $count = (int) $result['count'];

            return match ($toolName) {
                'meeting_tasks_extracted' => "Заэкстрактил {$count} задач",
                'issues_extracted' => "Заэкстрактил {$count} задач из транскрипта",
                'telegram_tasks_processed' => "Обработал {$count} задач из Telegram",
                'participant_profiles_matched' => "Сопоставил {$count} участников с профилями",
                'demo_personas_generated' => "Сгенерировал {$count} персонажей для демо",
                'insight_context_selected' => "Выбрал {$count} релевантных категорий памяти",
                default => $base,
            };
        }

        // Enrich with context from result
        if (is_array($result)) {
            if (isset($result['issue']['name'])) {
                $base = 'Создал задачу: ' . $result['issue']['name'];
            } elseif (isset($result['agent_task']['name'])) {
                $base = 'Создал задачу агента: ' . $result['agent_task']['name'];
            } elseif (isset($result['message']['content'], $result['recipient']['name'])) {
                $base = 'Отправил сообщение: ' . $result['recipient']['name'];
            } elseif (isset($result['title'])) {
                $base .= ': ' . $result['title'];
            } elseif (isset($result['name'])) {
                $base .= ': ' . $result['name'];
            } elseif (isset($result['artifact_id'])) {
                $base .= ' (' . $result['artifact_id'] . ')';
            } elseif (isset($result['items_count']) || isset($result['sources_count'])) {
                $count = (int) ($result['items_count'] ?? $result['sources_count'] ?? 0);
                if ($count > 0) {
                    $base .= " ({$count})";
                }
            }
        }

        return mb_substr($base, 0, 255);
    }

    public static function recordActivity(
        User $user,
        string $toolName,
        mixed $toolResult = null,
        array $toolArgs = [],
        ?int $chatId = null,
        ?string $agentRunUuid = null,
        bool $success = true,
        ?int $agentTaskRunId = null,
    ): ?self {
        try {
            return self::create([
                'user_id'             => $user->id,
                'chat_id'             => $chatId,
                'agent_run_uuid'      => $agentRunUuid,
                'agent_task_run_id'   => $agentTaskRunId,
                'tool_name'           => $toolName,
                'description'    => self::descriptionFor($toolName, $toolResult),
                'success'        => $success,
                'tool_args'      => $toolArgs !== [] ? $toolArgs : null,
                'tool_result'    => is_array($toolResult)
                    ? array_slice($toolResult, 0, 20)
                    : ($toolResult === null ? null : ['raw' => mb_substr((string) $toolResult, 0, 1000)]),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record agent activity', [
                'tool_name' => $toolName,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
