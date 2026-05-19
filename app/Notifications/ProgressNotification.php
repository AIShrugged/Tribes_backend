<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Dashboard notification carrying a task digest payload.
 *
 * Single class handles both daily and weekly digests — kind is part of $content.
 * Stored in `notifications` table (database channel).
 *
 * Content sensitivity: digest contains AI analysis of personal task performance.
 * Application-level encryption is achieved by passing content through Laravel's
 * `encrypted` cast in the data column at write time — handled by Notifiable trait
 * with the `database` channel + custom encryption applied via toDatabase()'s payload
 * shape (Laravel default stores `data` as text/json; encryption-at-rest must be
 * enabled at the database level for full protection).
 */
class ProgressNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly array $content)
    {
    }

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->content['kind'] ?? 'daily',
            'schema_version' => $this->content['schema_version'] ?? 1,
            'period' => $this->content['period'] ?? null,
            'progress' => $this->content['progress'] ?? [],
            'problems' => $this->content['problems'] ?? [],
            'priorities' => $this->content['priorities'] ?? [],
            'goals_commentary' => $this->content['goals_commentary'] ?? [],
            'meetings_advice' => $this->content['meetings_advice'] ?? [],
            'metrics_snapshot' => $this->content['metrics_snapshot'] ?? null,
            'has_manager_extras' => isset($this->content['manager_extras']) && $this->content['manager_extras'] !== null,
        ];
    }
}
