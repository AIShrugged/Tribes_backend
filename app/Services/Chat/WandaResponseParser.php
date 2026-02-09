<?php

namespace App\Services\Chat;

use App\Domain\DTO\Chat\ChartConfigDTO;
use App\Domain\DTO\Chat\WandaResponseDTO;

class WandaResponseParser
{
    public function parse(string $response): WandaResponseDTO
    {
        $cleanedResponse = $this->cleanJsonResponse($response);

        $data = json_decode($cleanedResponse, true);

        if (!is_array($data)) {
            return new WandaResponseDTO(message: $response);
        }

        return $this->parseSqlFormat($data);
    }

    public function parseInterpretation(string $response): WandaResponseDTO
    {
        $cleanedResponse = $this->cleanJsonResponse($response);

        $data = json_decode($cleanedResponse, true);

        if (!is_array($data)) {
            return new WandaResponseDTO(message: $response);
        }

        $visualization = null;
        if (isset($data['visualization']) && is_array($data['visualization'])) {
            $visualization = ChartConfigDTO::fromArray($data['visualization']);
        }

        return new WandaResponseDTO(
            message: $data['message'] ?? $response,
            visualization: $visualization,
        );
    }

    private function parseSqlFormat(array $data): WandaResponseDTO
    {
        $visualization = null;

        if (isset($data['visualization']) && is_array($data['visualization'])) {
            $visualization = ChartConfigDTO::fromArray($data['visualization']);
        }

        return new WandaResponseDTO(
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
