<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Default team per organization.
 *
 * Adds `teams.is_default` flag + partial unique index, then backfills:
 *   - One default team per existing organization (slug 'general', or
 *     'general-default' if 'general' is already taken).
 *   - team_user rows for every organization_user member (filtered by JOIN users
 *     to skip dangling FK-less orphans that already exist in prod).
 *
 * Fails loudly if any organization ends up without a default team — that
 * indicates a slug-collision case the two-pass logic doesn't cover and
 * requires manual intervention rather than silent partial success.
 *
 * down() throws because rollback after traffic would delete teams that may
 * have user data attached. See plan for the manual cleanup SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Schema
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('slug');
        });

        DB::statement(
            'CREATE UNIQUE INDEX teams_default_per_org_unique '
            . 'ON teams (organization_id) WHERE is_default = true'
        );

        // 2. Guard: backfill requires a default methodology row to exist.
        $methodologyId = DB::table('methodologies')->where('is_default', true)->value('id');
        if (!$methodologyId) {
            throw new \RuntimeException(
                'No default methodology row found — backfill cannot proceed. '
                . 'Ensure 2025_12_08_155550_create_methodologies_table.php ran first.'
            );
        }

        // 3. Pass 1: create default team for orgs without one and without slug 'general' collision.
        DB::statement('
            INSERT INTO teams (organization_id, methodology_id, name, slug, is_default, created_at, updated_at)
            SELECT o.id, ?, ?, ?, true, NOW(), NOW()
            FROM organizations o
            WHERE NOT EXISTS (
                SELECT 1 FROM teams t
                WHERE t.organization_id = o.id AND t.is_default = true
            )
            AND NOT EXISTS (
                SELECT 1 FROM teams t
                WHERE t.organization_id = o.id AND t.slug = ?
            )
        ', [$methodologyId, 'General', 'general', 'general']);

        // 4. Pass 2: orgs where 'general' is taken — try 'general-default'.
        DB::statement('
            INSERT INTO teams (organization_id, methodology_id, name, slug, is_default, created_at, updated_at)
            SELECT o.id, ?, ?, ?, true, NOW(), NOW()
            FROM organizations o
            WHERE NOT EXISTS (
                SELECT 1 FROM teams t
                WHERE t.organization_id = o.id AND t.is_default = true
            )
            AND NOT EXISTS (
                SELECT 1 FROM teams t
                WHERE t.organization_id = o.id AND t.slug = ?
            )
        ', [$methodologyId, 'Default', 'general-default', 'general-default']);

        // 5. Invariant assertion — both slug fallbacks taken? fail loud.
        $orgsWithoutDefault = (int) DB::selectOne('
            SELECT COUNT(*) AS c FROM organizations o
            WHERE NOT EXISTS (
                SELECT 1 FROM teams t WHERE t.organization_id = o.id AND t.is_default = true
            )
        ')->c;

        if ($orgsWithoutDefault > 0) {
            throw new \RuntimeException(
                "Backfill incomplete: {$orgsWithoutDefault} organization(s) still have no default team. "
                . "Likely both 'general' and 'general-default' slugs are taken. Manual intervention required."
            );
        }

        // 6. Populate team_user from organization_user. JOIN users filters
        //    dangling user_id refs (organization_user has no FK to users today).
        DB::statement('
            INSERT INTO team_user (team_id, user_id, created_at, updated_at)
            SELECT t.id, ou.user_id, NOW(), NOW()
            FROM teams t
            JOIN organization_user ou ON ou.organization_id = t.organization_id
            JOIN users u ON u.id = ou.user_id
            WHERE t.is_default = true
            ON CONFLICT (team_id, user_id) DO NOTHING
        ');
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Irreversible: down() would delete default teams and cascade team_user rows '
            . 'that may include data created after this migration ran. Use the manual '
            . 'rollback procedure documented in the feature plan if rollback is truly needed.'
        );
    }
};
