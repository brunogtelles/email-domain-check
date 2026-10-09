<?php

namespace App\Enums;

enum ItemStatus: string
{
    case Queued = 'queued';
    case Checking = 'checking';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
