<?php

namespace Tests\Unit\Transcript\Parsers;

use App\Domain\DTO\Transcript\TranscriptParseSpec;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\Parsers\GenericLineBasedTranscriptParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GenericLineBasedTranscriptParserTest extends TestCase
{
    #[Test]
    public function parses_before_colon_with_bracketed_hh_mm_timestamp(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'hh_mm',
            timestampLocation: 'line_prefix_bracketed',
            exampleLine: '[14:00] Anna: Hello team',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $result = $parser->parse("[14:00] Anna: Hello team\n[14:01] Pete: All good\n");

        $this->assertCount(2, $result['entries']);
        $this->assertSame(['Anna', 'Pete'], $result['speakers']);
        $this->assertSame('Anna', $result['entries'][0]->speaker);
        $this->assertSame('Hello team', $result['entries'][0]->paragraph);
        $this->assertSame(50400.0, $result['entries'][0]->startRelative);  // 14*3600
    }

    #[Test]
    public function parses_paren_timestamp_with_hh_mm_ss(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'hh_mm_ss',
            timestampLocation: 'line_prefix_paren',
            exampleLine: '(00:01:23) Pete: All set',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $result = $parser->parse("(00:01:23) Pete: All set\n");

        $this->assertCount(1, $result['entries']);
        $this->assertSame(83.0, $result['entries'][0]->startRelative);  // 1*60+23
    }

    #[Test]
    public function parses_voice_xml_tag(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'voice_xml_tag',
            speakerDelimiter: null,
            timestampFormat: 'hh_mm_ss_ms',
            timestampLocation: 'line_prefix_bracketed',
            exampleLine: '[00:00:01.500] <v Anna>Hello</v>',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $result = $parser->parse("[00:00:01.500] <v Anna>Hello</v>\n");

        $this->assertCount(1, $result['entries']);
        $this->assertSame('Anna', $result['entries'][0]->speaker);
    }

    #[Test]
    public function parses_no_timestamp_speaker_only(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'none',
            timestampLocation: 'none',
            exampleLine: 'Anna: text',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $result = $parser->parse("Anna: hi\nPete: hello\n");

        $this->assertCount(2, $result['entries']);
        $this->assertNull($result['entries'][0]->startRelative);
    }

    #[Test]
    public function header_line_count_skips_metadata_lines(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'hh_mm',
            timestampLocation: 'line_prefix_bracketed',
            headerLineCount: 3,
            exampleLine: '[14:00] Anna: Hi',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $contents = "Расшифровка встречи: Tribes\nДата: 2026-05-25\nУчастники: Anna, Pete\n[14:00] Anna: Hi\n[14:01] Pete: Yes\n";
        $result = $parser->parse($contents);

        $this->assertCount(2, $result['entries']);
        $this->assertSame('Anna', $result['entries'][0]->speaker);
        $this->assertSame('Hi', $result['entries'][0]->paragraph);
    }

    #[Test]
    public function header_terminator_empty_line_skips_until_blank(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'none',
            timestampLocation: 'none',
            headerTerminator: 'empty_line',
            exampleLine: 'Anna: Hi',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $contents = "Meeting: Tribes\nDate: 2026-05-25\n\nAnna: Hi\nPete: Yes\n";
        $result = $parser->parse($contents);

        $this->assertCount(2, $result['entries']);
    }

    #[Test]
    public function header_terminator_first_match_jumps_to_entry(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'hh_mm',
            timestampLocation: 'line_prefix_bracketed',
            headerTerminator: 'first_match',
            exampleLine: '[14:00] Anna: Hi',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        // Header has lines without timestamps; entries do.
        $contents = "Meeting Tribes\nDate 2026-05-25\nParticipants: Anna, Pete\n[14:00] Anna: Hi\n[14:01] Pete: Yes\n";
        $result = $parser->parse($contents);

        $this->assertCount(2, $result['entries']);
        $this->assertSame('Hi', $result['entries'][0]->paragraph);
    }

    #[Test]
    public function comment_prefixes_are_skipped(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'none',
            timestampLocation: 'none',
            commentPrefixes: ['#'],
            exampleLine: 'Anna: Hi',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $result = $parser->parse("# header comment\n# another\nAnna: Hi\n# trailing\nPete: Yes\n");
        $this->assertCount(2, $result['entries']);
    }

    #[Test]
    public function unmatching_lines_are_softly_skipped(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'hh_mm',
            timestampLocation: 'line_prefix_bracketed',
            exampleLine: '[14:00] Anna: Hi',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $result = $parser->parse("[14:00] Anna: Hi\n--- section divider ---\n[14:01] Pete: Yes\npage 3\n");
        $this->assertCount(2, $result['entries']);
        $this->assertEqualsWithDelta(0.5, $result['_meta']['yield_ratio'], 0.01);  // 2 of 4 considered
    }

    #[Test]
    public function throws_when_no_entries_match(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'hh_mm',
            timestampLocation: 'line_prefix_bracketed',
            exampleLine: '[14:00] Anna: Hi',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $this->expectException(TranscriptParseException::class);
        $parser->parse("plain text\nno timestamps\nno colons either\n");
    }

    #[Test]
    public function can_parse_example_line_is_true_when_regex_built_correctly(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'hh_mm',
            timestampLocation: 'line_prefix_bracketed',
            exampleLine: '[14:00] Anna: Hi',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $this->assertTrue($parser->canParseExampleLine());
    }

    #[Test]
    public function can_parse_example_line_is_false_when_spec_does_not_match_example(): void
    {
        $spec = $this->spec(
            speakerExtraction: 'before_colon',
            speakerDelimiter: ':',
            timestampFormat: 'hh_mm',
            timestampLocation: 'line_prefix_bracketed',
            exampleLine: 'no timestamp here Anna: Hi',
        );
        $parser = new GenericLineBasedTranscriptParser($spec);

        $this->assertFalse($parser->canParseExampleLine());
    }

    private function spec(
        string $speakerExtraction,
        ?string $speakerDelimiter,
        string $timestampFormat,
        string $timestampLocation,
        string $exampleLine,
        ?int $headerLineCount = null,
        ?string $headerTerminator = null,
        array $commentPrefixes = [],
        bool $skipEmptyLines = true,
    ): TranscriptParseSpec {
        return new TranscriptParseSpec(
            speakerExtraction: $speakerExtraction,
            speakerDelimiter: $speakerDelimiter,
            timestampFormat: $timestampFormat,
            timestampLocation: $timestampLocation,
            headerLineCount: $headerLineCount,
            headerTerminator: $headerTerminator,
            commentPrefixes: $commentPrefixes,
            skipEmptyLines: $skipEmptyLines,
            exampleLine: $exampleLine,
        );
    }
}
