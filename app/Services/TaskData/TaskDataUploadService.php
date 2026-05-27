<?php

namespace App\Services\TaskData;

use App\Exceptions\AppException;
use App\Http\Requests\API\v1\UploadTaskDataRequest;
use App\Jobs\SendTaskDataUploadReportJob;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\User;
use App\Services\Issue\IssueAutoPipelineDispatcher;
use App\Services\Transcript\TranscriptArchiveExtractor;
use App\Services\Transcript\TranscriptContentNormalizer;
use Illuminate\Support\Facades\Log;

class TaskDataUploadService
{
    public function __construct(
        private readonly TranscriptArchiveExtractor $archiveExtractor,
        private readonly TranscriptContentNormalizer $normalizer,
        private readonly TaskDataIssueExtractionService $extractionService,
    ) {
    }

    /**
     * @return array{upload: TaskDataUpload, created: \Illuminate\Support\Collection, updated: \Illuminate\Support\Collection}
     */
    public function handle(UploadTaskDataRequest $request, User $uploader): array
    {
        $team = $this->resolveTeam($request, $uploader);

        $upload = TaskDataUpload::create([
            'user_id'           => $uploader->id,
            'team_id'           => $team->id,
            'organization_id'   => $team->organization_id,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'status'            => 'processing',
        ]);

        try {
            $rawContent = $this->archiveExtractor->extract($request->file('file'));
            $content    = $this->normalizer->normalize($rawContent);

            $result = $this->extractionService->extract($content, $team, $uploader, $upload);

            $allIssueIds = $result['created']->pluck('id')
                ->merge($result['updated']->pluck('id'))
                ->filter()
                ->values()
                ->all();

            if ($allIssueIds !== []) {
                app(IssueAutoPipelineDispatcher::class)->dispatchForStandalone($allIssueIds);
            }

            $upload->update([
                'status'         => 'done',
                'issues_created' => $result['created']->count(),
                'issues_updated' => $result['updated']->count(),
            ]);

            Log::info('task_data_upload.done', [
                'upload_id'      => $upload->id,
                'uploader_id'    => $uploader->id,
                'team_id'        => $team->id,
                'issues_created' => $result['created']->count(),
                'issues_updated' => $result['updated']->count(),
            ]);

            SendTaskDataUploadReportJob::dispatch(
                $upload,
                $result['created']->pluck('id')->all(),
                $result['updated']->pluck('id')->all(),
            );

            return [
                'upload'  => $upload,
                'created' => $result['created'],
                'updated' => $result['updated'],
            ];
        } catch (\Throwable $e) {
            $upload->update(['status' => 'failed']);
            throw $e;
        }
    }

    private function resolveTeam(UploadTaskDataRequest $request, User $uploader): Team
    {
        $team = Team::findOrFail($request->teamId());

        $uploaderOrgIds = $uploader->organizations()->pluck('organizations.id');
        if (!$uploaderOrgIds->contains($team->organization_id)) {
            throw new AppException(
                'Selected team is not in your organization',
                'TEAM_ORG_MISMATCH',
                403,
            );
        }

        return $team;
    }
}
