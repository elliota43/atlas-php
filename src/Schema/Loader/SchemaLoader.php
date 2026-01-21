<?php

namespace Atlas\Schema\Loader;

use Atlas\Database\TypeNormalizerInterface;
use Atlas\Schema\Parser\SchemaParser;
use Atlas\Schema\Parser\YamlSchemaParser;
use Atlas\Schema\Discovery\ClassFinder;
use Atlas\Schema\Definition\TableDefinition;
use Atlas\Exceptions\SchemaException;
use Atlas\Schema\Discovery\YamlSchemaFinder;
class SchemaLoader
{
    public function __construct(
        private YamlSchemaParser $yamlParser,
        private SchemaParser $phpParser,
        private ClassFinder $classFinder,
        private TypeNormalizerInterface $normalizer
    ) {}

    /**
     * Load schema definitions from either YAML (default) or
     * PHP classes with #[Table] attribute.
     *
     *
     * @param string $path YAML file path or schema directory path
     * @param bool $usePhp Whether to treat the path as a PHP schema directory.
     * @return array<string, TableDefinition> Table definitions keyed by table name.
     */
    public function load(string $path, bool $usePhp = false): array
    {
        return $usePhp
            ? $this->loadFromPhp($path)
            : $this->loadFromYaml($path);
    }

    /**
     * Load schema definitions from a YAML schema file.
     *
     * @param string $path
     * @return array<string, TableDefinition>
     */
    private function loadFromYaml(string $path): array
    {
        return $this->yamlParser->parseFile($path);
    }

    /**
     * Load schema definitions from YAML files in a directory.
     *
     * @param string $directory Root directory to search.
     * @param string $pattern File pattern (default: *.schema.yaml)
     * @return array<TableDefinition>
     */
    public function loadFromYamlDirectory(string $directory, string $pattern = '*.schema.yaml'): array
    {
        $finder = new YamlSchemaFinder();
        $parser = new YamlSchemaParser($this->normalizer);

        $files = $finder->findInDirectory($directory, $pattern);

        if (empty($files)) {
            return [];
        }

        return $parser->parseFiles($files);
    }

    /**
     * Load schema definitions from PHP schema classes in a directory.}
     *
     * @param string $path
     * @return array<string, TableDefinition>
     */
    private function loadFromPhp(string $path): array
    {
        if (! is_dir($path)) {
            throw SchemaException::directoryNotFound($path);
        }

        $classes = $this->classFinder->findInDirectory($path);

        $definitions = [];

        foreach ($classes as $class) {
            $table = $this->phpParser->parse($class);

            $definitions[$table->tableName()] = $table;
        }

        return $definitions;
    }
}