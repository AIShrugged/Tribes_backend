<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_issue_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('base_type', 32);
            $table->foreignId('agent_profile_id')->nullable()->constrained('agent_profiles')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'key']);
        });

        DB::statement("CREATE UNIQUE INDEX organization_issue_types_global_key_unique ON organization_issue_types (key) WHERE organization_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX organization_issue_types_org_key_unique ON organization_issue_types (organization_id, key) WHERE organization_id IS NOT NULL");

        Schema::table('issues', function (Blueprint $table) {
            $table->foreignId('issue_type_id')
                ->nullable()
                ->after('id')
                ->constrained('organization_issue_types')
                ->nullOnDelete();
        });

        $now = now();
        DB::table('organization_issue_types')->insert([
            [
                'organization_id' => null,
                'key' => 'frontend',
                'name' => 'Frontend',
                'base_type' => 'development',
                'metadata' => json_encode(['default_repository' => ['provider' => 'github']]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'organization_id' => null,
                'key' => 'backend',
                'name' => 'Backend',
                'base_type' => 'development',
                'metadata' => json_encode(['default_repository' => ['provider' => 'github']]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'organization_id' => null,
                'key' => 'organization',
                'name' => 'Organization',
                'base_type' => 'organization',
                'metadata' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $globalTypeIds = DB::table('organization_issue_types')
            ->whereNull('organization_id')
            ->pluck('id', 'key')
            ->all();

        $issues = DB::table('issues')
            ->leftJoin('teams', 'teams.id', '=', 'issues.team_id')
            ->select([
                'issues.id',
                'issues.type',
                'teams.slug as team_slug',
                'teams.name as team_name',
            ])
            ->orderBy('issues.id')
            ->get();

        foreach ($issues as $issue) {
            $resolvedKey = $this->resolveLegacyIssueTypeKey(
                (string) ($issue->type ?? ''),
                (string) ($issue->team_slug ?? ''),
                (string) ($issue->team_name ?? '')
            );
            $typeId = $globalTypeIds[$resolvedKey] ?? $globalTypeIds['organization'];

            DB::table('issues')
                ->where('id', $issue->id)
                ->update([
                    'issue_type_id' => $typeId,
                    'type' => $resolvedKey,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issue_type_id');
        });

        DB::statement('DROP INDEX IF EXISTS organization_issue_types_org_key_unique');
        DB::statement('DROP INDEX IF EXISTS organization_issue_types_global_key_unique');

        Schema::dropIfExists('organization_issue_types');
    }

    private function resolveLegacyIssueTypeKey(string $type, string $teamSlug, string $teamName): string
    {
        $type = mb_strtolower(trim($type));
        $teamBlob = mb_strtolower(trim($teamSlug.' '.$teamName));

        return match ($type) {
            'frontend', 'backend', 'organization' => $type,
            'task' => 'organization',
            'development', 'bug' => $this->guessDevelopmentKey($teamBlob),
            default => 'organization',
        };
    }

    private function guessDevelopmentKey(string $teamBlob): string
    {
        if ($teamBlob !== '' && (str_contains($teamBlob, 'front') || str_contains($teamBlob, 'фронт'))) {
            return 'frontend';
        }

        if ($teamBlob !== '' && (str_contains($teamBlob, 'back') || str_contains($teamBlob, 'бэкенд') || str_contains($teamBlob, 'backend'))) {
            return 'backend';
        }

        return 'backend';
    }
};
