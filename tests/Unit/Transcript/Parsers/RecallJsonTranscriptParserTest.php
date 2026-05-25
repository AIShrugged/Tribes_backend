<?php

namespace Tests\Unit\Transcript\Parsers;

use App\Services\RecallTranscriptParser;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\Parsers\RecallJsonTranscriptParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RecallJsonTranscriptParserTest extends TestCase
{
    private RecallJsonTranscriptParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new RecallJsonTranscriptParser(new RecallTranscriptParser());
    }

    #[Test]
    public function parses_valid_recall_fixture(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/transcripts/recall_sample.json');
        $result = $this->parser->parse($contents);

        $this->assertSame(['Анна', 'Пётр'], $result['speakers']);
        $this->assertCount(2, $result['entries']);

        $first = $result['entries'][0];
        $this->assertSame('Анна', $first->speaker);
        $this->assertSame('Всем привет, давайте начнём.', $first->paragraph);
        $this->assertSame(0.0, $first->startRelative);
        $this->assertSame(2.4, $first->endRelative);
    }

    #[Test]
    public function throws_on_non_array_root(): void
    {
        $this->expectException(TranscriptParseException::class);
        $this->parser->parse('{"foo": "bar"}');
    }

    #[Test]
    public function throws_on_invalid_json(): void
    {
        $this->expectException(TranscriptParseException::class);
        $this->parser->parse('not valid json at all');
    }
}
