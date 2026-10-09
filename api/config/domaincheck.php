<?php

$csv = fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [

    /*
    |--------------------------------------------------------------------------
    | DNS resolution
    |--------------------------------------------------------------------------
    |
    | Nameservers are tried in order (the next one is used when a query fails).
    | Each query waits "timeout" seconds and is retried "retries" times on the
    | same nameserver before falling back.
    |
    | Many DNSBLs (Spamhaus, URIBL...) refuse queries coming from big public
    | resolvers such as 8.8.8.8 / 1.1.1.1. Those refusals are reported as errors,
    | never as "listed". For reliable results point DNSBL_NAMESERVERS at your own
    | recursive resolver (e.g. a local Unbound) and/or set SPAMHAUS_DQS_KEY.
    |
    */

    'nameservers' => $csv(env('DNS_NAMESERVERS', '1.1.1.1,8.8.8.8')),

    'dnsbl_nameservers' => $csv(env('DNSBL_NAMESERVERS')),

    'timeout' => (float) env('DNS_TIMEOUT', 2.0),

    'retries' => (int) env('DNS_RETRIES', 1),

    'spamhaus_dqs_key' => env('SPAMHAUS_DQS_KEY'),

    /*
    | DNSBL answers are cached (per list + IP/domain) so a bulk upload where many
    | domains share the same mail servers does not hammer the lists.
    */
    'cache_store' => env('DOMAINCHECK_CACHE_STORE', 'file'),

    'dnsbl_cache_ttl' => (int) env('DNSBL_CACHE_TTL', 1800),

    /*
    | Maximum number of distinct IPv4 addresses (domain A + MX hosts) checked
    | against IP-based blacklists per domain.
    */
    'max_ips_per_domain' => (int) env('DNSBL_MAX_IPS', 6),

    /*
    |--------------------------------------------------------------------------
    | Bulk processing
    |--------------------------------------------------------------------------
    |
    | Uploads are split into jobs of "chunk_size" domains. Each job checks up to
    | "concurrency" domains at the same time, so the total load is roughly
    | queue workers x concurrency domains in flight.
    |
    */

    'bulk' => [
        'max_rows' => (int) env('BULK_MAX_ROWS', 5000),
        'max_file_kb' => (int) env('BULK_MAX_FILE_KB', 5120),
        'chunk_size' => (int) env('BULK_CHUNK_SIZE', 20),
        'concurrency' => (int) env('BULK_CONCURRENCY', 10),
        'poll_page_size' => 500,
    ],

    'rate_limits' => [
        'single_per_minute' => (int) env('RATE_LIMIT_SINGLE', 60),
        'uploads_per_minute' => (int) env('RATE_LIMIT_UPLOADS', 10),
        'polls_per_minute' => (int) env('RATE_LIMIT_POLLS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | DKIM
    |--------------------------------------------------------------------------
    |
    | DKIM selectors cannot be discovered through DNS. When the user does not
    | provide one, these common selectors are probed.
    |
    */

    'dkim_selectors' => [
        'google', 'selector1', 'selector2', 'default', 'dkim', 'mail', 'k1', 'k2', 'k3',
        's1', 's2', 'smtp', 'mx', 'zoho', 'zmail', 'protonmail', 'protonmail2', 'protonmail3',
        'fm1', 'fm2', 'fm3', 'mandrill', 'mailjet', 'everlytic', 'mxvault', 'sig1', 'turbo-smtp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blacklists (DNSBL / RBL)
    |--------------------------------------------------------------------------
    |
    | target: "ip" lists are queried with reversed IPv4 octets, "domain" lists
    | with the domain name. error_codes are answers that mean "your query was
    | refused / invalid" rather than "listed". Any answer outside 127.0.0.0/8 is
    | also treated as an error (resolvers that hijack NXDOMAIN).
    |
    */

    'blacklists' => [
        [
            'name' => 'Spamhaus ZEN',
            'zone' => 'zen.spamhaus.org',
            'dqs_zone' => 'zen.dq.spamhaus.net',
            'target' => 'ip',
            'error_codes' => ['127.255.255.252', '127.255.255.254', '127.255.255.255'],
            'codes' => [
                '127.0.0.2' => 'SBL - known spam source',
                '127.0.0.3' => 'SBL CSS - snowshoe spam',
                '127.0.0.4' => 'XBL - exploited / infected host',
                '127.0.0.9' => 'DROP - hijacked netblock',
                '127.0.0.10' => 'PBL - end-user IP (ISP policy)',
                '127.0.0.11' => 'PBL - end-user IP (Spamhaus policy)',
            ],
        ],
        [
            'name' => 'SpamCop',
            'zone' => 'bl.spamcop.net',
            'target' => 'ip',
            'error_codes' => [],
            'codes' => ['127.0.0.2' => 'Reported spam source'],
        ],
        [
            'name' => 'Barracuda',
            'zone' => 'b.barracudacentral.org',
            'target' => 'ip',
            'error_codes' => [],
            'codes' => ['127.0.0.2' => 'Poor reputation'],
        ],
        [
            'name' => 'PSBL',
            'zone' => 'psbl.surriel.com',
            'target' => 'ip',
            'error_codes' => [],
            'codes' => ['127.0.0.2' => 'Spam trap hits'],
        ],
        [
            'name' => 'Mailspike',
            'zone' => 'bl.mailspike.net',
            'target' => 'ip',
            'error_codes' => [],
            'codes' => ['127.0.0.2' => 'Spam source'],
        ],
        [
            'name' => 'UCEPROTECT L1',
            'zone' => 'dnsbl-1.uceprotect.net',
            'target' => 'ip',
            'error_codes' => [],
            'codes' => ['127.0.0.2' => 'Spam source'],
        ],
        [
            'name' => 'DroneBL',
            'zone' => 'dnsbl.dronebl.org',
            'target' => 'ip',
            'error_codes' => [],
            'codes' => [],
        ],
        [
            'name' => 'Spamhaus DBL',
            'zone' => 'dbl.spamhaus.org',
            'dqs_zone' => 'dbl.dq.spamhaus.net',
            'target' => 'domain',
            'error_codes' => ['127.0.1.255', '127.255.255.252', '127.255.255.254', '127.255.255.255'],
            'codes' => [
                '127.0.1.2' => 'Spam domain',
                '127.0.1.4' => 'Phishing domain',
                '127.0.1.5' => 'Malware domain',
                '127.0.1.6' => 'Botnet C&C domain',
                '127.0.1.102' => 'Abused legit domain (spam)',
                '127.0.1.103' => 'Abused legit domain (redirector)',
                '127.0.1.104' => 'Abused legit domain (phishing)',
                '127.0.1.105' => 'Abused legit domain (malware)',
                '127.0.1.106' => 'Abused legit domain (botnet C&C)',
            ],
        ],
        [
            'name' => 'SURBL',
            'zone' => 'multi.surbl.org',
            'target' => 'domain',
            'error_codes' => ['127.0.0.1'],
            'codes' => [],
        ],
        [
            'name' => 'URIBL',
            'zone' => 'multi.uribl.com',
            'target' => 'domain',
            'error_codes' => ['127.0.0.1'],
            'codes' => [
                '127.0.0.2' => 'Black list',
                '127.0.0.4' => 'Grey list',
                '127.0.0.8' => 'Red list',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail provider detection
    |--------------------------------------------------------------------------
    |
    | MX host suffixes decide the provider. SPF includes, verification TXT
    | records, DKIM selectors and autodiscover CNAMEs are used as supporting
    | evidence (and to see through security gateways such as Proofpoint).
    |
    */

    'providers' => [
        'google' => [
            'label' => 'Google Workspace',
            'mx' => ['google.com', 'googlemail.com'],
            'spf' => ['_spf.google.com'],
            'txt' => ['google-site-verification='],
            'dkim_txt' => ['google'],
            'dkim_cname' => [],
            'autodiscover' => [],
        ],
        'microsoft' => [
            'label' => 'Microsoft 365',
            'mx' => ['mail.protection.outlook.com', 'mx.microsoft', 'eo.outlook.com'],
            'spf' => ['spf.protection.outlook.com'],
            'txt' => ['ms=ms'],
            'dkim_txt' => [],
            'dkim_cname' => ['selector1' => 'onmicrosoft.com', 'selector2' => 'onmicrosoft.com'],
            'autodiscover' => ['autodiscover.outlook.com'],
        ],
    ],

    'other_providers' => [
        'Zoho Mail' => ['zoho.com', 'zoho.eu', 'zoho.in', 'zoho.com.au', 'zohomail.com'],
        'Proton Mail' => ['protonmail.ch', 'proton.me'],
        'Yahoo Mail' => ['yahoodns.net'],
        'Fastmail' => ['messagingengine.com'],
        'iCloud Mail' => ['icloud.com'],
        'GoDaddy Email' => ['secureserver.net'],
        'Amazon WorkMail / SES' => ['amazonaws.com', 'awsapps.com'],
        'Yandex 360' => ['yandex.net', 'yandex.ru'],
        'Rackspace Email' => ['emailsrvr.com'],
        'Titan Email' => ['titan.email'],
        'Namecheap Private Email' => ['privateemail.com', 'registrar-servers.com'],
        'IONOS' => ['ionos.com', 'ionos.de', '1and1.com', 'kundenserver.de'],
        'OVHcloud' => ['ovh.net', 'ovh.com'],
        'Hostinger' => ['hostinger.com', 'hostinger.com.br'],
        'HostGator' => ['hostgator.com', 'hostgator.com.br', 'websitewelcome.com'],
        'Bluehost' => ['bluehost.com'],
        'Locaweb' => ['locaweb.com.br'],
        'KingHost' => ['kinghost.net'],
        'UOL Host' => ['uhserver.com'],
        'Mail.ru' => ['mail.ru'],
        'Gandi' => ['gandi.net'],
        'Cloudflare Email Routing' => ['mx.cloudflare.net'],
        'ImprovMX' => ['improvmx.com'],
        'Forward Email' => ['forwardemail.net'],
        'Mailgun' => ['mailgun.org'],
    ],

    'gateways' => [
        'Mimecast' => ['mimecast.com', 'mimecast.co.za'],
        'Proofpoint' => ['pphosted.com', 'ppe-hosted.com'],
        'Barracuda' => ['barracudanetworks.com', 'cudamail.com'],
        'Cisco Secure Email' => ['iphmx.com'],
        'Symantec Email Security' => ['messagelabs.com'],
        'Forcepoint' => ['mailcontrol.com'],
        'Trend Micro' => ['trendmicro.com', 'trendmicro.eu'],
        'Sophos' => ['sophos.com'],
        'Hornetsecurity' => ['hornetsecurity.com'],
        'SpamExperts' => ['spamexperts.com', 'antispamcloud.com'],
        'Fortinet FortiMail' => ['fortimail.com'],
        'Libraesva' => ['libraesva.com'],
    ],

];
