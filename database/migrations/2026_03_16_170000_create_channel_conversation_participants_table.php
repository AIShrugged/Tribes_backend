<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('channel_conversations')->cascadeOnDelete();
            $table->foreignId('channel_identity_id')->constrained('channel_identities')->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'channel_identity_id'], 'channel_conversation_participants_unique');
        });

        DB::table('channel_messages')
            ->whereNotNull('author_identity_id')
            ->select('conversation_id', 'author_identity_id')
            ->distinct()
            ->orderBy('conversation_id')
            ->orderBy('author_identity_id')
            ->each(function ($row): void {
                $firstMessageAt = DB::table('channel_messages')
                    ->where('conversation_id', $row->conversation_id)
                    ->where('author_identity_id', $row->author_identity_id)
                    ->min('created_at');

                $lastMessageAt = DB::table('channel_messages')
                    ->where('conversation_id', $row->conversation_id)
                    ->where('author_identity_id', $row->author_identity_id)
                    ->max('created_at');

                DB::table('channel_conversation_participants')->insert([
                    'conversation_id' => $row->conversation_id,
                    'channel_identity_id' => $row->author_identity_id,
                    'joined_at' => $firstMessageAt,
                    'last_message_at' => $lastMessageAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_conversation_participants');
    }
};
