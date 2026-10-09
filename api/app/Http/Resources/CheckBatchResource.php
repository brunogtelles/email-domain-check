<?php

namespace App\Http\Resources;

use App\Models\CheckBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CheckBatch
 */
class CheckBatchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $counts = $this->statusCounts();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'filename' => $this->filename,
            'total' => $this->total,
            'counts' => $counts,
            'processed' => $counts['completed'] + $counts['failed'],
            'options' => $this->options,
            'created_at' => $this->created_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
