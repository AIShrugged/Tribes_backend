<?php

namespace Tests\Unit\Transcript\Parsers;

use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\Parsers\PlainTextTranscriptParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PlainTextTranscriptParserTest extends TestCase
{
    private PlainTextTranscriptParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PlainTextTranscriptParser();
    }

    #[Test]
    public function parses_flat_dialect_without_timings(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/transcripts/plain_simple.txt');
        $result = $this->parser->parse($contents);

        $this->assertSame(['Анна', 'Пётр', 'Мария'], $result['speakers']);
        $this->assertCount(4, $result['entries']);
        $this->assertSame('Анна', $result['entries'][0]->speaker);
        $this->assertSame('Всем привет, давайте начнём.', $result['entries'][0]->paragraph);
        $this->assertNull($result['entries'][0]->startRelative);
    }

    #[Test]
    public function parses_timestamped_dialect_with_comments_stripped(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/transcripts/plain_with_timestamps.txt');
        $result = $this->parser->parse($contents);

        $this->assertSame(['Анна', 'Пётр', 'Мария'], $result['speakers']);
        $this->assertCount(4, $result['entries']);

        // [14:00] → 14h 00m 00s = 50400s
        $this->assertSame(50400.0, $result['entries'][0]->startRelative);
        // [14:02:30] → 50550s
        $this->assertSame(50550.0, $result['entries'][2]->startRelative);
    }

    #[Test]
    public function splits_on_first_colon_only(): void
    {
        $result = $this->parser->parse("Dr. Smith: PhD perspective on the topic\n");
        $this->assertSame('Dr. Smith', $result['entries'][0]->speaker);
        $this->assertSame('PhD perspective on the topic', $result['entries'][0]->paragraph);
    }

    #[Test]
    public function drops_lines_without_colon(): void
    {
        $result = $this->parser->parse("Анна: реплика\nне реплика без двоеточия\nПётр: ответ\n");
        $this->assertCount(2, $result['entries']);
    }

    #[Test]
    public function drops_empty_speaker_lines(): void
    {
        $result = $this->parser->parse(": orphan text\nАнна: ok\n");
        $this->assertCount(1, $result['entries']);
        $this->assertSame('Анна', $result['entries'][0]->speaker);
    }

    #[Test]
    public function throws_when_no_entries_extracted(): void
    {
        $this->expectException(TranscriptParseException::class);
        $this->parser->parse("# only comments\n# nothing else\n");
    }
}
