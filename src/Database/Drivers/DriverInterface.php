<?php

namespace SchemaOps\Database\Drivers;

use SchemaOps\Schema\Definition\TableDefinition;

interface DriverInterface
{
    /**
     * @return TableDefinition[] Keyed by table name
     */
    public function getCurrentSchema(): array;
}