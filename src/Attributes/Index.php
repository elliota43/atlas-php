<?php

namespace SchemaOps\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Index
{
    public function __construct(
        public array $columns,              // ['email'] or ['user_id', 'created_at']
        public ?string $name = null,        // Custom index name
        public bool $unique = false,        // UNIQUE index
        public ?string $type = null,        // 'BTREE', 'HASH', 'FULLTEXT', 'SPATIAL'
        public ?int $length = null,         // Index prefix length
    ) {}
}