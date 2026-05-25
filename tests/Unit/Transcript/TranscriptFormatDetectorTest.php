<?php

namespace Tests\Unit\Transcript;

use App\Services\Transcript\Exceptions\UnsupportedFormatException;
use App\Services\Transcript\TranscriptContentNormalizer;
use App\Services\Transcript\TranscriptFormat;
use App\Services\Transcript\TranscriptFormatDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TranscriptFormatDetectorTest extends TestCase
{
    private TranscriptFormatDetector $detector;
    private TranscriptContentNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector  = new TranscriptFormatDetector();
        $this->normalizer = new TranscriptContentNormalizer();
    }

    #[Test]
    public function detects_recall_json_by_structure(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/transcripts/recall_sample.json');
        $this->assertSame(TranscriptFormat::RECALL_JSON, $this->detector->detect($contents));
    }

    #[Test]
    public function detects_vtt_by_header(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/transcripts/sample.vtt');
        $this->assertSame(TranscriptFormat::VTT, $this->detector->detect($contents));
    }

    #[Test]
    public function detects_srt_by_index_plus_arrow(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/transcripts/sample.srt');
        $this->assertSame(TranscriptFormat::SRT, $this->detector->detect($contents));
    }

    #[Test]
    public function detects_plain_txt_as_fallback(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/transcripts/plain_simple.txt');
        $this->assertSame(TranscriptFormat::TXT, $this->detector->detect($contents));
    }

    #[Test]
    public function detects_timestamped_txt_as_txt(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/transcripts/plain_with_timestamps.txt');
        $this->assertSame(TranscriptFormat::TXT, $this->detector->detect($contents));
    }

    #[Test]
    public function recognises_recall_json_after_bom_strip(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/transcripts/recall_sample.json');
        $withBom  = "\xEF\xBB\xBF" . $contents;
        $normalised = $this->normalizer->normalize($withBom);

        $this->assertSame(TranscriptFormat::RECALL_JSON, $this->detector->detect($normalised));
    }

    #[Test]
    public function json_array_with_wrong_shape_falls_through_to_txt(): void
    {
        // Valid JSON, but not Recall-shaped — treat as TXT and let the parser fail
        // explicitly rather than silently misclassifying.
        $contents = '[{"foo": "bar"}]';
        $this->assertSame(TranscriptFormat::TXT, $this->detector->detect($contents));
    }

    #[Test]
    public function empty_file_throws(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->detector->detect('');
    }

    #[Test]
    public function whitespace_only_throws(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->detector->detect("\n\n   \n");
    }
}
