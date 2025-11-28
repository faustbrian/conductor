# Dependencies

Sequencer supports explicit operation dependencies through the `HasDependencies` interface. This ensures operations execute in the correct order beyond chronological timestamp ordering.

## The HasDependencies Interface

```php
use Cline\Sequencer\Contracts\HasDependencies;
use App\Models\Category;
use App\Models\Product;

return new class implements HasDependencies
{
    public function handle(): void
    {
        // Seed products (requires categories to exist)
        Product::create([
            'name' => 'Laptop',
            'category_id' => Category::where('slug', 'technology')->first()->id,
        ]);
    }

    public function dependsOn(): array
    {
        return [
            \Database\Operations\SeedCategories::class,
        ];
    }
};
```

## How Dependency Resolution Works

Sequencer uses topological sorting to order operations respecting dependencies.

**Note**: For advanced dependency-based orchestration with parallel wave execution, use the **DependencyGraphOrchestrator**. See **[Orchestration Strategies](orchestration-strategies.md#dependencygraphorchestrator)** for details.

### Without Dependencies (Timestamp Order)

```
2024_01_01_000000_seed_products
2024_01_02_000000_seed_categories  ← Products fail! Categories don't exist yet
```

### With Dependencies (Dependency-Aware Order)

```php
// SeedProducts declares dependency on SeedCategories
class SeedProducts implements HasDependencies
{
    public function dependsOn(): array
    {
        return [SeedCategories::class];
    }
}
```

Execution order:

```
2024_01_02_000000_seed_categories  ← Executes first (dependency)
2024_01_01_000000_seed_products    ← Executes second (depends on categories)
```

## Common Dependency Patterns

### Schema -> Data

```php
use Cline\Sequencer\Contracts\HasDependencies;

// Data seeding depends on schema creation
return new class implements HasDependencies
{
    public function handle(): void
    {
        User::create(['name' => 'Admin']);
    }

    public function dependsOn(): array
    {
        return [
            \Database\Migrations\CreateUsersTable::class,
        ];
    }
};
```

### Parent -> Child Relationships

```php
use Cline\Sequencer\Contracts\HasDependencies;

// Seed child records after parent records exist
return new class implements HasDependencies
{
    public function handle(): void
    {
        $user = User::first();
        Profile::create(['user_id' => $user->id]);
    }

    public function dependsOn(): array
    {
        return [
            SeedUsers::class,
        ];
    }
};
```

### Multiple Dependencies

```php
use Cline\Sequencer\Contracts\HasDependencies;

return new class implements HasDependencies
{
    public function handle(): void
    {
        // Create order with user, product, and address
    }

    public function dependsOn(): array
    {
        return [
            SeedUsers::class,
            SeedProducts::class,
            SeedAddresses::class,
        ];
    }
};
```

### Chain Dependencies

```php
// Operation A has no dependencies
class SetupDatabase implements Operation { }

// Operation B depends on A
class SeedCategories implements HasDependencies
{
    public function dependsOn(): array
    {
        return [SetupDatabase::class];
    }
}

// Operation C depends on B (which depends on A)
class SeedProducts implements HasDependencies
{
    public function dependsOn(): array
    {
        return [SeedCategories::class];
    }
}
```

Execution order: A → B → C

## Circular Dependency Detection

Sequencer detects circular dependencies and throws an exception:

```php
// Operation A depends on B
class OperationA implements HasDependencies
{
    public function dependsOn(): array
    {
        return [OperationB::class];
    }
}

// Operation B depends on A (circular!)
class OperationB implements HasDependencies
{
    public function dependsOn(): array
    {
        return [OperationA::class];
    }
}
```

Error:

```
RuntimeException: Circular dependency detected in operation dependencies
```

## Dependency Validation

Sequencer validates dependencies at runtime:

```php
class SeedProducts implements HasDependencies
{
    public function dependsOn(): array
    {
        return [
            NonExistentOperation::class,  // ← Error!
        ];
    }
}
```

Error:

```
RuntimeException: Dependency class does not exist: NonExistentOperation
```

## Testing Dependencies

Test that operations declare correct dependencies:

```php
use Tests\TestCase;

class DependencyTest extends TestCase
{
    public function test_operation_declares_dependencies()
    {
        $operation = new SeedProducts();

        $dependencies = $operation->dependsOn();

        $this->assertContains(SeedCategories::class, $dependencies);
    }

    public function test_operations_execute_in_dependency_order()
    {
        // Execute orchestrator
        app(SequentialOrchestrator::class)->process();

        // Verify categories were created before products
        $this->assertTrue(Category::exists());
        $this->assertTrue(Product::exists());
    }
}
```

## Best Practices

### 1. Declare Direct Dependencies Only

Only declare immediate dependencies, not transitive ones:

```php
// Good - only direct dependency
public function dependsOn(): array
{
    return [SeedCategories::class];
}

// Avoid - transitive dependencies are resolved automatically
public function dependsOn(): array
{
    return [
        CreateCategoriesTable::class,  // Transitive through SeedCategories
        SeedCategories::class,
    ];
}
```

### 2. Use Dependencies for Data, Not Schema

Prefer migration dependencies for schema, operation dependencies for data:

```php
// Good - operation depends on another operation
class SeedProducts implements HasDependencies
{
    public function dependsOn(): array
    {
        return [SeedCategories::class];
    }
}

// Less ideal - operation depends on migration
// (migrations run before operations automatically)
class SeedProducts implements HasDependencies
{
    public function dependsOn(): array
    {
        return [CreateProductsTable::class];
    }
}
```

### 3. Document Why Dependencies Exist

Add comments explaining dependency relationships:

```php
public function dependsOn(): array
{
    return [
        // Products require categories to exist for foreign key
        SeedCategories::class,

        // Products need settings for default values
        SeedSettings::class,
    ];
}
```

### 4. Keep Dependency Chains Short

Avoid deep dependency chains when possible:

```php
// Good - flat dependencies
A depends on B
A depends on C

// Avoid - deep chains
A depends on B
B depends on C
C depends on D
D depends on E
```

## Combining with Other Features

### Dependencies + Rollback

```php
use Cline\Sequencer\Contracts\HasDependencies;
use Cline\Sequencer\Contracts\Rollbackable;

return new class implements HasDependencies, Rollbackable
{
    public function handle(): void
    {
        Product::create([...]);
    }

    public function rollback(): void
    {
        Product::truncate();
    }

    public function dependsOn(): array
    {
        return [SeedCategories::class];
    }
};
```

### Dependencies + Conditional

```php
use Cline\Sequencer\Contracts\HasDependencies;
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class implements HasDependencies, ConditionalExecution
{
    public function handle(): void
    {
        Product::create([...]);
    }

    public function shouldRun(): bool
    {
        return app()->environment('production');
    }

    public function dependsOn(): array
    {
        return [SeedCategories::class];
    }
};
```

## Next Steps

- **[Conditional Execution](conditional-execution.md)** - Skip operations based on conditions
- **[Advanced Usage](advanced-usage.md)** - Transactions, observability, and more
