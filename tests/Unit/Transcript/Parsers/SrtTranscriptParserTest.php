<?php

namespace Tests\Unit\Transcript\Parsers;

use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\Parsers\SrtTranscriptParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SrtTranscriptParserTest extends TestCase
{
    private SrtTranscriptParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SrtTranscriptParser();
    }

    #[Test]
    public function parses_speakers_with_prefix_and_speakerless_cue(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/transcripts/sample.srt');
        $result = $this->parser->parse($contents);

        $this->assertContains('Анна', $result['speakers']);
        $this->assertContains('Пётр', $result['speakers']);
        $this->assertContains('Unknown', $result['speakers']);
        $this->assertCount(3, $result['entries']);

        $this->assertSame('Анна', $result['entries'][0]->speaker);
        $this->assertSame('Всем привет, давайте начнём.', $result['entries'][0]->paragraph);
        $this->assertSame(0.0, $result['entries'][0]->startRelative);
        $this->assertSame(4.0, $result['entries'][0]->endRelative);
    }

    #[Test]
    public function treats_long_pre_colon_as_body_not_speaker(): void
    {
        $contents = "1\n00:00:00,000 --> 00:00:02,000\nThis sentence has punctuation. Apparently colon: comes later\n";
        $result = $this->parser->parse($contents);

        $this->assertSame('Unknown', $result['entries'][0]->speaker);
    }

    #[Test]
    public function throws_on_file_with_no_cues(): void
    {
        $this->expectException(TranscriptParseException::class);
        $this->parser->parse("\n\n");
    }
}
