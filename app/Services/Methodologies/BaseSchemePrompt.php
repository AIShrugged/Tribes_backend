<?php

namespace App\Services\Methodologies;

abstract class BaseSchemePrompt
{
    protected const VERSION = 'Unknown';

    public function getVersion(): string
    {
        return static::VERSION;
    }

    public function make(string $methodology): string
    {
        return view($this->getTemplate(), ['methodology' => $methodology]);
    }

    abstract protected function getTemplate(): string;
}
