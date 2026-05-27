<?php

namespace Tests\Unit\Transcript;

use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\TranscriptArchiveExtractor;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class TranscriptArchiveExtractorTest extends TestCase
{
    private TranscriptArchiveExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new TranscriptArchiveExtractor();
    }

    #[Test]
    public function returns_raw_contents_for_plain_text_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('transcript.txt', "Anna: Hello\nPete: Hi\n");
        $result = $this->extractor->extract($file);
        $this->assertSame("Anna: Hello\nPete: Hi\n", $result);
    }

    #[Test]
    public function is_archive_returns_false_for_plain_text(): void
    {
        $file = UploadedFile::fake()->createWithContent('transcript.txt', "Anna: Hello\n");
        $this->assertFalse($this->extractor->isArchive($file));
    }

    #[Test]
    public function extracts_text_file_from_zip(): void
    {
        $zipPath = $this->createZipWithFiles([
            'meeting.txt' => "Anna: Hello\nPete: Hi\n",
        ]);
        $file = new UploadedFile($zipPath, 'transcript.zip', 'application/zip', null, true);

        $result = $this->extractor->extract($file);

        $this->assertSame("Anna: Hello\nPete: Hi\n", $result);
        $this->assertTrue($this->extractor->isArchive($file));
    }

    #[Test]
    public function picks_largest_text_file_from_zip_with_multiple_entries(): void
    {
        $zipPath = $this->createZipWithFiles([
            'small.txt' => 'short',
            'transcript.txt' => str_repeat("Anna: line\n", 100),
            'image.jpg' => 'fake-binary',
        ]);
        $file = new UploadedFile($zipPath, 'bundle.zip', 'application/zip', null, true);

        $result = $this->extractor->extract($file);

        $this->assertStringContainsString('Anna: line', $result);
        $this->assertGreaterThan(100, strlen($result));
    }

    #[Test]
    public function skips_macosx_resource_forks(): void
    {
        $zipPath = $this->createZipWithFiles([
            '__MACOSX/._transcript.txt' => 'resource fork garbage',
            'transcript.txt' => "Anna: Hello\n",
        ]);
        $file = new UploadedFile($zipPath, 't.zip', 'application/zip', null, true);

        $result = $this->extractor->extract($file);
        $this->assertSame("Anna: Hello\n", $result);
    }

    #[Test]
    public function throws_when_zip_contains_only_binary_files(): void
    {
        $zipPath = $this->createZipWithFiles([
            'photo.jpg' => 'binary data',
            'doc.pdf' => 'binary data',
        ]);
        $file = new UploadedFile($zipPath, 'no-text.zip', 'application/zip', null, true);

        $this->expectException(TranscriptParseException::class);
        $this->expectExceptionMessage('no text files');
        $this->extractor->extract($file);
    }

    #[Test]
    public function extracts_from_gzip(): void
    {
        $original = "Anna: Hello\nPete: Hi\n";
        $gzPath = tempnam(sys_get_temp_dir(), 'gz_test_');
        file_put_contents($gzPath, gzencode($original));

        $file = new UploadedFile($gzPath, 'transcript.txt.gz', 'application/gzip', null, true);

        $result = $this->extractor->extract($file);
        $this->assertSame($original, $result);
        $this->assertTrue($this->extractor->isArchive($file));

        @unlink($gzPath);
    }

    #[Test]
    public function throws_on_corrupt_zip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad_zip_');
        file_put_contents($path, "PK\x03\x04" . 'garbage data not a real zip');

        $file = new UploadedFile($path, 'bad.zip', 'application/zip', null, true);

        $this->expectException(TranscriptParseException::class);
        $this->extractor->extract($file);

        @unlink($path);
    }

    /**
     * @param  array<string, string>  $files  name → contents
     */
    private function createZipWithFiles(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip_test_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }
}
