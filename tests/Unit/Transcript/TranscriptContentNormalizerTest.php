<?php

namespace Tests\Unit\Transcript;

use App\Services\Transcript\TranscriptContentNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TranscriptContentNormalizerTest extends TestCase
{
    private TranscriptContentNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new TranscriptContentNormalizer();
    }

    #[Test]
    public function strips_utf8_bom(): void
    {
        $result = $this->normalizer->normalize("\xEF\xBB\xBFhello");
        $this->assertSame('hello', $result);
    }

    #[Test]
    public function leaves_non_bom_content_alone(): void
    {
        $this->assertSame('hello', $this->normalizer->normalize('hello'));
    }

    #[Test]
    public function converts_crlf_to_lf(): void
    {
        $this->assertSame("line1\nline2\n", $this->normalizer->normalize("line1\r\nline2\r\n"));
    }

    #[Test]
    public function converts_lone_cr_to_lf(): void
    {
        $this->assertSame("line1\nline2", $this->normalizer->normalize("line1\rline2"));
    }

    #[Test]
    public function handles_combined_bom_and_crlf(): void
    {
        $this->assertSame("a\nb\n", $this->normalizer->normalize("\xEF\xBB\xBFa\r\nb\r\n"));
    }
}
