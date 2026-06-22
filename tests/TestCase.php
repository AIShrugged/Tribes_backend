<?php

namespace Tests;

use Illuminate\Foundation\Testing\Concerns\InteractsWithContainer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use PDOException;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithContainer;

    protected bool $mockLlm = true;

    protected static bool $testingDatabasePrepared = false;

    protected function setUp(): void
    {
        $this->guardAgainstNonTestDatabase();
        $this->ensureTestingDatabaseExists();

        parent::setUp();

        if (! $this->mockLlm) {
            return;
        }

        Http::fake([
            'openrouter.ai/*' => fn (Request $request) => $this->fakeLlmResponse($request),
        ]);
    }

    private function fakeLlmResponse(Request $request)
    {
        $payload = $request->data();
        $messages = $payload['messages'] ?? [];
        $prompt = collect($messages)
            ->pluck('content')
            ->filter(fn ($content) => is_string($content))
            ->implode("\n\n");

        if (
            str_contains($prompt, 'frontend/backend') &&
            str_contains($prompt, 'Return ONLY valid JSON object') &&
            str_contains($prompt, '"steps"')
        ) {
            return Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->fakeLlmContent($payload, $prompt),
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200);
        }

        if (! empty($payload['tools'])) {
            return Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Mocked testing response',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200);
        }

        return Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $this->fakeLlmContent($payload, $prompt),
                ],
                'finish_reason' => 'stop',
            ]],
        ], 200);
    }

    private function fakeLlmContent(array $payload, string $prompt): string
    {
        if (
            str_contains($prompt, 'frontend/backend') &&
            str_contains($prompt, 'Return ONLY valid JSON object') &&
            str_contains($prompt, '"steps"')
        ) {
            return json_encode([
                'goal' => 'Deliver the requested development issue through a sequenced agent workflow.',
                'steps' => [
                    [
                        'title' => 'Inspect and plan',
                        'prompt' => 'Review the issue context, identify the relevant code paths, and prepare the implementation approach.',
                        'acceptance_criteria' => [
                            'Relevant repository area identified',
                            'Implementation approach is clear',
                        ],
                        'output_mode' => 'md',
                    ],
                    [
                        'title' => 'Implement and verify',
                        'prompt' => 'Apply the requested fix using the previous step output as input, then verify the result with tests or validation.',
                        'acceptance_criteria' => [
                            'Code changes are applied',
                            'Validation is completed',
                        ],
                        'output_mode' => 'md',
                    ],
                ],
            ]);
        }

        $forceJson = ($payload['response_format']['type'] ?? null) === 'json_object';

        if (! $forceJson) {
            return 'Mocked LLM response';
        }

        if (str_contains($prompt, '"new_tasks"') && str_contains($prompt, '"status_updates"')) {
            return json_encode([
                'new_tasks' => [],
                'status_updates' => [],
            ]);
        }

        if (str_contains($prompt, '"participants"') && str_contains($prompt, '"relationships"')) {
            return json_encode([
                'participants' => [],
                'relationships' => [],
            ]);
        }

        if (str_contains($prompt, 'Return ONLY a JSON array of relevant category names')) {
            return json_encode([]);
        }

        if (str_contains($prompt, '"relationship_type": "collaborative|conflicting|hierarchical|neutral"')) {
            return json_encode([
                'summary' => 'Mocked relationship summary',
                'key_observations' => [],
                'positive_interactions' => [],
                'negative_interactions' => [],
                'relationship_type' => 'neutral',
            ]);
        }

        if (str_contains($prompt, '"title": "Краткое название встречи') || str_contains($prompt, '"key_points"')) {
            return json_encode([
                'title' => 'Mocked Meeting',
                'summary' => 'Mocked summary',
                'key_points' => [],
                'decisions' => [],
            ]);
        }

        if (str_contains($prompt, 'Return a JSON array of tasks in the following format')) {
            return json_encode([]);
        }

        if (str_contains($prompt, 'Return a JSON array in the following format') && str_contains($prompt, 'participant_id')) {
            return json_encode([]);
        }

        if (str_contains($prompt, 'ID будущего артефакта') || str_contains($prompt, 'methodology_criteria')) {
            preg_match('/ID будущего артефакта:\s*([a-zA-Z0-9_\-]+)/', $prompt, $matches);
            $artifactId = $matches[1] ?? 'followup_mock';

            return json_encode([
                'artifacts' => [
                    $artifactId => [
                        'id' => $artifactId,
                        'type' => 'methodology_criteria',
                        'title' => 'Mocked followup',
                        'data' => [
                            'blocks' => [
                                [
                                    'type' => 'header',
                                    'text' => 'Mocked followup',
                                ],
                                [
                                    'type' => 'progress_summary',
                                    'items' => [
                                        [
                                            'label' => 'Общий балл',
                                            'value' => 42,
                                            'max' => 72,
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'scoring_table',
                                    'columns' => ['Метрика', 'Балл', 'Макс.', 'Комментарий'],
                                    'rows' => [
                                        ['Small talk', 3, 4, 'Good'],
                                    ],
                                ],
                                [
                                    'type' => 'text_list',
                                    'title' => 'Сильные стороны',
                                    'items' => ['Mocked strength'],
                                ],
                                [
                                    'type' => 'text_list',
                                    'title' => 'Зоны для развития',
                                    'items' => ['Mocked area'],
                                ],
                                [
                                    'type' => 'text_list',
                                    'title' => 'План действий',
                                    'items' => ['Mocked action'],
                                ],
                            ],
                        ],
                        'status' => 'ready',
                    ],
                ],
                'layout' => [
                    'items' => [
                        ['id' => $artifactId],
                    ],
                ],
            ]);
        }

        if (
            str_contains($prompt, 'concrete tasks from work meeting transcripts')
            || str_contains($prompt, 'actionable issues')
            || str_contains($prompt, 'извлечения actionable issues')
        ) {
            return json_encode([
                'issues' => [
                    [
                        'name' => 'Исправить баг в авторизации',
                        'description' => 'При логине через Google OAuth не сохраняется сессия',
                        'type' => 'backend',
                        'assignee_name' => 'John Doe',
                    ],
                    [
                        'name' => 'Добавить экспорт отчётов в PDF',
                        'description' => 'Нужно добавить кнопку экспорта на странице отчётов',
                        'type' => 'organization',
                        'assignee_name' => null,
                    ],
                ],
            ]);
        }

        return json_encode(new \stdClass());
    }

    /**
     * Safety net: RefreshDatabase runs migrate:fresh on the CONFIGURED database.
     * A stale config cache (bootstrap/cache/config.php) makes that the real DEV
     * database instead of the phpunit-forced test DB, which silently wipes dev
     * data. Abort loudly before any migration if the configured DB is not the
     * expected test DB. (Use `composer test` — it clears the config cache first.)
     */
    private function guardAgainstNonTestDatabase(): void
    {
        // Runs BEFORE the app boots, so read the cached config FILE directly
        // (config()/the container are not available yet) and compare against the
        // phpunit-forced test DB from env. A stale cache would make
        // RefreshDatabase migrate:fresh the real dev database — abort loudly.
        if ((string) env('DB_CONNECTION') !== 'pgsql') {
            return;
        }

        $cacheFile = __DIR__.'/../bootstrap/cache/config.php';
        if (! is_file($cacheFile)) {
            return; // no cache → config is built from env at boot → safe
        }

        $cached = @include $cacheFile;
        $configured = $cached['database']['connections']['pgsql']['database'] ?? null;
        $expected = (string) env('DB_DATABASE', '');

        if ($configured !== null && $configured !== $expected) {
            throw new \RuntimeException(
                "Refusing to run tests: cached config DB [{$configured}] != test DB [{$expected}]. "
                .'A stale config cache would wipe a non-test database. Run '
                .'`php artisan config:clear` (or use `composer test`) before running tests.'
            );
        }
    }

    private function ensureTestingDatabaseExists(): void
    {
        if (self::$testingDatabasePrepared) {
            return;
        }

        if ((string) env('DB_CONNECTION') !== 'pgsql') {
            self::$testingDatabasePrepared = true;

            return;
        }

        $database = (string) env('DB_DATABASE', '');
        if ($database === '') {
            self::$testingDatabasePrepared = true;

            return;
        }

        try {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                (string) env('DB_HOST', 'postgres'),
                (string) env('DB_PORT', '5432'),
                (string) env('DB_ROOT_DATABASE', 'postgres'),
            );

            $pdo = new PDO(
                $dsn,
                (string) env('DB_USERNAME', 'root'),
                (string) env('DB_PASSWORD', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :database');
            $statement->execute(['database' => $database]);

            if ($statement->fetchColumn() === false) {
                $pdo->exec(sprintf('CREATE DATABASE "%s"', str_replace('"', '""', $database)));
            }

            self::$testingDatabasePrepared = true;
        } catch (PDOException $exception) {
            throw new \RuntimeException(
                sprintf('Unable to prepare PostgreSQL test database [%s]: %s', $database, $exception->getMessage()),
                previous: $exception,
            );
        }
    }
}
