<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function reposMetadata(): string
    {
        return json_encode([
            'repositories' => [
                ['provider' => 'github', 'owner' => 'AIShrugged', 'name' => 'Tribes_backend', 'description' => 'Laravel backend'],
                ['provider' => 'github', 'owner' => 'AIShrugged', 'name' => 'Tribes_frontend', 'description' => 'Frontend app'],
            ],
        ]);
    }

    public function up(): void
    {
        $now = now();

        // 1. Create global 'development' type (skip if already exists)
        if (! DB::table('organization_issue_types')->where('key', 'development')->whereNull('organization_id')->exists()) {
            DB::table('organization_issue_types')->insert([
                'organization_id' => null,
                'key' => 'development',
                'name' => 'Development',
                'base_type' => 'development',
                'agent_profile_id' => DB::table('organization_issue_types')
                    ->where('key', 'backend')
                    ->whereNull('organization_id')
                    ->value('agent_profile_id'),
                'metadata' => $this->reposMetadata(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Ensure metadata is up-to-date on existing global development type
        DB::table('organization_issue_types')
            ->where('key', 'development')
            ->whereNull('organization_id')
            ->update(['metadata' => $this->reposMetadata(), 'updated_at' => $now]);

        $globalDevId = DB::table('organization_issue_types')
            ->where('key', 'development')
            ->whereNull('organization_id')
            ->value('id');

        // 2. Create org-specific 'development' types for each org that had backend/frontend
        $orgIds = DB::table('organization_issue_types')
            ->whereNotNull('organization_id')
            ->whereIn('key', ['backend', 'frontend'])
            ->distinct()
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            if (! DB::table('organization_issue_types')->where('key', 'development')->where('organization_id', $orgId)->exists()) {
                $agentProfileId = DB::table('organization_issue_types')
                    ->where('organization_id', $orgId)
                    ->where('key', 'backend')
                    ->value('agent_profile_id');

                DB::table('organization_issue_types')->insert([
                    'organization_id' => $orgId,
                    'key' => 'development',
                    'name' => 'Development',
                    'base_type' => 'development',
                    'agent_profile_id' => $agentProfileId,
                    'metadata' => $this->reposMetadata(),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Ensure metadata is up-to-date
            DB::table('organization_issue_types')
                ->where('key', 'development')
                ->where('organization_id', $orgId)
                ->update(['metadata' => $this->reposMetadata(), 'updated_at' => $now]);

            $orgDevId = DB::table('organization_issue_types')
                ->where('key', 'development')
                ->where('organization_id', $orgId)
                ->value('id');

            // 3. Re-point issues from backend/frontend to development (org-specific)
            $oldTypeIds = DB::table('organization_issue_types')
                ->where('organization_id', $orgId)
                ->whereIn('key', ['backend', 'frontend'])
                ->pluck('id');

            DB::table('issues')
                ->whereIn('issue_type_id', $oldTypeIds)
                ->update([
                    'issue_type_id' => $orgDevId,
                    'type' => 'development',
                    'updated_at' => $now,
                ]);
        }

        // 4. Re-point issues that use global backend/frontend types
        $globalOldIds = DB::table('organization_issue_types')
            ->whereNull('organization_id')
            ->whereIn('key', ['backend', 'frontend'])
            ->pluck('id');

        DB::table('issues')
            ->whereIn('issue_type_id', $globalOldIds)
            ->update([
                'issue_type_id' => $globalDevId,
                'type' => 'development',
                'updated_at' => $now,
            ]);

        // 5. Also catch any issues with type='backend'/'frontend' but no issue_type_id
        DB::table('issues')
            ->whereIn('type', ['backend', 'frontend'])
            ->update([
                'issue_type_id' => $globalDevId,
                'type' => 'development',
                'updated_at' => $now,
            ]);

        // 6. Deactivate old backend/frontend types
        DB::table('organization_issue_types')
            ->whereIn('key', ['backend', 'frontend'])
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        $now = now();

        // Re-activate backend/frontend types
        DB::table('organization_issue_types')
            ->whereIn('key', ['backend', 'frontend'])
            ->update([
                'is_active' => true,
                'updated_at' => $now,
            ]);

        // Point development issues back to backend (safe default)
        $devTypeIds = DB::table('organization_issue_types')
            ->where('key', 'development')
            ->pluck('id');

        foreach ($devTypeIds as $devId) {
            $orgId = DB::table('organization_issue_types')
                ->where('id', $devId)
                ->value('organization_id');

            $backendId = DB::table('organization_issue_types')
                ->where('key', 'backend')
                ->where(function ($q) use ($orgId) {
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    } else {
                        $q->whereNull('organization_id');
                    }
                })
                ->value('id');

            if ($backendId) {
                DB::table('issues')
                    ->where('issue_type_id', $devId)
                    ->update([
                        'issue_type_id' => $backendId,
                        'type' => 'backend',
                        'updated_at' => $now,
                    ]);
            }
        }

        // Remove development types
        DB::table('organization_issue_types')
            ->where('key', 'development')
            ->delete();
    }
};
