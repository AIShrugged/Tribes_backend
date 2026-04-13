<?php

namespace App\Services\Paperclip;

interface PaperclipPayloadInterface
{
    public static function fromArray(array $data): static;
}
