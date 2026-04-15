<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\v1\IssueCommentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;
use Illuminate\Http\Request;

class IssueCommentController extends Controller
{
    public function index(Request $request, int $issue): ApiResponse
    {
        $this->findVisibleIssue($request->user(), $issue);

        $comments = IssueComment::query()
            ->where('issue_id', $issue)
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->orderBy('created_at')
            ->get();

        return ApiResponse::list(IssueCommentResource::collection($comments), $comments->count());
    }

    public function store(Request $request, int $issue): ApiResponse
    {
        $this->findVisibleIssue($request->user(), $issue);

        $data = $request->validate([
            'content'   => ['required', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'integer', 'exists:issue_comments,id'],
        ]);

        if (!empty($data['parent_id'])) {
            $parent = IssueComment::findOrFail($data['parent_id']);
            abort_if($parent->issue_id !== $issue, 422, 'Parent comment does not belong to this issue.');
            abort_if($parent->parent_id !== null, 422, 'Cannot reply to a reply.');
        }

        $comment = IssueComment::create([
            'issue_id'  => $issue,
            'user_id'   => $request->user()->id,
            'parent_id' => $data['parent_id'] ?? null,
            'content'   => $data['content'],
        ]);

        $comment->load('user');

        return ApiResponse::success(data: IssueCommentResource::make($comment), status: 201);
    }

    public function update(Request $request, int $comment): ApiResponse
    {
        $record = $this->findOwnComment($request->user(), $comment);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
        ]);

        $record->update(['content' => $data['content']]);

        $record->load('user');

        return ApiResponse::success(data: IssueCommentResource::make($record));
    }

    public function destroy(Request $request, int $comment): ApiResponse
    {
        $record = $this->findOwnComment($request->user(), $comment);
        $record->delete();

        return ApiResponse::success();
    }

    private function findVisibleIssue(User $user, int $issueId): Issue
    {
        return Issue::query()->visibleTo($user)->findOrFail($issueId);
    }

    private function findOwnComment(User $user, int $commentId): IssueComment
    {
        return IssueComment::query()
            ->where('user_id', $user->id)
            ->findOrFail($commentId);
    }
}
