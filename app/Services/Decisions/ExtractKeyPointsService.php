<?php

namespace App\Services\Decisions;

use App\Models\MeetingKeyPoint;
use App\Models\MeetingSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtractKeyPointsService
{
    use ResolvesTeamContexts;

    public function extract(MeetingSummary $summary): int
    {
        $rawPoints = $summary->key_points ?? [];
        if (empty($rawPoints)) {
            return 0;
        }

        $event = $summary->calendarEvent;
        $teamContexts = $event
            ? $this->resolveTeamContexts($event)
            : [[null, null]];

        return DB::transaction(function () use ($summary, $event, $rawPoints, $teamContexts) {
            // Idempotent: wipe and re-insert so backfill is always safe to re-run.
            MeetingKeyPoint::where('meeting_summary_id', $summary->id)->delete();

            $created = 0;
            foreach ($rawPoints as $position => $raw) {
                $text = trim(is_string($raw) ? $raw : ($raw['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                foreach ($teamContexts as [$teamId, $organizationId]) {
                    MeetingKeyPoint::create([
                        'meeting_summary_id' => $summary->id,
                        'calendar_event_id'  => $event?->id,
                        'team_id'            => $teamId,
                        'organization_id'    => $organizationId,
                        'text'               => $text,
                        'position'           => (int) $position,
                    ]);
                    $created++;
                }
            }

            if ($created > 0) {
                Log::info('ExtractKeyPointsService: extracted key points', [
                    'summary_id' => $summary->id,
                    'count'      => $created,
                ]);
            }

            return $created;
        });
    }
}