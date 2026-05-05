<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\IssueRequest;
use App\Http\Requests\API\v1\StoreOrphanAttachmentRequest;
use App\Http\Resources\API\v1\IssueAttachmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\IssueAttachment;
use App\Models\Issue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IssueAttachmentController extends Controller
{
    public function store(IssueRequest $request, int $issue): ApiResponse
    {
        $task = $this->findVisibleIssue($request->user(), $issue);
        $disk = $this->attachmentDisk();

        $path = $request->file('file')->store("issues/{$task->id}", $disk);

        if ($path === false) {
            return ApiResponse::error('Failed to store attachment', 500);
        }

        $attachment = $task->attachments()->create([
            'file_path' => $path,
            'uploaded_at' => now(),
        ]);

        return ApiResponse::success(data: IssueAttachmentResource::make($attachment), status: 201);
    }

    public function index(IssueRequest $request, int $issue): ApiResponse
    {
        $task = $this->findVisibleIssue($request->user(), $issue);
        $attachments = $task->attachments()->latest('id')->get();

        return ApiResponse::list(IssueAttachmentResource::collection($attachments), $attachments->count());
    }

    public function destroy(IssueRequest $request, int $attachment): ApiResponse
    {
        $record = IssueAttachment::query()
            ->whereHas('issue', fn ($query) => $query->visibleTo($request->user()))
            ->findOrFail($attachment);

        Storage::disk($this->attachmentDisk())->delete($record->file_path);
        $record->delete();

        return ApiResponse::success();
    }

    public function download(int $attachment): StreamedResponse
    {
        $record = IssueAttachment::query()->findOrFail($attachment);
        $disk = $this->attachmentDisk();

        if (!Storage::disk($disk)->exists($record->file_path)) {
            abort(404, 'Attachment file not found');
        }

        return Storage::disk($disk)->response(
            $record->file_path,
            basename($record->file_path),
            ['Content-Disposition' => 'inline; filename="'.basename($record->file_path).'"']
        );
    }

    public function downloadAuthenticated(Request $request, int $attachment): StreamedResponse
    {
        $record = IssueAttachment::query()
            ->whereHas('issue', fn ($query) => $query->visibleTo($request->user()))
            ->findOrFail($attachment);

        $disk = $this->attachmentDisk();

        if (!Storage::disk($disk)->exists($record->file_path)) {
            abort(404, 'Attachment file not found');
        }

        return Storage::disk($disk)->response(
            $record->file_path,
            basename($record->file_path),
            ['Content-Disposition' => 'inline; filename="'.basename($record->file_path).'"']
        );
    }

    public function storePending(StoreOrphanAttachmentRequest $request): ApiResponse
    {
        $token = $request->input('upload_token');
        $disk  = $this->attachmentDisk();
        $file  = $request->file('file');

        $blockedExtensions = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'htaccess', 'sh', 'bash', 'exe', 'bat', 'cmd'];
        if (in_array(strtolower($file->getClientOriginalExtension()), $blockedExtensions, true)) {
            return ApiResponse::error('File type not allowed', 422);
        }

        $path = $file->store("attachments/pending/{$token}", $disk);

        if ($path === false) {
            return ApiResponse::error('Failed to store attachment', 500);
        }

        $attachment = IssueAttachment::create([
            'file_path'           => $path,
            'issue_id'            => null,
            'upload_token'        => $token,
            'uploaded_by_user_id' => $request->user()->id,
            'uploaded_at'         => now(),
        ]);

        return ApiResponse::success(data: IssueAttachmentResource::make($attachment), status: 201);
    }

    public function destroyPending(Request $request, IssueAttachment $attachment): ApiResponse
    {
        $this->authorize('deletePending', $attachment);

        Storage::disk($this->attachmentDisk())->delete($attachment->file_path);
        $attachment->delete();

        return ApiResponse::success();
    }

    private function findVisibleIssue(User $user, int $issueId): Issue
    {
        return Issue::query()
            ->visibleTo($user)
            ->findOrFail($issueId);
    }

    private function attachmentDisk(): string
    {
        return (string) config('filesystems.issue_attachments_disk', config('filesystems.default', 'local'));
    }
}
