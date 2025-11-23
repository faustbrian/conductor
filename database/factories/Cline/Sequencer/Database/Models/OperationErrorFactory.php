<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Database\Factories\Cline\Sequencer\Database\Models;

use Cline\Sequencer\Database\Models\OperationError;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationError>
 */
final class OperationErrorFactory extends Factory
{
    protected $model = OperationError::class;

    public function definition(): array
    {
        return [
            'operation_id' => null,
            'exception' => 'RuntimeException',
            'message' => $this->faker->sentence(),
            'trace' => $this->faker->text(500),
            'context' => [],
            'created_at' => now(),
        ];
    }
}
