<?php

namespace App\Domain\DTO\Chat;

use App\Domain\DTO\BaseDTO;

class WandaResponseDTO extends BaseDTO
{
    public function __construct(
        public string $message,
        public ?string $sql = null,
        public ?ChartConfigDTO $visualization = null,
    ) {
    }

    public function hasSqlQuery(): bool
    {
        return $this->sql !== null && $this->sql !== '';
    }

    public function hasVisualization(): bool
    {
        return $this->visualization !== null && $this->visualization->hasVisualization();
    }
}
