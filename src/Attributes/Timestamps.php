<?php

namespace SchemaOps\Attributes;

use Attribute;
use Dom\Attr;

#[Attribute(Attribute::TARGET_CLASS)]
class Timestamps
{
    public function __construct(
        public bool $nullable = false,
        public ?string $createdAtColumn = 'created_at',
        public ?string $updatedAtColumn = 'updated_at',
    ) {}
}