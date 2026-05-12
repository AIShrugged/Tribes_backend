<?php

namespace App\Services\Onboarding;

use App\Models\IssueAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class OnboardingLlmBase
{
    protected const MAX_BROWSE_ITERATIONS = 15;
    protected const MAX_URL_CHARS         = 5000;
    protected const MAX_FILE_CHARS        = 8000;

    protected function readUploadedFiles(?string $uploadToken, int $userId): string
    {
        if (!$uploadToken) {
            return '';
        }

        $disk        = (string) config('filesystems.issue_attachments_disk', config('filesystems.default', 'local'));
        $attachments = IssueAttachment::pending($uploadToken, $userId)->get();
        $parts       = [];

        foreach ($attachments as $attachment) {
            $ext = strtolower(pathinfo($attachment->file_path, PATHINFO_EXTENSION));
            $raw = Storage::disk($disk)->get($attachment->file_path);

            if ($raw === null) {
                continue;
            }

            $text = match ($ext) {
                'pdf'  => $this->extractPdf($raw),
                'docx' => $this->extractDocx($raw),
                default => $raw,
            };

            $parts[] = '=== File: ' . basename($attachment->file_path) . " ===\n" . mb_substr($text, 0, self::MAX_FILE_CHARS);
        }

        return implode("\n\n", $parts);
    }

    protected function extractPdf(string $raw): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return '[PDF: smalot/pdfparser library not installed]';
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseContent($raw);

            return $pdf->getText();
        } catch (\Throwable $e) {
            Log::warning('Onboarding PDF extraction failed', ['error' => $e->getMessage()]);

            return '[PDF: could not read file]';
        }
    }

    protected function extractDocx(string $raw): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return '[DOCX: ZipArchive extension not available]';
        }

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'docx_');
            file_put_contents($tmp, $raw);

            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                unlink($tmp);

                return '[DOCX: could not open file]';
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            unlink($tmp);

            if ($xml === false) {
                return '[DOCX: unrecognized file structure]';
            }

            return strip_tags($xml);
        } catch (\Throwable $e) {
            Log::warning('Onboarding DOCX extraction failed', ['error' => $e->getMessage()]);

            return '[DOCX: could not read file]';
        }
    }

    protected function fetchUrlToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => 'fetch_url',
                'description' => 'Fetch the content of a URL to gather information about an organization, its team, repository, or project. Use this to browse websites, follow links to contributor pages, commit history, README files, issues, API endpoints, etc.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url' => [
                            'type'        => 'string',
                            'description' => 'The full URL to fetch (must start with http:// or https://)',
                        ],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }

    protected function fetchSingleUrl(string $url): string
    {
        try {
            $response = Http::timeout(15)->get($url);

            if (!$response->successful()) {
                return "Error fetching {$url}: HTTP {$response->status()}";
            }

            $text = strip_tags($response->body());
            $text = preg_replace('/\s+/', ' ', $text);

            return mb_substr(trim($text), 0, self::MAX_URL_CHARS);
        } catch (\Throwable $e) {
            return "Error fetching {$url}: {$e->getMessage()}";
        }
    }

    protected function isSafeUrl(string $url): bool
    {
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
        if (in_array(strtolower($host), $blocked, true)) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    protected function extractTextContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return implode('', array_map(
                fn($block) => is_array($block) && ($block['type'] ?? '') === 'text' ? ($block['text'] ?? '') : '',
                $content,
            ));
        }

        return '';
    }
}
