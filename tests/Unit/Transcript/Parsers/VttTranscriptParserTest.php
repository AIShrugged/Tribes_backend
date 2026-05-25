<?php

namespace Tests\Unit\Transcript\Parsers;

use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\Parsers\VttTranscriptParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VttTranscriptParserTest extends TestCase
{
    private VttTranscriptParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new VttTranscriptParser();
    }

    #[Test]
    public function parses_voice_tags_and_speakerless_cue(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/transcripts/sample.vtt');
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
    public function ignores_note_and_style_blocks(): void
    {
        $contents = "WEBVTT\n\nNOTE a comment\n\nSTYLE\n::cue { color: red }\n\n00:00:00.000 --> 00:00:02.000\n<v Alice>Hi</v>\n";
        $result = $this->parser->parse($contents);

        $this->assertCount(1, $result['entries']);
        $this->assertSame('Alice', $result['entries'][0]->speaker);
    }

    #[Test]
    public function handles_multiple_voice_tags_per_cue(): void
    {
        $contents = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\n<v Alice>Hi</v> <v Bob>Hello</v>\n";
        $result = $this->parser->parse($contents);

        $this->assertCount(2, $result['entries']);
        $this->assertSame('Alice', $result['entries'][0]->speaker);
        $this->assertSame('Bob', $result['entries'][1]->speaker);
    }

    #[Test]
    public function throws_on_file_with_no_cues(): void
    {
        $this->expectException(TranscriptParseException::class);
        $this->parser->parse("WEBVTT\n\nNOTE just a comment, no cues\n");
    }
}
