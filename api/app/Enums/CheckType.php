<?php

namespace App\Enums;

enum CheckType: string
{
    /** Blacklist (DNSBL) + DNS records health check. */
    case Health = 'health';

    /** Google Workspace / Microsoft 365 / other mail provider detection. */
    case Provider = 'provider';
}
