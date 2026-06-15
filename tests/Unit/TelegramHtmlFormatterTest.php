<?php

namespace Tests\Unit;

use App\Services\Telegram\TelegramHtmlFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TelegramHtmlFormatterTest extends TestCase
{
    private TelegramHtmlFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new TelegramHtmlFormatter;
    }

    #[Test]
    public function it_escapes_html_special_characters(): void
    {
        $this->assertSame('a &lt; b &amp; c &gt; d', $this->formatter->toHtml('a < b & c > d'));
    }

    #[Test]
    public function it_converts_bold_and_italic(): void
    {
        $this->assertSame('<b>bold</b> and <i>italic</i>', $this->formatter->toHtml('**bold** and *italic*'));
    }

    #[Test]
    public function it_converts_inline_code_and_escapes_inside(): void
    {
        $this->assertSame('<code>a&lt;b</code>', $this->formatter->toHtml('`a<b`'));
    }

    #[Test]
    public function it_does_not_reinterpret_emphasis_inside_code(): void
    {
        $this->assertSame('<code>**not bold**</code>', $this->formatter->toHtml('`**not bold**`'));
    }

    #[Test]
    public function it_converts_links(): void
    {
        $this->assertSame(
            '<a href="https://example.com">text</a>',
            $this->formatter->toHtml('[text](https://example.com)'),
        );
    }

    #[Test]
    public function it_converts_bullets_and_headings(): void
    {
        $this->assertSame("<b>Title</b>\n• one\n• two", $this->formatter->toHtml("# Title\n- one\n- two"));
    }

    #[Test]
    public function it_keeps_cyrillic_intact(): void
    {
        $this->assertSame('<b>Привет</b> мир', $this->formatter->toHtml('**Привет** мир'));
    }

    #[Test]
    public function plain_text_fallback_strips_markup(): void
    {
        $this->assertSame('bold link (https://e.com)', $this->formatter->toPlainText('**bold** [link](https://e.com)'));
    }
}
