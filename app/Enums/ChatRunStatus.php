<?php

namespace App\Enums;

enum ChatRunStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case RETRYING = 'retrying';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::QUEUED => in_array($target, [self::PROCESSING, self::FAILED], true),
            self::PROCESSING => in_array($target, [self::RETRYING, self::COMPLETED, self::FAILED], true),
            self::RETRYING => in_array($target, [self::PROCESSING, self::FAILED], true),
            self::COMPLETED, self::FAILED => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED], true);
    }

    public function progressPercent(): int
    {
        return match ($this) {
            self::QUEUED => 5,
            self::PROCESSING => 50,
            self::RETRYING => 25,
            self::COMPLETED, self::FAILED => 100,
        };
    }

    public function currentStepLabel(): ?string
    {
        return match ($this) {
            self::QUEUED => 'Queued',
            self::PROCESSING => 'Generating response',
            self::RETRYING => 'Retrying after failure',
            self::COMPLETED => 'Completed',
            self::FAILED => null,
        };
    }
}
