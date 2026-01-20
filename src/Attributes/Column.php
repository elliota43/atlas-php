<?php

namespace SchemaOps\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public string $type, // e.g. 'integer', 'varchar'
        public ?int $length = null,
        public bool $nullable = false,
        public bool $autoIncrement = false,
        public bool $primaryKey = false,
        public mixed $default = null,
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function length(): ?int
    {
        return $this->length;
    }

    public function nullable(): bool
    {
        return $this->nullable;
    }

    public function autoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function primaryKey(): bool
    {
        return $this->primaryKey;
    }

    public function default(): mixed
    {
        return $this->default;
    }
}