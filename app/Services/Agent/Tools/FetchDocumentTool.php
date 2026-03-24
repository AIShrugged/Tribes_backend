<?php

namespace App\Services\Agent\Tools;

use Illuminate\Support\Facades\Http;

class FetchDocumentTool implements ToolInterface
{
    public function getName(): string
    {
        return 'fetch_document';
    }

    public function getDescription(): string
    {
        return 'Fetches a web page or document by URL and returns its text content. '
            . 'Use when the user shares a link to a document (methodology, article, specification). '
            . 'Supports HTML pages, plain text, and PDF files.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['url'],
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => 'The URL of the document to fetch.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $url = $parameters['url'] ?? null;

        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'error'   => 'A valid URL is required.',
            ];
        }

        try {
            $response = Http::withProxy()
                ->timeout(30)
                ->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error'   => "Failed to fetch URL: HTTP {$response->status()}",
                ];
            }

            $contentType = $response->header('Content-Type') ?? '';
            $body        = $response->body();

            $content = match (true) {
                str_contains($contentType, 'application/pdf') => $this->extractPdf($body),
                str_contains($contentType, 'text/html')       => $this->extractHtml($body),
                default                                       => $body,
            };

            $content = $this->truncate($content, 15000);

            return [
                'success'        => true,
                'url'            => $url,
                'content_type'   => $contentType,
                'content'        => $content,
                'content_length' => mb_strlen($content),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => "Failed to fetch document: {$e->getMessage()}",
            ];
        }
    }

    private function extractHtml(string $html): string
    {
        // Remove script, style, nav, header, footer blocks
        $html = preg_replace('/<(script|style|nav|header|footer)\b[^>]*>.*?<\/\1>/si', '', $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function extractPdf(string $body): string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            return '[PDF parsing unavailable — smalot/pdfparser not installed]';
        }

        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseContent($body);

        return $pdf->getText();
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . "\n\n[Content truncated at {$maxLength} characters]";
    }
}
