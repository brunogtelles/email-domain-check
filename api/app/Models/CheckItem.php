<?php

namespace App\Models;

use App\Enums\ItemStatus;
use Database\Factories\CheckItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['check_batch_id', 'input', 'domain', 'selector', 'status', 'seq', 'result', 'error', 'duration_ms'])]
class CheckItem extends Model
{
    /** @use HasFactory<CheckItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => ItemStatus::class,
            'result' => 'array',
            'seq' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CheckBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CheckBatch::class, 'check_batch_id');
    }
}
