<?php

namespace SchemaOps\Schema\Parser;

use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use SchemaOps\Attributes\Column;
use SchemaOps\Attributes\Table;
use SchemaOps\Schema\Definition\ColumnDefinition;
use SchemaOps\Schema\Definition\TableDefinition;

class SchemaParser
{
    /**
     * Parse a class into a table definition.
     */
    public function parse(string $className): TableDefinition
    {
        $reflection = $this->reflectClass($className);

        $tableAttribute = $this->extractTableAttribute($reflection);

        return $this->buildTableDefinition($reflection, $tableAttribute);
    }

    /**
     * Create a reflection instance for the given class.
     */
    protected function reflectClass(string $className): ReflectionClass
    {
        if (! class_exists($className)) {
            throw new RuntimeException(
                "Class '${className}' not found. Make sure it's loaded or autoloadable."
            );
        }

        return new ReflectionClass($className);
    }

    /**
     * Extract the Table attribute from a reflected class.
     */
    protected function extractTableAttribute(ReflectionClass $reflection): Table
    {
        $attribute = $this->getAttribute($reflection, Table::class);

        if (! $attribute) {
            throw new RuntimeException(
                "Class '{$reflection->getName()}' is missing the #[Table] attribute."
            );
        }

        return $attribute;
    }

    /**
     * Build a complete table definition from reflection and attribute.
     */
    protected function buildTableDefinition(
        ReflectionClass $reflection,
        Table $tableAttribute
    ): TableDefinition {
        $definition = new TableDefinition($tableAttribute->name);

        foreach ($this->getSchemaProperties($reflection) as $property) {
            if ($column = $this->buildColumnDefinition($property)) {
                $definition->addColumn($column);
            }
        }

        return $definition;
    }

    /**
     * Get all public properties that should be considered for schema.
     */
    protected function getSchemaProperties(ReflectionClass $reflection): array
    {
        return $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
    }

    /**
     * Build a column definition from a reflected property.
     */
    protected function buildColumnDefinition(ReflectionProperty $property): ?ColumnDefinition
    {
        $attribute = $this->getAttribute($property, Column::class);

        if (! $attribute) {
            return null;
        }

        return new ColumnDefinition(
            name: $property->getName(),
            sqlType: $this->resolveSqlType($attribute),
            isNullable: $attribute->nullable,
            isAutoIncrement: $attribute->autoIncrement,
            isPrimaryKey: $attribute->primaryKey,
            defaultValue: $attribute->default
        );
    }

    /**
     * Get an attribute instance from a reflection object.
     */
    protected function getAttribute(
        ReflectionClass|ReflectionProperty $reflector,
        string $attributeClass
    ): ?object {
        $attributes = $reflector->getAttributes($attributeClass);

        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Resolve the SQL type from a column attribute.
     */
    protected function resolveSqlType(Column $attribute): string
    {
        if ($attribute->type === 'varchar' && $attribute->length) {
            return "varchar({$attribute->length})";
        }

        return $attribute->type;
    }
}