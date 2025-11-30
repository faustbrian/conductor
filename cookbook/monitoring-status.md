# Monitoring and Status

This guide covers how to monitor your migrations and operations using the `sequencer:status` command.

## Overview

The `sequencer:status` command provides a comprehensive view of your migration and operation state, including:

- **Pending migrations and operations** that haven't been executed yet
- **Completed operations** with execution timestamps
- **Failed operations** with detailed error information

## Basic Usage

View the complete status of all migrations and operations:

```bash
php artisan sequencer:status
```

This displays:
1. Pending migrations (not yet run)
2. Pending operations (not yet executed)
3. Completed operations (successfully executed)
4. Failed operations (with error details)

## Filtering Options

### Show Only Pending Items

Display only migrations and operations that haven't been executed:

```bash
php artisan sequencer:status --pending
```

**Output includes**:
- Pending migrations with timestamps and names
- Pending operations with timestamps and names

### Show Only Completed Operations

Display only successfully completed operations:

```bash
php artisan sequencer:status --completed
```

**Output includes**:
- Operation name
- Execution type (sync/async)
- Executed at timestamp
- Completed at timestamp

### Show Only Failed Operations

Display only operations that have failed:

```bash
php artisan sequencer:status --failed
```

**Output includes**:
- Operation name
- Failed at timestamp
- Exception type and message
- Stack trace (in verbose mode)

## Understanding the Output

### Pending Migrations

```
Pending Migrations
┌──────────────────┬─────────────────────────────────────┐
│ Timestamp        │ Name                                │
├──────────────────┼─────────────────────────────────────┤
│ 2024_01_15_120000│ 2024_01_15_120000_create_users.php  │
└──────────────────┴─────────────────────────────────────┘
Total: 1 pending migration(s)
```

### Pending Operations

```
Pending Operations
┌──────────────────┬────────────────────────────────────────────┐
│ Timestamp        │ Name                                       │
├──────────────────┼────────────────────────────────────────────┤
│ 2024_01_15_130000│ 2024_01_15_130000_NotifyUsers.php          │
└──────────────────┴────────────────────────────────────────────┘
Total: 1 pending operation(s)
```

### Completed Operations

```
Completed Operations
┌────────────────────────────┬──────┬──────────────────────┬──────────────────────┐
│ Name                       │ Type │ Executed At          │ Completed At         │
├────────────────────────────┼──────┼──────────────────────┼──────────────────────┤
│ 2024_01_14_120000_Sync.php │ sync │ 2024-01-14 12:00:05 │ 2024-01-14 12:00:10 │
└────────────────────────────┴──────┴──────────────────────┴──────────────────────┘
Total: 1 completed operation(s)
```

### Failed Operations

```
Failed Operations

2024_01_13_120000_FailedOperation.php (Failed at: 2024-01-13 12:05:00)
┌──────────────────┬──────────────────────────┬──────────────────────┐
│ Exception        │ Message                  │ Occurred At          │
├──────────────────┼──────────────────────────┼──────────────────────┤
│ RuntimeException │ Database connection lost │ 2024-01-13 12:05:00 │
└──────────────────┴──────────────────────────┴──────────────────────┘

Total: 1 failed operation(s)
```

## Verbose Output

For detailed debugging information, including full stack traces of failed operations:

```bash
php artisan sequencer:status --failed -v
```

**Additional output**:
- Full stack trace for each error
- File paths and line numbers
- Complete exception details

## Common Workflows

### Pre-Deployment Check

Before deploying, check what migrations and operations will run:

```bash
php artisan sequencer:status --pending
```

### Post-Deployment Verification

After deployment, verify everything completed successfully:

```bash
php artisan sequencer:status --completed
php artisan sequencer:status --failed
```

### Debugging Failed Operations

When operations fail, get detailed error information:

```bash
php artisan sequencer:status --failed -v
```

This shows:
1. Which operation failed
2. When it failed
3. The exception type and message
4. Full stack trace for debugging

### Monitoring System Health

Regular status checks to ensure operations are processing:

```bash
# Check if operations are piling up
php artisan sequencer:status --pending

# Check for recent failures
php artisan sequencer:status --failed
```

## Understanding Operation States

Operations can be in one of several states:

1. **Pending**: Discovered in filesystem but not yet executed
2. **In Progress**: Currently being executed (transient state)
3. **Completed**: Successfully finished execution (`completed_at` set)
4. **Failed**: Execution threw an exception (`failed_at` set, errors recorded)

**Note**: Only completed operations (those with `completed_at` set) are considered fully executed. Failed operations can be retried by re-running `sequencer:process`.

## Error Records

When an operation fails, Sequencer records:

- **Exception class**: The type of exception thrown
- **Message**: The error message
- **Stack trace**: Full trace for debugging
- **Context**: Additional contextual data (if available)
- **Timestamp**: When the error occurred

Multiple errors can be recorded for a single operation if it's retried multiple times.

## Integration with Monitoring

The status command is ideal for:

- **CI/CD pipelines**: Verify deployments before going live
- **Monitoring dashboards**: Expose status via scheduled tasks
- **Alerting systems**: Detect and alert on failed operations
- **Audit trails**: Track operation execution history

### Example: Automated Monitoring Script

```bash
#!/bin/bash

# Check for failed operations
FAILED=$(php artisan sequencer:status --failed | grep "Total:" | awk '{print $2}')

if [ "$FAILED" -gt 0 ]; then
    echo "WARNING: $FAILED failed operation(s) detected"
    php artisan sequencer:status --failed -v
    exit 1
fi

echo "All operations healthy"
exit 0
```

## See Also

- [Basic Usage](basic-usage.md) - Learn about creating operations
- [Advanced Usage](advanced-usage.md) - Discover advanced patterns
- [Getting Started](getting-started.md) - Initial setup guide
