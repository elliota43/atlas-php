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
        public mixed $default = null,


        public bool $autoIncrement = false,
        public bool $primaryKey = false,
        public bool $unique = false,

        // Numeric
        public bool $unsigned = false,
        public bool $zerofill = false,
        public ?int $precision = null,
        public ?int $scale = null,

        public ?string $charset = null,
        public ?string $collation = null,

        // Enum/Set
        public ?array $values = null,

        public ?string $comment = null,

        public ?string $generated = null, // 'VIRTUAL' or 'STORED'
        public ?string $expression = null,

        public ?string $onUpdate = null,
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