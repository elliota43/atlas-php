<?php

namespace SchemaOps\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class SoftDeletes
{
    public function __construct(
        public string $column = 'deleted_at',
        public bool $nullable = true,
    ) {}
}
