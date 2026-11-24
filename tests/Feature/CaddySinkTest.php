<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JeffersonGoncalves\ScannerGuardCaddy\CaddySink;

beforeEach(function () {
    config(['scanner-guard-caddy.path' => 'storage/app/scanner-guard/denylist-test.caddy']);
    $this->file = storage_path('app/scanner-guard/denylist-test.caddy');
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/scanner-guard'));
});

it('writes static entries and bans as a Caddy matcher and an abort', function () {
    (new CaddySink)->sync([
        ['ip' => '185.177.72.0/24', 'reason' => 'static', 'expires_at' => null],
        ['ip' => '2001:db8::1', 'reason' => 'scanner_path', 'expires_at' => '2026-11-20T10:00:00+00:00'],
    ]);

    expect(File::get($this->file))->toBe(
        "@scanner_guard {\n"
        ."\tclient_ip 185.177.72.0/24 # static\n"
        ."\tclient_ip 2001:db8::1 # scanner_path, expires 2026-11-20T10:00:00+00:00\n"
        ."}\n"
        ."abort @scanner_guard\n"
    )->and(File::glob(storage_path('app/scanner-guard/*.tmp')))->toBe([]);
});

it('writes no matcher when nothing is banned', function () {
    (new CaddySink)->sync([]);

    expect(File::get($this->file))->toBe("# nothing banned\n");
});

it('keeps a hostile reason inside its comment', function () {
    (new CaddySink)->sync([['ip' => '10.20.20.20', 'reason' => "x\n}\nrespond {\$APP_KEY}", 'expires_at' => null]]);

    expect(File::get($this->file))->toBe("@scanner_guard {\n\tclient_ip 10.20.20.20 # x___respond___APP_KEY_\n}\nabort @scanner_guard\n");
});

it('runs the reload command only when the file content changed', function () {
    Process::fake();
    config(['scanner-guard-caddy.reload_command' => 'caddy reload']);
    $entries = [['ip' => '10.20.20.23', 'reason' => 'static', 'expires_at' => null]];

    (new CaddySink)->sync($entries);
    (new CaddySink)->sync($entries);
    (new CaddySink)->sync([]);

    Process::assertRanTimes('caddy reload', 2);
});

it('logs instead of failing when the reload command fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Error: sending configuration', exitCode: 1)]);
    Log::spy();
    config(['scanner-guard-caddy.reload_command' => 'caddy reload']);

    (new CaddySink)->sync([]);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'scanner-guard: Caddy reload command failed'
        && str_contains($context['error'], 'sending configuration'));
});

it('logs instead of failing when the reload command throws (e.g. a timeout)', function () {
    Process::fake(fn () => throw new RuntimeException('The process exceeded the timeout of 60 seconds.'));
    Log::spy();
    config(['scanner-guard-caddy.reload_command' => 'caddy reload']);

    (new CaddySink)->sync([]);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($context['error'], 'timeout'));
});

it('runs a failed reload again on the next sync, until it succeeds', function () {
    Process::fake(['*' => Process::sequence()->push(Process::result(exitCode: 1))->push(Process::result())]);
    config(['scanner-guard-caddy.reload_command' => 'caddy reload']);

    (new CaddySink)->sync([]); // fails
    (new CaddySink)->sync([]); // retried
    (new CaddySink)->sync([]); // up to date

    Process::assertRanTimes('caddy reload', 2);
    expect(File::glob(storage_path('app/scanner-guard/*.reload')))->toBe([]);
});

it('is driven by scanner-guard:sync-denylist', function () {
    config([
        'scanner-guard.driver' => 'http', // no database needed: bans come from the (empty) last pull
        'scanner-guard.sinks' => [CaddySink::class],
        'scanner-guard.ip_blocklist' => ['185.177.72.69', 'not-an-ip'],
    ]);

    $this->artisan('scanner-guard:sync-denylist')->assertSuccessful();

    expect(File::get($this->file))->toBe("@scanner_guard {\n\tclient_ip 185.177.72.69 # static\n}\nabort @scanner_guard\n");
});
