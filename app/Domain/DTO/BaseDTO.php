<?php

namespace App\Domain\DTO;

use Illuminate\Contracts\Support\Arrayable;

abstract class BaseDTO implements Arrayable, \JsonSerializable
{
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
