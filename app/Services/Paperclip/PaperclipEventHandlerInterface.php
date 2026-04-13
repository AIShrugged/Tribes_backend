<?php

namespace App\Services\Paperclip;

interface PaperclipEventHandlerInterface
{
    public function handle(PaperclipPayloadInterface $payload): void;
}
