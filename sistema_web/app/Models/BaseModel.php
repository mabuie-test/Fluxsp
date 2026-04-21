<?php

declare(strict_types=1);

namespace App\Models;

abstract class BaseModel
{
    public function __construct(public array $attributes = []) {}

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
