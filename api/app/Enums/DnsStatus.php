<?php

namespace App\Enums;

enum DnsStatus: string
{
    /** The name exists and has records of the requested type. */
    case Ok = 'ok';

    /** The name does not exist. */
    case NxDomain = 'nxdomain';

    /** The name exists but has no records of the requested type. */
    case NoData = 'nodata';

    /** No answer within the configured timeout / retries. */
    case Timeout = 'timeout';

    /** SERVFAIL, REFUSED, network errors... */
    case Error = 'error';
}
