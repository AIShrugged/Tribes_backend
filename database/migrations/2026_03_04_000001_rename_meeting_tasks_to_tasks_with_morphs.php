<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('taskable');
            $table->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('assignee_name')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });

        DB::table('meeting_tasks')->orderBy('id')->each(function ($task) {
            DB::table('tasks')->insert([
                'id'            => $task->id,
                'taskable_type' => 'App\Models\CalendarEvent',
                'taskable_id'   => $task->calendar_event_id,
                'profile_id'    => $task->profile_id,
                'title'         => $task->title,
                'description'   => $task->description,
                'assignee_name' => $task->assignee_name,
                'due_date'      => $task->due_date,
                'status'        => $task->status,
                'created_at'    => $task->created_at,
                'updated_at'    => $task->updated_at,
            ]);
        });

        Schema::dropIfExists('meeting_tasks');
    }

    public function down(): void
    {
        Schema::create('meeting_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('assignee_name')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });

        DB::table('tasks')
            ->where('taskable_type', 'App\Models\CalendarEvent')
            ->orderBy('id')
            ->each(function ($task) {
                DB::table('meeting_tasks')->insert([
                    'id'                => $task->id,
                    'calendar_event_id' => $task->taskable_id,
                    'profile_id'        => $task->profile_id,
                    'title'             => $task->title,
                    'description'       => $task->description,
                    'assignee_name'     => $task->assignee_name,
                    'due_date'          => $task->due_date,
                    'status'            => $task->status,
                    'created_at'        => $task->created_at,
                    'updated_at'        => $task->updated_at,
                ]);
            });

        Schema::dropIfExists('tasks');
    }
};
