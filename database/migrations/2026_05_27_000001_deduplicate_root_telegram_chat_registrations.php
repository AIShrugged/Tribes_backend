<?php

use App\Models\TelegramChatRegistration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT
                    id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY telegram_chat_id
                        ORDER BY
                            (bound_at IS NOT NULL) DESC,
                            (organization_id IS NOT NULL) DESC,
                            (channel_conversation_id IS NOT NULL) DESC,
                            id DESC
                    ) AS keep_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY telegram_chat_id
                        ORDER BY
                            (bound_at IS NOT NULL) DESC,
                            (organization_id IS NOT NULL) DESC,
                            (channel_conversation_id IS NOT NULL) DESC,
                            id DESC
                    ) AS row_number
                FROM telegram_chat_registrations
                WHERE message_thread_id IS NULL
            ),
            duplicate_map AS (
                SELECT id, keep_id
                FROM ranked
                WHERE row_number > 1
            ),
            conflicting_settings AS (
                SELECT duplicate_settings.id
                FROM team_notification_settings duplicate_settings
                JOIN duplicate_map ON duplicate_map.id = duplicate_settings.notifiable_id
                JOIN team_notification_settings keep_settings
                    ON keep_settings.team_id = duplicate_settings.team_id
                    AND keep_settings.event_type = duplicate_settings.event_type
                    AND keep_settings.notifiable_type = duplicate_settings.notifiable_type
                    AND keep_settings.notifiable_id = duplicate_map.keep_id
                WHERE duplicate_settings.notifiable_type = ?
            )
            DELETE FROM team_notification_settings
            WHERE id IN (SELECT id FROM conflicting_settings)
        SQL, [TelegramChatRegistration::class]);

        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT
                    id,
                    FIRST_VALUE(id) OVER (
                        PARTITION BY telegram_chat_id
                        ORDER BY
                            (bound_at IS NOT NULL) DESC,
                            (organization_id IS NOT NULL) DESC,
                            (channel_conversation_id IS NOT NULL) DESC,
                            id DESC
                    ) AS keep_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY telegram_chat_id
                        ORDER BY
                            (bound_at IS NOT NULL) DESC,
                            (organization_id IS NOT NULL) DESC,
                            (channel_conversation_id IS NOT NULL) DESC,
                            id DESC
                    ) AS row_number
                FROM telegram_chat_registrations
                WHERE message_thread_id IS NULL
            ),
            duplicate_map AS (
                SELECT id, keep_id
                FROM ranked
                WHERE row_number > 1
            )
            UPDATE team_notification_settings
            SET notifiable_id = duplicate_map.keep_id
            FROM duplicate_map
            WHERE team_notification_settings.notifiable_type = ?
                AND team_notification_settings.notifiable_id = duplicate_map.id
        SQL, [TelegramChatRegistration::class]);

        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY telegram_chat_id
                        ORDER BY
                            (bound_at IS NOT NULL) DESC,
                            (organization_id IS NOT NULL) DESC,
                            (channel_conversation_id IS NOT NULL) DESC,
                            id DESC
                    ) AS row_number
                FROM telegram_chat_registrations
                WHERE message_thread_id IS NULL
            )
            DELETE FROM telegram_chat_registrations
            WHERE id IN (
                SELECT id
                FROM ranked
                WHERE row_number > 1
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS telegram_chat_registrations_root_chat_unique
            ON telegram_chat_registrations (telegram_chat_id)
            WHERE message_thread_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS telegram_chat_registrations_root_chat_unique');
    }
};
