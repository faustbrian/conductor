<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Models\TestModelWithSequencerPrimaryKey;
use Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

function createTestModel(): Model
{
    return new TestModelWithSequencerPrimaryKey();
}

function simulateCreatingEvent(Model $model): void
{
    $model->triggerCreating();
}

function createOperationFile(string $filename, string $path): void
{
    $filepath = $path.'/'.$filename;

    File::put($filepath, '<?php // Test operation file');
}
