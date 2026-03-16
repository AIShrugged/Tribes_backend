<?php

namespace App\Services\Agent\Tools;

use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\ChannelMessage;
use App\Models\Task;

/**
 * Creates a new task, optionally linked to a meeting, chat message, or Telegram message.
 *
 * Example questions this tool answers:
 * - "Создай задачу для Ивана: подготовить отчёт до пятницы"
 * - "Добавь задачу по итогам встречи"
 * - "Create a task: review the PR by tomorrow"
 */
class CreateTaskTool extends AbstractAgentTool
{
    private const TASKABLE_MAP = [
        'calendar_event' => CalendarEvent::class,
        'channel_message' => ChannelMessage::class,
        'chat_message' => ChannelMessage::class,
        'telegram_chat_message' => ChannelMessage::class,
    ];

    public function getName(): string
    {
        return 'create_task';
    }

    public function getDescription(): string
    {
        return 'Create a new task. Can optionally be linked to a calendar event, chat message, or Telegram message. Use when the user wants to capture an action item, assignment, or follow-up.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type'        => 'string',
                    'description' => 'Short title of the task.',
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => 'Detailed description of the task (optional).',
                ],
                'assignee_name' => [
                    'type'        => 'string',
                    'description' => 'Full name of the person responsible for the task (optional).',
                ],
                'profile_id' => [
                    'type'        => 'integer',
                    'description' => 'Profile ID of the assignee — links the task to a system user (optional).',
                ],
                'due_date' => [
                    'type'        => 'string',
                    'description' => 'Due date in YYYY-MM-DD format (optional).',
                ],
                'taskable_type' => [
                    'type'        => 'string',
                    'enum'        => ['calendar_event', 'channel_message', 'chat_message', 'telegram_chat_message'],
                    'description' => 'Type of the entity this task is linked to (optional). One of: calendar_event, channel_message, chat_message, telegram_chat_message.',
                ],
                'taskable_id' => [
                    'type'        => 'integer',
                    'description' => 'ID of the linked entity — required when taskable_type is provided.',
                ],
            ],
            'required' => ['title'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $title = trim($parameters['title'] ?? '');
        if (! $title) {
            return ['success' => false, 'error' => 'title is required'];
        }

        $taskableType = null;
        $taskableId   = null;

        if (! empty($parameters['taskable_type'])) {
            $typeKey = $parameters['taskable_type'];
            if (! isset(self::TASKABLE_MAP[$typeKey])) {
                return ['success' => false, 'error' => "Unknown taskable_type: {$typeKey}. Valid values: " . implode(', ', array_keys(self::TASKABLE_MAP))];
            }
            $taskableType = self::TASKABLE_MAP[$typeKey];
            $taskableId   = $parameters['taskable_id'] ?? null;

            if (! $taskableId) {
                return ['success' => false, 'error' => 'taskable_id is required when taskable_type is provided'];
            }
        }

        $task = Task::create([
            'taskable_type' => $taskableType,
            'taskable_id'   => $taskableId,
            'profile_id'    => $parameters['profile_id'] ?? null,
            'title'         => $title,
            'description'   => $parameters['description'] ?? null,
            'assignee_name' => $parameters['assignee_name'] ?? null,
            'due_date'      => $parameters['due_date'] ?? null,
            'status'        => MeetingTaskStatus::OPEN->value,
        ]);

        return [
            'success' => true,
            'task'    => [
                'id'            => $task->id,
                'title'         => $task->title,
                'description'   => $task->description,
                'assignee_name' => $task->assignee_name,
                'profile_id'    => $task->profile_id,
                'due_date'      => $task->due_date?->toDateString(),
                'status'        => $task->status,
                'taskable_type' => $task->taskable_type,
                'taskable_id'   => $task->taskable_id,
            ],
        ];
    }
}
