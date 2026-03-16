<?php

use App\Enums\ConversationChannelType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_identities', function (Blueprint $table) {
            $table->id();
            $table->string('channel_type', 32);
            $table->string('external_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name')->nullable();
            $table->string('username')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['channel_type', 'external_id']);
        });

        Schema::table('channel_messages', function (Blueprint $table) {
            $table->foreignId('author_identity_id')->nullable()->after('conversation_id')->constrained('channel_identities')->nullOnDelete();
            $table->index(['author_identity_id', 'created_at']);
        });

        DB::table('telegram_users')
            ->orderBy('telegram_user_id')
            ->each(function ($telegramUser): void {
                DB::table('channel_identities')->updateOrInsert(
                    [
                        'channel_type' => ConversationChannelType::TELEGRAM->value,
                        'external_id' => (string) $telegramUser->telegram_user_id,
                    ],
                    [
                        'user_id' => $telegramUser->user_id,
                        'display_name' => $telegramUser->telegram_username,
                        'username' => $telegramUser->telegram_username,
                        'metadata' => json_encode([
                            'legacy_telegram_user_id' => (int) $telegramUser->telegram_user_id,
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            });

        DB::table('channel_messages')
            ->whereNotNull('telegram_user_id')
            ->orderBy('id')
            ->each(function ($message): void {
                $identityId = DB::table('channel_identities')
                    ->where('channel_type', ConversationChannelType::TELEGRAM->value)
                    ->where('external_id', (string) $message->telegram_user_id)
                    ->value('id');

                if ($identityId) {
                    DB::table('channel_messages')
                        ->where('id', $message->id)
                        ->update(['author_identity_id' => $identityId]);
                }
            });

        Schema::table('channel_messages', function (Blueprint $table) {
            $table->dropColumn('telegram_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->bigInteger('telegram_user_id')->nullable()->after('author_identity_id');
        });

        DB::table('channel_messages')
            ->whereNotNull('author_identity_id')
            ->orderBy('id')
            ->each(function ($message): void {
                $identity = DB::table('channel_identities')->where('id', $message->author_identity_id)->first();

                if ($identity && $identity->channel_type === ConversationChannelType::TELEGRAM->value) {
                    DB::table('channel_messages')
                        ->where('id', $message->id)
                        ->update(['telegram_user_id' => (int) $identity->external_id]);
                }
            });

        Schema::table('channel_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_identity_id');
        });

        Schema::dropIfExists('channel_identities');
    }
};
