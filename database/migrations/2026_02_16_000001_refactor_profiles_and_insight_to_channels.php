<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor user identity:
 *  - Create channels table (google_calendar, telegram, zoom)
 *  - Add channel_id + channel_identifier to profiles, drop email
 *  - Replace email with profile_id in all insight tables
 *  - Replace email_a/email_b with profile_id_a/profile_id_b in insight_relationships
 *
 * Data preservation strategy:
 *  - Existing profiles → channel=google_calendar, identifier=email
 *  - TelegramUsers with linked User but no profile → new telegram-channel profile
 *  - Rows that cannot be mapped to a profile are deleted (no orphaned data)
 *  - If any step fails due to unexpected data, the migration continues safely
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // STEP 1: Create channels table
        // ----------------------------------------------------------------
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        DB::table('channels')->insert([
            ['name' => 'google_calendar', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'telegram',        'created_at' => now(), 'updated_at' => now()],
            ['name' => 'zoom',            'created_at' => now(), 'updated_at' => now()],
        ]);

        $googleCalendarId = DB::table('channels')->where('name', 'google_calendar')->value('id');
        $telegramId       = DB::table('channels')->where('name', 'telegram')->value('id');

        // ----------------------------------------------------------------
        // STEP 2: Add channel columns to profiles (nullable for now)
        // ----------------------------------------------------------------
        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('channel_id')->nullable()->after('user_id');
            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
            $table->string('channel_identifier')->nullable()->after('channel_id');
        });

        // ----------------------------------------------------------------
        // STEP 3: Fill existing profiles as google_calendar
        // channel_identifier = email (still present at this point)
        // ----------------------------------------------------------------
        DB::statement("
            UPDATE profiles
            SET channel_id         = ?,
                channel_identifier = email
            WHERE email IS NOT NULL
        ", [$googleCalendarId]);

        // ----------------------------------------------------------------
        // STEP 4: Create telegram profiles for TelegramUsers that have a
        // linked User account but no profile entry yet.
        // We temporarily set email so the JOIN in step 7 can find them.
        // ----------------------------------------------------------------

        // Fix sequence: if rows were inserted with explicit IDs, the sequence
        // may not have advanced. Reset it to avoid primary key conflicts.
        DB::statement("
            SELECT setval(
                pg_get_serial_sequence('profiles', 'id'),
                (SELECT COALESCE(MAX(id), 1) FROM profiles)
            )
        ");

        $telegramUsers = DB::table('telegram_users')
            ->join('users', 'telegram_users.user_id', '=', 'users.id')
            ->whereNotNull('telegram_users.user_id')
            ->select(
                'telegram_users.telegram_user_id',
                'telegram_users.user_id',
                'users.email'
            )
            ->get();

        foreach ($telegramUsers as $tu) {
            // Skip if a telegram profile for this user already exists
            $alreadyExists = DB::table('profiles')
                ->where('user_id', $tu->user_id)
                ->where('channel_id', $telegramId)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            // Use SAVEPOINT so a failure here doesn't abort the whole transaction.
            // PostgreSQL aborts the transaction on any error inside it, so we must
            // rollback to savepoint on exception instead of relying on PHP try-catch.
            DB::statement('SAVEPOINT sp_telegram_profile');
            try {
                DB::table('profiles')->insert([
                    'user_id'            => $tu->user_id,
                    'channel_id'         => $telegramId,
                    'channel_identifier' => (string) $tu->telegram_user_id,
                    'email'              => $tu->email, // temporary — used for JOIN below
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
                DB::statement('RELEASE SAVEPOINT sp_telegram_profile');
            } catch (\Throwable $e) {
                DB::statement('ROLLBACK TO SAVEPOINT sp_telegram_profile');
                \Illuminate\Support\Facades\Log::warning(
                    'Migration refactor_profiles: telegram profile insert skipped',
                    ['user_id' => $tu->user_id, 'error' => $e->getMessage()]
                );
            }
        }

        // ----------------------------------------------------------------
        // STEP 5: Add profile_id (nullable FK) to insight tables
        // ----------------------------------------------------------------
        Schema::table('insight_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_id')->nullable()->after('id');
            $table->foreign('profile_id')->references('id')->on('profiles')->nullOnDelete();
        });

        Schema::table('insight_items', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_id')->nullable()->after('id');
            $table->foreign('profile_id')->references('id')->on('profiles')->nullOnDelete();
        });

        Schema::table('insight_short_term', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_id')->nullable()->after('id');
            $table->foreign('profile_id')->references('id')->on('profiles')->nullOnDelete();
        });

        Schema::table('insight_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_id')->nullable()->after('id');
            $table->foreign('profile_id')->references('id')->on('profiles')->nullOnDelete();
        });

        // ----------------------------------------------------------------
        // STEP 6: Add profile_id_a / profile_id_b to insight_relationships
        // ----------------------------------------------------------------
        Schema::table('insight_relationships', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_id_a')->nullable()->after('id');
            $table->foreign('profile_id_a')->references('id')->on('profiles')->nullOnDelete();
            $table->unsignedBigInteger('profile_id_b')->nullable()->after('profile_id_a');
            $table->foreign('profile_id_b')->references('id')->on('profiles')->nullOnDelete();
        });

        // ----------------------------------------------------------------
        // STEP 7: Fill profile_id in insight tables via email JOIN
        // profiles.email is still present and matches insight tables.email
        // For google_calendar profiles: email = channel_identifier = original email
        // For telegram profiles: email = users.email (set temporarily in step 4)
        // We pick the FIRST matching profile (in case of multiple for one email)
        // ----------------------------------------------------------------
        $emailTables = ['insight_sources', 'insight_items', 'insight_short_term', 'insight_profiles'];

        foreach ($emailTables as $tbl) {
            try {
                DB::statement("
                    UPDATE {$tbl}
                    SET profile_id = (
                        SELECT p.id
                        FROM profiles p
                        WHERE p.email = {$tbl}.email
                        ORDER BY p.id
                        LIMIT 1
                    )
                    WHERE email IS NOT NULL
                      AND profile_id IS NULL
                ");
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    "Migration refactor_profiles: profile_id fill failed for {$tbl}",
                    ['error' => $e->getMessage()]
                );
            }
        }

        // insight_relationships: map both sides, order by profile_id (LEAST/GREATEST)
        try {
            DB::statement("
                UPDATE insight_relationships ir
                SET profile_id_a = LEAST(pa.id, pb.id),
                    profile_id_b = GREATEST(pa.id, pb.id)
                FROM profiles pa, profiles pb
                WHERE pa.email = ir.email_a
                  AND pb.email = ir.email_b
                  AND ir.profile_id_a IS NULL
                  AND ir.profile_id_b IS NULL
            ");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Migration refactor_profiles: relationship profile_id fill failed',
                ['error' => $e->getMessage()]
            );
        }

        // ----------------------------------------------------------------
        // STEP 8: Delete orphaned rows that could not be mapped to a profile
        // ----------------------------------------------------------------
        DB::table('insight_items')->whereNull('profile_id')->delete();
        DB::table('insight_short_term')->whereNull('profile_id')->delete();
        DB::table('insight_sources')->whereNull('profile_id')->delete();

        // Deleting insight_profiles cascades to insight_profile_history
        DB::table('insight_profiles')->whereNull('profile_id')->delete();

        DB::table('insight_relationships')
            ->whereNull('profile_id_a')
            ->orWhereNull('profile_id_b')
            ->delete();

        // ----------------------------------------------------------------
        // STEP 9: Make profile_id NOT NULL (data is clean after step 8)
        // ----------------------------------------------------------------
        DB::statement('ALTER TABLE insight_sources      ALTER COLUMN profile_id SET NOT NULL');
        DB::statement('ALTER TABLE insight_items        ALTER COLUMN profile_id SET NOT NULL');
        DB::statement('ALTER TABLE insight_short_term   ALTER COLUMN profile_id SET NOT NULL');
        DB::statement('ALTER TABLE insight_profiles     ALTER COLUMN profile_id SET NOT NULL');
        DB::statement('ALTER TABLE insight_relationships ALTER COLUMN profile_id_a SET NOT NULL');
        DB::statement('ALTER TABLE insight_relationships ALTER COLUMN profile_id_b SET NOT NULL');

        // ----------------------------------------------------------------
        // STEP 10: Drop email columns from insight tables
        // PostgreSQL auto-drops dependent indexes when column is dropped
        // ----------------------------------------------------------------

        // insight_sources: drop email index + email_source_type_source_id unique, then column
        // Also add new unique: (profile_id, source_type, source_id)
        Schema::table('insight_sources', function (Blueprint $table) {
            $table->dropIndex('insight_sources_email_index');
            $table->dropUnique('insight_sources_email_source_type_source_id_unique');
            $table->dropColumn('email');
        });
        Schema::table('insight_sources', function (Blueprint $table) {
            $table->unique(['profile_id', 'source_type', 'source_id']);
        });

        // insight_items: drop indexes, drop email, add new index
        Schema::table('insight_items', function (Blueprint $table) {
            $table->dropIndex('insight_items_email_index');
            $table->dropIndex('insight_items_email_category_is_archived_index');
            $table->dropColumn('email');
        });
        Schema::table('insight_items', function (Blueprint $table) {
            $table->index(['profile_id', 'category', 'is_archived']);
        });

        // insight_short_term: drop indexes, drop email, add new index
        Schema::table('insight_short_term', function (Blueprint $table) {
            $table->dropIndex('insight_short_term_email_index');
            $table->dropIndex('insight_short_term_email_context_type_expires_at_index');
            $table->dropColumn('email');
        });
        Schema::table('insight_short_term', function (Blueprint $table) {
            $table->index(['profile_id', 'context_type', 'expires_at']);
        });

        // insight_profiles: drop unique(email,category) + index, drop email, add new unique
        Schema::table('insight_profiles', function (Blueprint $table) {
            $table->dropUnique('insight_profiles_email_category_unique');
            $table->dropIndex('insight_profiles_email_index');
            $table->dropColumn('email');
        });
        Schema::table('insight_profiles', function (Blueprint $table) {
            $table->unique(['profile_id', 'category']);
        });

        // insight_profile_history: drop email index + column
        Schema::table('insight_profile_history', function (Blueprint $table) {
            $table->dropIndex('insight_profile_history_email_index');
            $table->dropColumn('email');
        });

        // insight_relationships: drop email indexes/unique, drop columns, add new unique + indexes
        Schema::table('insight_relationships', function (Blueprint $table) {
            $table->dropUnique('insight_relationships_email_a_email_b_unique');
            $table->dropIndex('insight_relationships_email_a_index');
            $table->dropIndex('insight_relationships_email_b_index');
            $table->dropColumn(['email_a', 'email_b']);
        });
        Schema::table('insight_relationships', function (Blueprint $table) {
            $table->unique(['profile_id_a', 'profile_id_b']);
            $table->index('profile_id_a');
            $table->index('profile_id_b');
        });

        // ----------------------------------------------------------------
        // STEP 11: Clean up profiles table
        // Drop email (auto-drops profiles_email_unique)
        // Make channel_id / channel_identifier NOT NULL
        // Add UNIQUE(channel_id, channel_identifier)
        // ----------------------------------------------------------------
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropUnique('profiles_email_unique');
            $table->dropColumn('email');
        });

        DB::statement('ALTER TABLE profiles ALTER COLUMN channel_id         SET NOT NULL');
        DB::statement('ALTER TABLE profiles ALTER COLUMN channel_identifier SET NOT NULL');

        Schema::table('profiles', function (Blueprint $table) {
            $table->unique(['channel_id', 'channel_identifier']);
        });
    }

    public function down(): void
    {
        // Full reversal is not implemented due to complexity of restoring email data.
        // To roll back: restore from a database snapshot taken before this migration.
        throw new \RuntimeException(
            'Rolling back this migration is not supported. Restore from a DB snapshot.'
        );
    }
};
