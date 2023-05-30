<?php

declare(strict_types=1);

namespace Nadyita\Relana;

class Relation
{
    public function __construct(
        public string $name,
        public int $id,
    ) {
    }
}
