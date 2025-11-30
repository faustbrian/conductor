<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Models;

use Cline\Sequencer\Database\Concerns\HasSequencerPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TestModelWithSequencerPrimaryKey extends Model
{
    use HasSequencerPrimaryKey;
    use HasFactory;

    public function triggerCreating(): void
    {
        $this->fireModelEvent('creating');
    }
}
