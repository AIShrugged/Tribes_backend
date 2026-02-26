<?php

namespace App\Services\Demo;

use App\Enums\UserRole;
use App\Jobs\Demo\SeedDemoStructureJob;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\DemoGeneration;
use App\Models\InsightItem;
use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;
use App\Models\InsightRelationship;
use App\Models\InsightShortTerm;
use App\Models\InsightSource;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DemoDataService
{
    /**
     * Check if current user has an active or completed demo generation.
     */
    public function getForUser(User $user): ?DemoGeneration
    {
        return DemoGeneration::forUser($user->id)->latest()->first();
    }

    /**
     * Initiate demo data generation for a user.
     * Creates the DemoGeneration record and dispatches the first job.
     */
    public function initiate(User $user, array $params): DemoGeneration
    {
        $generation = DemoGeneration::create([
            'user_id' => $user->id,
            'status'  => 'pending',
            'params'  => [
                'teams_count'        => $params['teams_count'] ?? 1,
                'employees_per_team' => $params['employees_per_team'] ?? 7,
                'meetings_per_team'  => $params['meetings_per_team'] ?? 3,
            ],
        ]);

        SeedDemoStructureJob::dispatch($generation->id);

        Log::info('DemoDataService: generation initiated', [
            'generation_id' => $generation->id,
            'user_id'       => $user->id,
            'params'        => $generation->params,
        ]);

        return $generation;
    }

    /**
     * Destroy all demo data for a user.
     */
    public function destroy(User $user): void
    {
        $generation = $this->getForUser($user);

        if (!$generation) {
            return;
        }

        DB::transaction(function () use ($generation) {
            $data = $generation->data ?? [];

            // Collect all profile IDs across all teams
            $allProfileIds = [];
            foreach ($data['teams'] ?? [] as $teamData) {
                $profileIds    = $teamData['profile_ids'] ?? [];
                $allProfileIds = array_merge($allProfileIds, $profileIds);
            }

            // Delete insight relationships
            if (!empty($allProfileIds)) {
                InsightRelationship::where(function ($q) use ($allProfileIds) {
                    $q->whereIn('profile_id_a', $allProfileIds)
                        ->orWhereIn('profile_id_b', $allProfileIds);
                })->delete();
            }

            $demoUserIds = collect($data['teams'] ?? [])
                ->flatMap(fn($t) => $t['user_ids'] ?? [])
                ->unique()
                ->all();

            // Delete demo calendar events (created under owner's source, identified by external_id prefix)
            $eventIds = CalendarEvent::where('external_id', 'like', 'demo_event_' . $generation->id . '_%')->pluck('id');

            if ($eventIds->isNotEmpty()) {
                DB::table('participants')->whereIn('calendar_event_id', $eventIds)->delete();
                DB::table('transcript_entries')->whereIn('calendar_event_id', $eventIds)->delete();
                DB::table('followups')->whereIn('calendar_event_id', $eventIds)->delete();
                DB::table('meeting_summaries')->whereIn('calendar_event_id', $eventIds)->delete();
                DB::table('meeting_tasks')->whereIn('calendar_event_id', $eventIds)->delete();
                DB::table('calendar_event_profile')->whereIn('calendar_event_id', $eventIds)->delete();
                CalendarEvent::whereIn('id', $eventIds)->delete();
            }

            if (!empty($demoUserIds)) {
                Source::whereIn('user_id', $demoUserIds)->delete();

                // Delete insight data per profile
                if (!empty($allProfileIds)) {
                    InsightShortTerm::whereIn('profile_id', $allProfileIds)->delete();
                    $sourceIds2 = InsightSource::whereIn('profile_id', $allProfileIds)->pluck('id');
                    InsightItem::whereIn('insight_source_id', $sourceIds2)->delete();
                    InsightSource::whereIn('id', $sourceIds2)->delete();
                    $insightProfileIds = InsightProfile::whereIn('profile_id', $allProfileIds)->pluck('id');
                    InsightProfileHistory::whereIn('insight_profile_id', $insightProfileIds)->delete();
                    InsightProfile::whereIn('id', $insightProfileIds)->delete();
                }

                // Delete profiles
                Profile::whereIn('id', $allProfileIds)->delete();

                // Delete demo users
                User::whereIn('id', $demoUserIds)->delete();
            }

            // Delete org (cascades teams, team_user, org_user, invites)
            if ($generation->organization_id) {
                $org = Organization::find($generation->organization_id);
                $org?->deleteCompletely();
            }

            $generation->delete();
        });

        Log::info('DemoDataService: generation destroyed', ['user_id' => $user->id]);
    }
}
