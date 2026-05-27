<?php

namespace App\Services\Transcript;

use App\Services\Transcript\Exceptions\TranscriptParseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Extracts text content from uploaded files, transparently handling archives.
 *
 * Supported archive formats (detected by magic bytes, not extension):
 *   - ZIP (PK\x03\x04)
 *   - GZIP (.gz — \x1F\x8B)
 *
 * If the file is not an archive, returns its raw contents unchanged.
 *
 * Archive heuristic: pick the largest text-like file from the archive.
 * "Text-like" = extension in {txt, json, vtt, srt, csv, md, log, tsv, text}
 * or no extension at all. Binary files (images, executables) are skipped.
 *
 * Guards against zip-bombs: uncompressed content capped at MAX_UNCOMPRESSED_BYTES.
 */
class TranscriptArchiveExtractor
{
    private const MAX_UNCOMPRESSED_BYTES = 5 * 1024 * 1024; // 5MB

    private const TEXT_EXTENSIONS = [
        'txt', 'json', 'vtt', 'srt', 'csv', 'md', 'log', 'tsv', 'text', 'sub', 'ass', 'ssa',
    ];

    private const BINARY_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg',
        'mp3', 'mp4', 'wav', 'avi', 'mov', 'mkv',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'exe', 'dll', 'so', 'dylib', 'bin', 'class', 'pyc',
    ];

    /**
     * @return string  Raw text contents — either from the file directly, or from
     *                 the best text file inside an archive.
     * @throws TranscriptParseException  If archive is corrupt, empty, or contains
     *                                    only binary files.
     */
    public function extract(UploadedFile $file): string
    {
        $firstBytes = file_get_contents($file->getRealPath(), false, null, 0, 4);

        if ($firstBytes === false) {
            return $file->get();
        }

        if ($this->isZip($firstBytes)) {
            return $this->extractFromZip($file->getRealPath());
        }

        if ($this->isGzip($firstBytes)) {
            return $this->extractFromGzip($file->getRealPath());
        }

        return $file->get();
    }

    /**
     * Whether the uploaded file looks like an archive (by magic bytes).
     * Used by the upload service to log telemetry about archive uploads.
     */
    public function isArchive(UploadedFile $file): bool
    {
        $firstBytes = file_get_contents($file->getRealPath(), false, null, 0, 4);
        if ($firstBytes === false) {
            return false;
        }
        return $this->isZip($firstBytes) || $this->isGzip($firstBytes);
    }

    private function isZip(string $bytes): bool
    {
        return strlen($bytes) >= 4 && substr($bytes, 0, 4) === "PK\x03\x04";
    }

    private function isGzip(string $bytes): bool
    {
        return strlen($bytes) >= 2 && substr($bytes, 0, 2) === "\x1F\x8B";
    }

    private function extractFromZip(string $path): string
    {
        $zip = new ZipArchive();
        $result = $zip->open($path, ZipArchive::RDONLY);

        if ($result !== true) {
            throw new TranscriptParseException('Could not open ZIP archive (error code: ' . $result . ')');
        }

        try {
            $bestEntry = $this->findBestTextEntry($zip);

            if ($bestEntry === null) {
                throw new TranscriptParseException('ZIP archive contains no text files');
            }

            $contents = $zip->getFromName($bestEntry['name']);

            if ($contents === false) {
                throw new TranscriptParseException('Could not read file from ZIP: ' . $bestEntry['name']);
            }

            if (strlen($contents) > self::MAX_UNCOMPRESSED_BYTES) {
                throw new TranscriptParseException(
                    'Uncompressed file exceeds ' . (self::MAX_UNCOMPRESSED_BYTES / 1024 / 1024) . ' MB limit',
                );
            }

            Log::info('TranscriptArchiveExtractor: extracted from ZIP', [
                'archive_entries' => $zip->numFiles,
                'selected_file' => $bestEntry['name'],
                'uncompressed_size' => strlen($contents),
            ]);

            return $contents;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{name: string, size: int}|null
     */
    private function findBestTextEntry(ZipArchive $zip): ?array
    {
        $candidates = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];

            // Skip directories and macOS resource forks.
            if (str_ends_with($name, '/') || str_contains($name, '__MACOSX')) {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            // Explicitly skip known binary formats.
            if (in_array($ext, self::BINARY_EXTENSIONS, true)) {
                continue;
            }

            // Accept known text extensions OR files with no extension.
            if ($ext === '' || in_array($ext, self::TEXT_EXTENSIONS, true)) {
                $candidates[] = [
                    'name' => $name,
                    'size' => $stat['size'], // uncompressed size
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Pick the largest text file — most likely the actual transcript.
        usort($candidates, fn (array $a, array $b) => $b['size'] <=> $a['size']);
        return $candidates[0];
    }

    private function extractFromGzip(string $path): string
    {
        $compressed = file_get_contents($path);

        if ($compressed === false) {
            throw new TranscriptParseException('Could not read GZIP file');
        }

        $contents = @gzdecode($compressed);

        if ($contents === false) {
            throw new TranscriptParseException('Could not decompress GZIP file');
        }

        if (strlen($contents) > self::MAX_UNCOMPRESSED_BYTES) {
            throw new TranscriptParseException(
                'Uncompressed content exceeds ' . (self::MAX_UNCOMPRESSED_BYTES / 1024 / 1024) . ' MB limit',
            );
        }

        Log::info('TranscriptArchiveExtractor: extracted from GZIP', [
            'compressed_size' => strlen($compressed),
            'uncompressed_size' => strlen($contents),
        ]);

        return $contents;
    }
}
