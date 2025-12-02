<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Database\Factories\Cline\Sequencer\Database\Models;

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Enums\OperationState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operation>
 */
final class OperationFactory extends Factory
{
    protected $model = Operation::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'type' => 'sync',
            'state' => OperationState::Pending,
            'executed_at' => now(),
            'completed_at' => null,
            'failed_at' => null,
            'rolled_back_at' => null,
        ];
    }
}
