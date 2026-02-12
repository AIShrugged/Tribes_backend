<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign key from telegram_chat_messages
        Schema::table('telegram_chat_messages', function (Blueprint $table) {
            $table->dropForeign(['telegram_user_id']);
        });

        // Update telegram_chat_messages to use telegram_id instead of id
        // This maps old auto-increment IDs to Telegram API IDs
        DB::statement('
            UPDATE telegram_chat_messages
            SET telegram_user_id = (
                SELECT telegram_id
                FROM telegram_users
                WHERE telegram_users.id = telegram_chat_messages.telegram_user_id
            )
            WHERE telegram_user_id IS NOT NULL
        ');

        // For SQLite: need to recreate the table
        // For PostgreSQL: can modify in place
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->recreateTelegramUsersTableSqlite();
        } else {
            $this->modifyTelegramUsersTablePostgres();
        }

        // Re-add foreign key to telegram_chat_messages, now referencing telegram_user_id
        Schema::table('telegram_chat_messages', function (Blueprint $table) {
            $table->foreign('telegram_user_id')
                ->references('telegram_user_id')
                ->on('telegram_users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key
        Schema::table('telegram_chat_messages', function (Blueprint $table) {
            $table->dropForeign(['telegram_user_id']);
        });

        // Restore telegram_users structure
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->restoreTelegramUsersTableSqlite();
        } else {
            $this->restoreTelegramUsersTablePostgres();
        }

        // Restore foreign key
        Schema::table('telegram_chat_messages', function (Blueprint $table) {
            $table->foreign('telegram_user_id')
                ->references('id')
                ->on('telegram_users')
                ->nullOnDelete();
        });
    }

    private function recreateTelegramUsersTableSqlite(): void
    {
        // Create new table with telegram_user_id as primary key
        Schema::create('telegram_users_new', function (Blueprint $table) {
            $table->bigInteger('telegram_user_id')->primary();
            $table->string('telegram_username')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
        });

        // Copy data from old table
        DB::statement('
            INSERT INTO telegram_users_new (telegram_user_id, telegram_username, user_id, created_at, updated_at)
            SELECT telegram_id, telegram_username, user_id, created_at, updated_at
            FROM telegram_users
        ');

        // Drop old table
        Schema::dropIfExists('telegram_users');

        // Rename new table
        Schema::rename('telegram_users_new', 'telegram_users');
    }

    private function modifyTelegramUsersTablePostgres(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropPrimary(['id']);
            $table->dropUnique(['telegram_id']);
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->renameColumn('telegram_id', 'telegram_user_id');
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn('id');
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->primary('telegram_user_id');
        });
    }

    private function restoreTelegramUsersTableSqlite(): void
    {
        // Create table with old structure
        Schema::create('telegram_users_old', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
        });

        // Copy data back
        DB::statement('
            INSERT INTO telegram_users_old (telegram_id, telegram_username, user_id, created_at, updated_at)
            SELECT telegram_user_id, telegram_username, user_id, created_at, updated_at
            FROM telegram_users
        ');

        // Drop new table
        Schema::dropIfExists('telegram_users');

        // Rename old table back
        Schema::rename('telegram_users_old', 'telegram_users');
    }

    private function restoreTelegramUsersTablePostgres(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropPrimary(['telegram_user_id']);
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->id()->first();
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->renameColumn('telegram_user_id', 'telegram_id');
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->unique('telegram_id');
        });
    }
};