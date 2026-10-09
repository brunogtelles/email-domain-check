<?php

namespace App\Enums;

enum BatchStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
