<?php

namespace Tests\Unit\Transcript;

use App\Services\OpenRouterClient;
use App\Services\Transcript\TranscriptPatternDetector;
use App\Services\Transcript\TranscriptSpecCache;
use App\Services\Transcript\TranscriptSpecLlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cache + LLM-mock integration tests for the orchestrator.
 *
 * `Cache::store('array')` driver is used by default in `phpunit.xml` (testing env),
 * so put/get works in-memory without needing Redis in tests.
 */
class TranscriptPatternDetectorTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_LLM_RESPONSE = <<<'JSON'
{
  "format": "custom_txt",
  "spec": {
    "speaker_extraction": "before_colon",
    "speaker_delimiter": ">",
    "timestamp_format": "hh_mm_ss",
    "timestamp_location": "line_prefix_paren",
    "header_line_count": 4,
    "header_terminator": null,
    "comment_prefixes": [],
    "skip_empty_lines": true,
    "example_line": "(00:00:15) Anna > Hello"
  },
  "confidence": "high",
  "reasoning": "Header is 4 lines (title/date/participants/blank), entries use (HH:MM:SS) Speaker > text."
}
JSON;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function returns_spec_on_happy_path_and_caches_it(): void
    {
        $this->mockLlm(self::VALID_LLM_RESPONSE, expectedCalls: 1);

        $detector = $this->app->make(TranscriptPatternDetector::class);

        $contents = "Meeting: Tribes\nDate: 2026-05-25\nParticipants: Anna\n\n(00:00:15) Anna > Hello\n";
        $spec = $detector->detect($contents);

        $this->assertNotNull($spec);
        $this->assertSame('before_colon', $spec->speakerExtraction);
        $this->assertSame('>', $spec->speakerDelimiter);
        $this->assertSame('hh_mm_ss', $spec->timestampFormat);
        $this->assertSame(4, $spec->headerLineCount);
    }

    #[Test]
    public function second_call_with_same_content_uses_cache_no_llm(): void
    {
        $this->mockLlm(self::VALID_LLM_RESPONSE, expectedCalls: 1);

        $detector = $this->app->make(TranscriptPatternDetector::class);
        $contents = "Meeting: Tribes\nDate: 2026-05-25\nParticipants: Anna\n\n(00:00:15) Anna > Hello\n";

        $first  = $detector->detect($contents);
        $second = $detector->detect($contents);

        $this->assertEquals($first->toArray(), $second->toArray());
    }

    #[Test]
    public function returns_null_on_llm_failure(): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('upstream failed'));
        $this->app->instance(OpenRouterClient::class, $mock);

        $detector = $this->app->make(TranscriptPatternDetector::class);
        $spec = $detector->detect("anything");

        $this->assertNull($spec);
    }

    #[Test]
    public function returns_null_when_schema_validation_fails(): void
    {
        // speaker_extraction = bogus value
        $this->mockLlm(json_encode([
            'spec' => [
                'speaker_extraction' => 'totally_invalid',
                'speaker_delimiter' => ':',
                'timestamp_format' => 'none',
                'timestamp_location' => 'none',
                'comment_prefixes' => [],
                'skip_empty_lines' => true,
                'example_line' => 'Anna: hi',
            ],
        ]));

        $detector = $this->app->make(TranscriptPatternDetector::class);
        $this->assertNull($detector->detect("anything"));
    }

    #[Test]
    public function returns_null_when_example_line_fails_round_trip(): void
    {
        // Spec says timestamp_location=line_prefix_bracketed but example_line has no timestamp.
        $this->mockLlm(json_encode([
            'spec' => [
                'speaker_extraction' => 'before_colon',
                'speaker_delimiter' => ':',
                'timestamp_format' => 'hh_mm',
                'timestamp_location' => 'line_prefix_bracketed',
                'comment_prefixes' => [],
                'skip_empty_lines' => true,
                'example_line' => 'Anna: hi (no timestamp)',
            ],
        ]));

        $detector = $this->app->make(TranscriptPatternDetector::class);
        $this->assertNull($detector->detect("anything"));
    }

    #[Test]
    public function strips_gemini_text_preamble_before_decoding(): void
    {
        $raw = "Here is the spec you requested:\n\n" . self::VALID_LLM_RESPONSE . "\n\nHope this helps!";
        $this->mockLlm($raw);

        $detector = $this->app->make(TranscriptPatternDetector::class);
        $spec = $detector->detect("Meeting: Tribes\nDate: 2026-05-25\nParticipants: Anna\n\n(00:00:15) Anna > Hello\n");

        $this->assertNotNull($spec);
    }

    #[Test]
    public function invalidate_drops_cached_entry(): void
    {
        $this->mockLlm(self::VALID_LLM_RESPONSE, expectedCalls: 2);

        $detector = $this->app->make(TranscriptPatternDetector::class);
        $contents = "Meeting: Tribes\nDate: 2026-05-25\nParticipants: Anna\n\n(00:00:15) Anna > Hello\n";

        $detector->detect($contents);                // cache write
        $detector->invalidate($contents);            // cache forget
        $detector->detect($contents);                // cache miss → LLM call again
    }

    /**
     * @param  int  $expectedCalls  set to null to skip assertion (any number of calls accepted)
     */
    private function mockLlm(string $response, ?int $expectedCalls = null): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $expectation = $mock->shouldReceive('chat');
        if ($expectedCalls !== null) {
            $expectation->times($expectedCalls);
        }
        $expectation->andReturn($response);
        $this->app->instance(OpenRouterClient::class, $mock);
    }
}
