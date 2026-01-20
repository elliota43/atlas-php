<?php

namespace SchemaOps\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Uuid
{
    public function __construct(
        public string $name = 'id',
        public bool $primaryKey = true,
    ) {}
}
