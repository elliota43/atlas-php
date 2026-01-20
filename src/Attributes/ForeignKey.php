<?php

namespace SchemaOps\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class ForeignKey
{
    public function __construct(
        public string $references, // 'users.id' or ['users', 'id']
        public ?string $onDelete = null, // 'CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'
        public ?string $onUpdate = null, // 'CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'
        public ?string $name = null, // custom constraint name
    ) {}
}