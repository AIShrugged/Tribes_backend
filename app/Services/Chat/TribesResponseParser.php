<?php

namespace App\Services\Chat;

use App\Domain\DTO\Chat\ChartConfigDTO;
use App\Domain\DTO\Chat\TribesResponseDTO;

class TribesResponseParser
{
    public function parse(string $response): TribesResponseDTO
    {
        $cleanedResponse = $this->cleanJsonResponse($response);

        $data = json_decode($cleanedResponse, true);

        if (!is_array($data)) {
            return new TribesResponseDTO(message: $response);
        }

        return $this->parseSqlFormat($data);
    }

    public function parseInterpretation(string $response): TribesResponseDTO
    {
        $cleanedResponse = $this->cleanJsonResponse($response);

        $data = json_decode($cleanedResponse, true);

        if (!is_array($data)) {
            return new TribesResponseDTO(message: $response);
        }

        $visualization = null;
        if (isset($data['visualization']) && is_array($data['visualization'])) {
            $visualization = ChartConfigDTO::fromArray($data['visualization']);
        }

        return new TribesResponseDTO(
            message: $data['message'] ?? $response,
            visualization: $visualization,
        );
    }

    private function parseSqlFormat(array $data): TribesResponseDTO
    {
        $visualization = null;

        if (isset($data['visualization']) && is_array($data['visualization'])) {
            $visualization = ChartConfigDTO::fromArray($data['visualization']);
        }

        return new TribesResponseDTO(
            message: $data['message'] ?? '',
            sql: $data['sql'] ?? null,
            visualization: $visualization,
        );
    }

    private function cleanJsonResponse(string $response): string
    {
        $response = trim($response);

        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            return $matches[1];
        }

        if (preg_match('/```\s*(.*?)\s*```/s', $response, $matches)) {
            return $matches[1];
        }

        return $response;
    }
}
