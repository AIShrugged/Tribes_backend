<?php

namespace App\Services\Recall;

interface RecallEventHandlerInterface
{
    public function handle(RecallPayloadInterface $payload): void;
}
