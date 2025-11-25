<?php

namespace App\Services\Recall;

interface RecallPayloadInterface
{
    public static function fromArray(array $data): static;
}
