<?php

namespace App\Jobs;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Services\InlineAgentTaskExecutor;
use App\Services\IsolatedAgentTaskExecutor;
use App\Services\SandboxRunWorkspaceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Telegram\Bot\Api;

class RunAgentTaskJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3900;

    public function __construct(
        public int $agentTaskId,
        public int $agentTaskRunId,
        public int $maxAttempts,
    ) {
        $this->onQueue('agent-tasks');
    }

    public function tries(): int
    {
        return max(1, $this->maxAttempts);
    }

    public function backoff(): array
    {
        return array_values((array) config('agent.agent_tasks.backoff_seconds', [30, 120]));
    }

    public function handle(
        InlineAgentTaskExecutor $inlineExecutor,
        IsolatedAgentTaskExecutor $isolatedExecutor,
        SandboxRunWorkspaceService $sandboxRunWorkspaceService,
    ): void
    {
        $task = AgentTask::find($this->agentTaskId);
        $run = AgentTaskRun::find($this->agentTaskRunId);

        if (! $task || ! $run) {
            return;
        }

        if (! $task->enabled && $task->isOneOff()) {
            return;
        }

        $attempt = $this->attempts();

        $run->update([
            'status' => AgentTaskRunStatus::PROCESSING->value,
            'attempt' => $attempt,
            'started_at' => $run->started_at ?? now(),
            'error_message' => null,
        ]);

        $task->update([
            'last_run_at' => now(),
            'last_error' => null,
        ]);

        try {
            $response = $task->isIsolated()
                ? $isolatedExecutor->execute($task, $run)
                : $inlineExecutor->execute($task, $run);

            $run->update([
                'status' => AgentTaskRunStatus::COMPLETED->value,
                'output' => $response,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            $this->saveTestResults($run, $sandboxRunWorkspaceService);

            $nextRunAt = $task->nextRunFrom($run->scheduled_for ?? now());

            $task->update([
                'enabled' => $task->isOneOff() ? false : $task->enabled,
                'next_run_at' => $nextRunAt,
                'last_completed_at' => now(),
                'last_failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
            ]);

            $this->sendTelegramNotification($task, $run, 'completed');
            $sandboxRunWorkspaceService->cleanup($run);
        } catch (\Throwable $e) {
            $terminal = $attempt >= $this->tries();

            $run->update([
                'status' => $terminal ? AgentTaskRunStatus::FAILED->value : AgentTaskRunStatus::QUEUED->value,
                'attempt' => $attempt,
                'error_message' => $e->getMessage(),
                'finished_at' => $terminal ? now() : null,
            ]);

            if ($terminal) {
                $task->update([
                    'enabled' => $task->isOneOff() ? false : $task->enabled,
                    'next_run_at' => $task->isInterval() ? $task->nextRunFrom($run->scheduled_for ?? now()) : null,
                    'last_failed_at' => now(),
                    'last_error' => $e->getMessage(),
                    'locked_at' => null,
                ]);

                $this->sendTelegramNotification($task, $run, 'failed', $e->getMessage());
            }

            $sandboxRunWorkspaceService->cleanup($run);

            throw $e;
        }
    }

    public function failed(?\Throwable $e = null): void
    {
        $task = AgentTask::find($this->agentTaskId);
        $run = AgentTaskRun::find($this->agentTaskRunId);
        $sandboxRunWorkspaceService = app(SandboxRunWorkspaceService::class);

        if (! $task || ! $run) {
            return;
        }

        if ($run->status !== AgentTaskRunStatus::FAILED) {
            $run->update([
                'status' => AgentTaskRunStatus::FAILED->value,
                'finished_at' => $run->finished_at ?? now(),
                'error_message' => $e?->getMessage() ?? $run->error_message,
            ]);
        }

        $task->update([
            'last_failed_at' => now(),
            'last_error' => $e?->getMessage() ?? $task->last_error,
            'locked_at' => null,
            'next_run_at' => $task->isInterval()
                ? $task->nextRunFrom($run->scheduled_for ?? now())
                : $task->next_run_at,
        ]);

        $sandboxRunWorkspaceService->cleanup($run);
    }

    private function saveTestResults(AgentTaskRun $run, SandboxRunWorkspaceService $sandboxRunWorkspaceService): void
    {
        $sandboxResult = $run->metadata['sandbox_result'] ?? null;
        if (! $sandboxResult) {
            return;
        }

        $actions = collect($sandboxResult['actions'] ?? []);
        $testActions = $actions->filter(fn ($a) => str_contains($a['evidence']['command'] ?? '', 'artisan test')
            || str_contains($a['evidence']['command'] ?? '', 'npx jest'));

        $parsed = null;
        foreach ($testActions as $ta) {
            $parsed = $this->parseTestArtifact($ta, $run, $sandboxRunWorkspaceService);
            if ($parsed) {
                break;
            }
        }

        if (! $parsed) {
            $testAction = $testActions->last();
            if (! $testAction) {
                return;
            }
            $exitCode = $testAction['evidence']['exit_code'] ?? null;
            $parsed = [
                'total' => null,
                'passed' => null,
                'failed' => null,
                'failed_tests' => [],
                'exit_code' => $exitCode,
                'status' => $exitCode === 0 ? 'passed' : 'failed',
            ];
        } else {
            $parsed['status'] = $parsed['failed'] === 0 ? 'passed' : 'failed';
        }

        $run->update([
            'metadata' => [
                ...($run->metadata ?? []),
                'test_results' => $parsed,
            ],
        ]);
    }

    private function sendTelegramNotification(AgentTask $task, AgentTaskRun $run, string $status, ?string $errorMessage = null): void
    {
        if (! $task->notification_telegram_chat_id) {
            return;
        }

        try {
            $duration = $run->started_at && $run->finished_at
                ? $run->started_at->diffForHumans($run->finished_at, true)
                : 'unknown';

            $emoji = $status === 'completed' ? "\xE2\x9C\x85" : "\xE2\x9D\x8C";

            $lines = [
                "{$emoji} *Agent Task #{$task->id}: {$status}*",
                "",
                "*Task:* {$task->name}",
                "*Duration:* {$duration}",
                "*Run:* #{$run->id}, attempt {$run->attempt}",
            ];

            $sandboxResult = $run->metadata['sandbox_result'] ?? null;

            if ($sandboxResult) {
                $lines = array_merge($lines, $this->formatSandboxResult($sandboxResult, $run));
            } elseif ($status === 'completed' && $run->output) {
                $lines[] = "";
                $lines[] = Str::limit($run->output, 800);
            }

            if ($errorMessage) {
                $lines[] = "";
                $lines[] = "*Error:* ".Str::limit($errorMessage, 400);
            }

            $text = implode("\n", $lines);

            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id' => $task->notification_telegram_chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ];

            if ($task->notification_telegram_thread_id) {
                $params['message_thread_id'] = $task->notification_telegram_thread_id;
            }

            try {
                $telegram->sendMessage($params);
            } catch (\Throwable $e) {
                if (str_contains(mb_strtolower($e->getMessage()), "can't parse entities")
                    || str_contains(mb_strtolower($e->getMessage()), 'cant parse entities')) {
                    unset($params['parse_mode']);
                    $telegram->sendMessage($params);
                } else {
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send AgentTask Telegram notification', [
                'agent_task_id' => $task->id,
                'chat_id' => $task->notification_telegram_chat_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatSandboxResult(array $sandboxResult, AgentTaskRun $run): array
    {
        $lines = [];
        $actions = collect($sandboxResult['actions'] ?? []);

        $completed = $actions->where('status', 'completed')->count();
        $total = $actions->count();
        $lines[] = "";
        $lines[] = "*Steps:* {$completed}/{$total}";

        $testActions = $actions->filter(fn ($a) => str_contains($a['evidence']['command'] ?? '', 'artisan test')
            || str_contains($a['evidence']['command'] ?? '', 'npx jest'));

        $parsed = $run->metadata['test_results'] ?? null;
        if (! $parsed) {
            foreach ($testActions as $ta) {
                $parsed = $this->parseTestArtifact($ta, $run, app(SandboxRunWorkspaceService::class));
                if ($parsed) {
                    break;
                }
            }
        }

        $testAction = $testActions->last();

        if ($testAction) {

            if ($parsed && $parsed['total'] !== null) {
                $emoji = $parsed['failed'] === 0 ? "\xE2\x9C\x85" : "\xE2\x9A\xA0\xEF\xB8\x8F";
                $lines[] = "*Tests:* {$emoji} {$parsed['passed']}/{$parsed['total']} passed, {$parsed['failed']} failed";

                if (! empty($parsed['failed_tests'])) {
                    $lines[] = "";
                    $lines[] = "*Failed:*";
                    foreach (array_slice($parsed['failed_tests'], 0, 15) as $test) {
                        $lines[] = "\xE2\x80\xA2 `{$test}`";
                    }
                }
            } else {
                $exitCode = $testAction['evidence']['exit_code'] ?? null;
                $emoji = $exitCode === 0 ? "\xE2\x9C\x85" : "\xE2\x9A\xA0\xEF\xB8\x8F";
                $label = match ($exitCode) {
                    0 => 'all passed',
                    1 => 'errors',
                    2 => 'failures',
                    default => "exit code {$exitCode}",
                };
                $lines[] = "*Tests:* {$emoji} {$label}";
            }
        }

        return $lines;
    }

    private function parseTestArtifact(array $testAction, AgentTaskRun $run, SandboxRunWorkspaceService $sandboxRunWorkspaceService): ?array
    {
        $artifactPath = $testAction['evidence']['stdout_artifact'] ?? null;
        if (! $artifactPath) {
            return null;
        }

        $filename = basename($artifactPath);
        $localPath = $sandboxRunWorkspaceService->pathForRun($run).'/artifacts/'.$filename;

        if (! file_exists($localPath)) {
            return null;
        }

        $content = (string) file_get_contents($localPath);

        // Parse PHPUnit: "Tests: 5 failed, 207 passed (756 assertions)"
        // Parse Jest:    "Tests: 3 failed, 1275 passed, 1278 total"
        if (preg_match('/Tests:\s+(?:(\d+)\s+failed,\s+)?(\d+)\s+passed/i', $content, $m)) {
            $failed = (int) ($m[1] ?? 0);
            $passed = (int) $m[2];
        } else {
            return null;
        }

        // Extract failed test names and error descriptions
        $failedTests = [];
        $contentLines = explode("\n", $content);
        for ($i = 0; $i < count($contentLines); $i++) {
            $line = $contentLines[$i];

            // PHPUnit: "  FAILED  Tests\Feature\ExampleTest > method  RuntimeException"
            if (preg_match('/^\s*FAILED\s+Tests\\\\(.+)/i', $line, $fm)) {
                $raw = trim('Tests\\' . $fm[1]);
                $parts = preg_split('/\s{2,}/', $raw, 2);
                $testName = $parts[0] ?? $raw;
                $exceptionType = $parts[1] ?? '';

                $nextLine = isset($contentLines[$i + 1]) ? trim($contentLines[$i + 1]) : '';
                $isDescriptionLine = $nextLine !== ''
                    && ! str_starts_with($nextLine, 'FAILED')
                    && ! str_starts_with($nextLine, 'Tests:')
                    && ! str_starts_with($nextLine, 'Duration:')
                    && $nextLine !== '--'
                    && ! str_starts_with($nextLine, '──');

                if ($isDescriptionLine) {
                    $failedTests[] = "{$testName}: {$nextLine}";
                } elseif ($exceptionType) {
                    $failedTests[] = "{$testName} ({$exceptionType})";
                } else {
                    $failedTests[] = $testName;
                }
                continue;
            }

            // Jest: "FAIL features/teams/ui/__tests__/team-list.test.tsx"
            if (preg_match('/^FAIL\s+(.+)/i', $line, $jm)) {
                $failedTests[] = trim($jm[1]);
            }
        }

        return [
            'total' => $passed + $failed,
            'passed' => $passed,
            'failed' => $failed,
            'failed_tests' => $failedTests,
        ];
    }
}
