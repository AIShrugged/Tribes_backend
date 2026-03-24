<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'chat_id',
        'agent_run_uuid',
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

    /**
     * Generate a human-readable description from tool name and result.
     */
    public static function descriptionFor(string $toolName, mixed $result): string
    {
        $descriptions = [
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
            'get_user_info'            => 'Запросил информацию о пользователе',
            'get_user_insights'        => 'Получил инсайты',
            'get_team_members'         => 'Получил участников команды',
            'create_issue'             => 'Создал задачу',
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
        ];

        $base = $descriptions[$toolName] ?? $toolName;

        // Enrich with context from result
        if (is_array($result)) {
            if (isset($result['name'])) {
                $base .= ': ' . $result['name'];
            } elseif (isset($result['title'])) {
                $base .= ': ' . $result['title'];
            } elseif (isset($result['artifact_id'])) {
                $base .= ' (' . $result['artifact_id'] . ')';
            }
        }

        return mb_substr($base, 0, 255);
    }
}
