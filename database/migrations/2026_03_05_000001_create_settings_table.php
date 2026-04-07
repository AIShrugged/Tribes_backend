<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('settings')->insert([
            ['key' => 'model.followup',        'value' => 'google/gemini-3-pro-preview',   'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model.scheme',           'value' => 'google/gemini-3-pro-preview',   'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model.tribes',           'value' => 'google/gemini-3-pro-preview',   'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model.telegram_agent',   'value' => 'anthropic/claude-sonnet-4.6',   'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model.meeting_summary',  'value' => 'google/gemini-3-pro-preview',   'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model.meeting_tasks',    'value' => 'google/gemini-3-pro-preview',   'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model.insight',          'value' => 'google/gemini-3-pro-preview',   'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model.demo',             'value' => 'google/gemini-3-pro-preview',   'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
