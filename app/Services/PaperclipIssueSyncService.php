<?php

namespace App\Services;

use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\IssueAttachment;
use App\Models\IssueComment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaperclipIssueSyncService
{
    public function sync(
        Issue $issue,
        AgentTaskRun $run,
        string $runStatus,
        ?string $comment,
        array $artifacts = [],
    ): void {
        $this->syncStatus($issue, $runStatus);
        $this->syncComment($issue, $run, $runStatus, $comment);
        $this->syncAttachments($issue, $artifacts);
    }

    private function syncStatus(Issue $issue, string $runStatus): void
    {
        $issueStatus = match ($runStatus) {
            'done' => 'done',
            'blocked' => 'paused',
            'failed' => 'open',
            default => null,
        };

        if (! $issueStatus || $issue->status === $issueStatus) {
            return;
        }

        $issue->update([
            'status' => $issueStatus,
        ]);
    }

    private function syncComment(Issue $issue, AgentTaskRun $run, string $runStatus, ?string $comment): void
    {
        $body = trim((string) $comment);

        if ($body === '') {
            return;
        }

        $paperclipUserId = (int) ($issue->user_id ?? $run->task?->user_id ?? 0);

        $prefix = match ($runStatus) {
            'done' => 'Paperclip завершил задачу.',
            'blocked' => 'Paperclip заблокирован.',
            'failed' => 'Paperclip завершился с ошибкой.',
            default => 'Paperclip status update.',
        };

        IssueComment::create([
            'issue_id' => $issue->id,
            'user_id' => $paperclipUserId,
            'content' => trim($prefix."\n\n".$body),
        ]);
    }

    private function syncAttachments(Issue $issue, array $artifacts): void
    {
        if ($artifacts === []) {
            return;
        }

        $disk = $this->attachmentDisk();
        $saved = 0;

        foreach ($artifacts as $index => $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $filename = $this->artifactFilename($artifact, $index);
            $payload = $this->artifactPayload($artifact);
            if ($payload === null) {
                $payload = $this->artifactFallbackPayload($artifact, $filename);
            }
            if ($payload === null) {
                continue;
            }

            $path = "issues/{$issue->id}/paperclip/{$filename}";
            Storage::disk($disk)->put($path, $payload);

            IssueAttachment::create([
                'issue_id' => $issue->id,
                'file_path' => $path,
                'uploaded_at' => now(),
            ]);

            $saved++;
        }

        if ($saved > 0) {
            Log::info('Paperclip issue attachments synced', [
                'issue_id' => $issue->id,
                'count' => $saved,
            ]);
        }
    }

    private function artifactFilename(array $artifact, int $index): string
    {
        $filename = (string) ($artifact['filename'] ?? $artifact['name'] ?? $artifact['title'] ?? '');
        $filename = trim($filename);

        if ($filename === '') {
            $filename = 'paperclip-artifact-'.($index + 1).'.txt';
        }

        $filename = basename($filename);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'paperclip-artifact.txt';

        return $filename;
    }

    private function artifactPayload(array $artifact): ?string
    {
        foreach (['content_base64', 'base64', 'content'] as $key) {
            $value = $artifact[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if ($key !== 'content') {
                $decoded = base64_decode($value, true);
                if ($decoded !== false) {
                    return $decoded;
                }
            }

            return $value;
        }

        if (isset($artifact['url']) && is_string($artifact['url']) && trim($artifact['url']) !== '') {
            return "Paperclip artifact URL: ".trim($artifact['url']);
        }

        return null;
    }

    private function artifactFallbackPayload(array $artifact, string $filename): ?string
    {
        if ($filename === '') {
            return null;
        }

        return json_encode([
            'filename' => $filename,
            'artifact' => $artifact,
            'note' => 'Paperclip did not provide file contents; stored metadata placeholder instead.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function attachmentDisk(): string
    {
        return (string) config('filesystems.issue_attachments_disk', config('filesystems.default', 'local'));
    }
}
