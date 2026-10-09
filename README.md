# Domain Health Checker

A web tool that checks the health of email domains. It combines two checkers:

- **Blacklist & DNS** – checks whether a domain or its mail servers are listed on DNS-based blacklists (DNSBL/RBL) and shows the DNS records that matter for email: MX, SPF, DMARC, DKIM, NS, A, AAAA, CNAME, PTR and, optionally, every other record found.
- **Email Provider** – detects whether a domain uses **Google Workspace**, **Microsoft 365**, another mail provider, or no detectable provider, and explains the evidence behind the answer.

Both checkers accept a single domain or email address (the domain is extracted automatically) or a CSV/TXT file for bulk checks. Bulk files are processed in the background, and each row appears in the UI as soon as its domain is checked.

## Features

- **Single check:** enter a domain or email address and get the full result immediately.
- **Bulk check:** upload a CSV or TXT file (up to 5,000 unique domains by default). A `domain`/`email` header column is detected automatically, and an optional `selector` column sets the DKIM selector per row. Duplicate domains and invalid lines are reported.
- **Live progress:** shows "347 / 1,000 checked" and a per-row status of *Queued → Checking → Completed / Failed*.
- **Filters:** Clean, Blacklisted, No MX, No DMARC, Google, Microsoft, Other, Not Detected, Failed and In progress, plus search by domain.
- **CSV export** of finished results in the current filter.
- **Resilient DNS:** every query has a timeout and retries, and falls back to the next nameserver. Timeouts and errors are reported per record instead of being treated as "not found".
- **Rate limiting** on the API and a concurrency limit on bulk processing, so large uploads do not overload the server or the DNS providers.

## How it works

### Blacklist & DNS

1. All DNS lookups for the domain run concurrently: A, AAAA, MX, TXT, NS, SOA, CNAME, `_dmarc` and DKIM.
2. The IPv4 addresses of the domain and of its MX servers are checked against IP blacklists (Spamhaus ZEN, SpamCop, Barracuda, PSBL, Mailspike, UCEPROTECT, DroneBL). The domain itself is checked against domain blacklists (Spamhaus DBL, SURBL, URIBL).
3. SPF and DMARC records are parsed and checked for common problems: multiple records, `+all`, more than 10 DNS lookups, `p=none`, missing reporting address, and so on.
4. DKIM uses the selector provided by the user. Selectors cannot be discovered through DNS, so when none is given 27 common selectors are probed (`google`, `selector1`, `selector2`, `default`, `k1`...).

A blacklist answer that means "query refused" (for example Spamhaus' `127.255.255.254`) is reported as an **error**, never as **listed**. This avoids false positives.

### Email Provider

The primary (lowest priority) MX record decides the provider:

| MX points to | Result |
| --- | --- |
| `*.google.com`, `*.googlemail.com` | Google Workspace |
| `*.mail.protection.outlook.com`, `*.mx.microsoft` | Microsoft 365 |
| A known provider (Zoho, Proton, Fastmail, Amazon WorkMail...) | Other (named) |
| No MX, or a null MX | Not Detected |

Supporting evidence is collected and shown with the result: SPF includes, verification TXT records (`MS=ms…`, `google-site-verification`), DKIM selectors and the `autodiscover` CNAME. This evidence also identifies Google or Microsoft behind security gateways such as Proofpoint or Mimecast (for example *"Microsoft 365 (via Proofpoint)"*). Every result has a confidence level of high, medium or low.

### Bulk processing

```
Upload ──► API parses the file, creates one row per domain, splits rows into queue jobs
                                                    │
Queue workers ──► each job checks N domains concurrently and saves every status change
                                                    │
React UI ──► polls every second for rows changed since the last poll (incremental cursor)
```

- Each upload is split into jobs of 20 domains (`BULK_CHUNK_SIZE`). Each job checks 10 domains at a time (`BULK_CONCURRENCY`). The total load is roughly *number of workers × concurrency*.
- Every row update gets a sequence number. The frontend only asks for rows with a sequence greater than the last one it received, so it never downloads the whole list again.
- Blacklist answers are cached, so domains that share mail servers (for example many Google Workspace customers) do not query the same IPs again.

## Tech stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13, [react/dns](https://github.com/reactphp/dns) + react/async for concurrent, non-blocking DNS |
| Queue / database | Laravel database queue, SQLite by default (any Laravel-supported database works) |
| Frontend | React 19, Webpack 5, Tailwind CSS 4, Axios |
| Tests | PHPUnit, with a fake DNS resolver so the tests run without network access |

## Running locally

### Requirements

- PHP 8.3 or newer with the `pdo_sqlite` extension (and `intl` for internationalized domain names)
- Composer
- Node.js 20 or newer and npm

### 1. Backend (Laravel API)

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate        # creates database/database.sqlite if it does not exist
php artisan serve          # http://127.0.0.1:8000
```

In a **second terminal**, start a queue worker. Bulk uploads stay *Queued* until a worker is running:

```bash
cd api
php artisan queue:work
```

To process bulk files faster, run more workers (2–4 is a good start), each in its own terminal or under a process manager such as Supervisor.

### 2. Frontend (React)

```bash
cd frontend
npm install
npm run dev                # http://localhost:5173
```

Open **http://localhost:5173**. The dev server forwards `/api` requests to `http://127.0.0.1:8000`, so no CORS setup is needed. If the API runs on another address, set `API_PROXY_TARGET`:

```bash
API_PROXY_TARGET=http://127.0.0.1:8080 npm run dev
```

For a production build, set the API's public URL and serve the files in `frontend/dist`:

```bash
API_URL=https://api.example.com/api npm run build
```

### 3. Try it

- **Single check:** type `john@github.com` in the *Email Provider* tab. It should show Microsoft 365 with its evidence.
- **Blacklist example:** in the *Blacklist & DNS* tab, check `surbl-org-permanent-test-point.com`, a permanent test entry that SURBL always lists.
- **Bulk check:** upload a file like this:

  ```csv
  email
  someone@google.com
  someone@microsoft.com
  someone@zoho.com
  not-a-valid-entry
  ```

### Running the tests

```bash
cd api
php artisan test
```

## Configuration

All settings are in [`api/config/domaincheck.php`](api/config/domaincheck.php), including the blacklists, the provider patterns and the DKIM selectors. The main ones can be changed in `api/.env`:

| Variable | Default | Description |
| --- | --- | --- |
| `DNS_NAMESERVERS` | `1.1.1.1,8.8.8.8` | Resolvers used for DNS lookups, tried in order |
| `DNSBL_NAMESERVERS` | *(same as above)* | Separate resolvers for blacklist queries (see below) |
| `SPAMHAUS_DQS_KEY` | – | Free Spamhaus Data Query Service key |
| `DNS_TIMEOUT` / `DNS_RETRIES` | `2` / `1` | Seconds per query attempt / retries per nameserver |
| `BULK_MAX_ROWS` | `5000` | Maximum unique domains per upload |
| `BULK_CHUNK_SIZE` | `20` | Domains per queue job |
| `BULK_CONCURRENCY` | `10` | Domains checked at the same time inside each job |
| `RATE_LIMIT_SINGLE` / `RATE_LIMIT_UPLOADS` / `RATE_LIMIT_POLLS` | `60` / `10` / `300` | Requests per minute per IP |

### Important: blacklists and public DNS resolvers

Several major blacklists (Spamhaus, URIBL, SURBL) **refuse queries that come from large public resolvers** such as 1.1.1.1 and 8.8.8.8. With the default settings these lists show as **error**, not as clean, and the rest of the lists work normally. To get full blacklist coverage, do one or both of the following:

- set `DNSBL_NAMESERVERS` to your own recursive resolver (for example a local [Unbound](https://nlnetlabs.nl/projects/unbound/)), and/or
- register for a free [Spamhaus DQS](https://www.spamhaus.com/free-trial/free-data-query-service/) key and set `SPAMHAUS_DQS_KEY`.

## API reference

| Method | Endpoint | Description |
| --- | --- | --- |
| `POST` | `/api/checks/{health\|provider}` | Single check. Body: `input`, optional `selector` and `all_records` (health only) |
| `POST` | `/api/batches` | Bulk upload (multipart). Fields: `type` (`health`/`provider`), `file`, optional `selector`, `all_records` |
| `GET` | `/api/batches/{id}?after={cursor}` | Batch progress plus rows changed after `cursor` |
| `POST` | `/api/batches/{id}/cancel` | Cancel a batch. Queued rows are marked as failed |

Single checks and batch rows share the same shape: `{ input, domain, status, error, result, duration_ms }`.

## Project structure

```
api/                                 Laravel API
├── app/Services/DomainCheck/
│   ├── HealthChecker.php            Blacklist + DNS check
│   ├── ProviderDetector.php         Google / Microsoft / other detection
│   ├── BlacklistChecker.php         DNSBL queries, refusal handling, caching
│   ├── EmailAuthAnalyzer.php        SPF and DMARC parsing
│   ├── BulkInputParser.php          CSV/TXT parsing, header detection, de-duplication
│   ├── DomainNormalizer.php         Email / URL / IDN → domain
│   └── Dns/                         Resolver with timeouts, retries and fallback
├── app/Jobs/ProcessCheckItems.php   Queue job that checks a chunk of domains concurrently
├── app/Http/Controllers/            Single check and batch endpoints
├── config/domaincheck.php           Blacklists, providers, limits, timeouts
└── tests/                           Unit and feature tests

frontend/                            React app
└── src/
    ├── api/                         Axios client and endpoint calls
    ├── hooks/useBatchPolling.js     Incremental polling with back-off
    ├── components/                  Forms, progress bar, results table, detail panels
    └── lib/                         Filters, CSV export, formatting
```
