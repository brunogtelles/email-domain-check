<?php

namespace App\Providers;

use App\Services\DomainCheck\BlacklistChecker;
use App\Services\DomainCheck\Dns\ReactDnsResolver;
use App\Services\DomainCheck\Dns\Resolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Resolver::class, fn (): Resolver => new ReactDnsResolver(
            config('domaincheck.nameservers'),
            config('domaincheck.timeout'),
            config('domaincheck.retries'),
        ));

        $this->app->singleton(BlacklistChecker::class, fn (): BlacklistChecker => new BlacklistChecker(
            resolver: config('domaincheck.dnsbl_nameservers') === []
                ? $this->app->make(Resolver::class)
                : new ReactDnsResolver(config('domaincheck.dnsbl_nameservers'), config('domaincheck.timeout'), config('domaincheck.retries')),
            cache: Cache::store(config('domaincheck.cache_store')),
            lists: config('domaincheck.blacklists'),
            spamhausDqsKey: config('domaincheck.spamhaus_dqs_key'),
            cacheTtl: config('domaincheck.dnsbl_cache_ttl'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // "artisan serve" starts PHP's built-in server with a whitelisted environment. Without
        // TEMP/TMP, PHP on Windows falls back to C:\Windows and file uploads fail at request startup.
        if ($this->app->runningInConsole()) {
            ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, 'TEMP', 'TMP', 'TMPDIR']));
        }

        RateLimiter::for('single-check', fn (Request $request) => Limit::perMinute(config('domaincheck.rate_limits.single_per_minute'))->by($request->ip()));
        RateLimiter::for('bulk-upload', fn (Request $request) => Limit::perMinute(config('domaincheck.rate_limits.uploads_per_minute'))->by($request->ip()));
        RateLimiter::for('batch-poll', fn (Request $request) => Limit::perMinute(config('domaincheck.rate_limits.polls_per_minute'))->by($request->ip()));
    }
}
