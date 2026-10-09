<?php

namespace App\Http\Resources;

use App\Models\CheckItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CheckItem
 */
class CheckItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'input' => $this->input,
            'domain' => $this->domain,
            'selector' => $this->selector,
            'status' => $this->status,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'result' => $this->result,
            'seq' => $this->seq,
        ];
    }
}
