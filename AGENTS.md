# SchemaOps Coding Standards

## Philosophy

This library follows **Taylor Otwell's coding principles** from Laravel, adapted for a standalone PHP library (no Laravel dependencies).

## Core Principles

### 1. Code Should Read Like Prose

Methods should tell a story. Reading the main method should give you the complete picture without diving into details.

**Good:**
```php
public function execute(): int
{
    $schemas = $this->discoverSchemas();
    $database = $this->connectToDatabase();
    $changes = $this->compareSchemas($schemas, $database);
    
    $this->displayResults($changes);
    
    return Command::SUCCESS;
}
```

**Bad:**
```php
public function execute(): int
{
    // 200 lines of inline logic
}
```

### 2. No God Functions

Every method should do ONE thing. If you can describe a method with "and", it does too much.

**Maximum method length:** ~20 lines for regular methods, ~10 lines for control flow methods

### 3. Extract Everything

If logic can be named, extract it into a method.

**Good:**
```php
protected function hasTableAttribute(SplFileInfo $file): bool
{
    $content = file_get_contents($file->getPathname());
    
    return str_contains($content, '#[Table');
}
```

**Bad:**
```php
// Inline: if (str_contains(file_get_contents($file->getPathname()), '#[Table'))
```

### 4. Guard Clauses Over Nesting

Exit early, don't nest deeply.

**Good:**
```php
public function process($data): void
{
    if (! $this->isValid($data)) {
        return;
    }
    
    if (! $this->hasPermission()) {
        return;
    }
    
    // Main logic here
}
```

**Bad:**
```php
public function process($data): void
{
    if ($this->isValid($data)) {
        if ($this->hasPermission()) {
            // Main logic nested 3 levels deep
        }
    }
}
```

### 5. Descriptive Method Names

Method names should be complete thoughts.

**Good:**
- `discoverAndParseSchemas()`
- `connectToDatabase()`
- `displayTableChanges()`
- `hasTableAttribute()`

**Bad:**
- `process()`
- `handle()`
- `doStuff()`
- `check()`

### 6. Boolean Checks

Use `!` not `!==` for boolean checks. Use `===` only for null/type-specific checks.

**Good:**
```php
if (! $user) { }
if (! $this->isValid()) { }
```

**Bad:**
```php
if ($user === null) { }  // Only if specifically checking null
if ($this->isValid() === false) { }
```

### 7. String Interpolation Over Concatenation

**Good:**
```php
return "{$namespace}\\{$class}";
```

**Bad:**
```php
return $namespace . '\\' . $class;
```

### 8. Protected Over Private

Use `protected` for methods to allow extension and easier testing.

**Exception:** Use `private` only for truly internal implementation details that should never be overridden.

### 9. Type Everything

Always use type hints for parameters and return types.

**Good:**
```php
protected function parse(string $className): TableDefinition
```

**Bad:**
```php
protected function parse($className)  // No types
```

### 10. Early Returns

Return as soon as you know the answer.

**Good:**
```php
public function find(string $id): ?User
{
    if (! $this->exists($id)) {
        return null;
    }
    
    return $this->repository->find($id);
}
```

**Bad:**
```php
public function find(string $id): ?User
{
    $user = null;
    
    if ($this->exists($id)) {
        $user = $this->repository->find($id);
    }
    
    return $user;
}
```

## Namespace Organization

Organize by **feature/domain**, not technical layer.

**Good:**
```
Schema/
├── Definition/      ← All definition structures
├── Parser/          ← Parsing logic
├── Discovery/       ← Discovery logic
└── Grammars/        ← SQL compilation
```

**Bad:**
```
Models/              ← Generic technical term
Parsers/             ← Too vague
Utils/               ← Anti-pattern
Helpers/             ← Anti-pattern
```

## Class Naming

- **Comparators** - Classes that compare things: `TableComparator`, `ColumnComparator`
- **Grammars** - Classes that compile to SQL: `MySqlGrammar`, `PostgresGrammar`
- **Definitions** - Data structures: `TableDefinition`, `ColumnDefinition`
- **Changes** - Result objects: `TableChanges`, `SchemaChanges`
- **Drivers** - Database execution: `MySqlDriver`, `PostgresDriver`

## No Laravel Helpers

This is a standalone library. Use pure PHP:

**Use:**
- `getenv('KEY')` not `env('KEY')`
- `require 'config.php'` not `config('file')`
- `new MyClass()` not `app(MyClass::class)`
- Plain arrays not Collections (unless you add a dependency)

## Method Ordering

Order methods by logical flow, not alphabetically:

1. Public API methods (what users call)
2. Protected workflow methods (the main steps)
3. Protected helper methods (the details)
4. Private implementation details (rare)

**Example:**
```php
// 1. Public API
public function compare(): TableChanges { }

// 2. Main workflow
protected function detectAddedColumns(): void { }
protected function detectModifiedColumns(): void { }
protected function detectRemovedColumns(): void { }

// 3. Helpers
protected function hasColumnChanged(): bool { }
protected function getColumnDifferences(): array { }
```

## Comments

Code should be self-documenting. Use comments sparingly:

**Good use of comments:**
- Public API documentation
- Complex algorithms that need explanation
- "Why" not "what"

**Bad use of comments:**
```php
// Loop through users
foreach ($users as $user) {
    // Check if user is active
    if ($user->isActive()) {
        // Do something
    }
}
```

**Good (no comments needed):**
```php
foreach ($this->getActiveUsers() as $user) {
    $this->processActiveUser($user);
}
```

## Testing

Write tests that read like documentation:

```php
#[Test]
public function TestDetectsTypeChange(): void
{
    // Clear arrange/act/assert structure
    $current = new ColumnDefinition('email', 'varchar(100)', false, false, false, null);
    $desired = new ColumnDefinition('email', 'varchar(255)', false, false, false, null);
    
    $comparator = new ColumnComparator($current, $desired);
    
    $this->assertTrue($comparator->hasTypeChanged());
}
```

## Anti-Patterns to Avoid

1. ❌ **God Classes** - Classes with too many responsibilities
2. ❌ **God Functions** - Functions that do everything
3. ❌ **Deep Nesting** - More than 2-3 levels
4. ❌ **Magic Numbers** - Use named constants
5. ❌ **Boolean Flags** - Split into separate methods instead
6. ❌ **Stringly Typed** - Use enums or value objects
7. ❌ **Mutable State** - Prefer immutability where possible

## When in Doubt

Ask: "Would Taylor write it this way?"

If the code doesn't flow naturally or requires mental gymnastics to understand, refactor it.

## Example: Before & After

**Before (Bad):**
```php
public function execute($input, $output) {
    $path = $input->getArgument('path');
    $finder = new ClassFinder();
    $classes = $finder->findInDirectory($path);
    if (empty($classes)) {
        $output->writeln('No classes');
        return 0;
    }
    foreach ($classes as $class) {
        try {
            $parser = new SchemaParser();
            $def = $parser->parse($class);
            // ... 50 more lines
        } catch (Exception $e) {
            // ...
        }
    }
}
```

**After (Good):**
```php
public function execute(InputInterface $input, OutputInterface $output): int
{
    $definitions = $this->discoverAndParseSchemas($input, $output);
    
    if (empty($definitions)) {
        return Command::SUCCESS;
    }
    
    $this->processDefinitions($output, $definitions);
    
    return Command::SUCCESS;
}
```

---

Remember: **Clean code is not about being clever, it's about being clear.**
