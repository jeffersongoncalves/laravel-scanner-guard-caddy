<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ScannerGuardCaddy;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JeffersonGoncalves\ScannerGuard\Contracts\DenylistSink;
use Throwable;

/**
 * Writes the denylist as a Caddyfile snippet (a `client_ip` matcher and an
 * `abort`) and reloads Caddy when the file changed. Every IP arrives validated.
 */
class CaddySink implements DenylistSink
{
    /** Config file, overridden by the FrankenPHP sink. */
    protected string $config = 'scanner-guard-caddy';

    public function sync(array $entries): void
    {
        $path = base_path((string) config($this->config.'.path', 'storage/app/scanner-guard/denylist.caddy'));
        $contents = $this->contents($entries);

        if (File::exists($path) && File::get($path) === $contents) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));

        // Temp file + rename: a Caddy reload mid-write never reads a truncated file.
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        File::put($temp, $contents);
        File::move($temp, $path);

        $this->reload();
    }

    /**
     * @param  list<array{ip: string, reason: string, expires_at: string|null}>  $entries
     */
    protected function contents(array $entries): string
    {
        // A matcher without a value is a Caddyfile error, so nothing banned = no matcher.
        if ($entries === []) {
            return "# nothing banned\n";
        }

        $lines = array_map(function (array $entry): string {
            // A forwarded ban's reason is free text: no newline or {$VAR} may reach the Caddyfile.
            $reason = preg_replace('/[^\w.-]/', '_', $entry['reason']);

            return $entry['expires_at'] === null
                ? "\tclient_ip {$entry['ip']} # {$reason}"
                : "\tclient_ip {$entry['ip']} # {$reason}, expires {$entry['expires_at']}";
        }, $entries);

        return "@scanner_guard {\n".implode("\n", $lines)."\n}\nabort @scanner_guard\n";
    }

    protected function reload(): void
    {
        $command = config($this->config.'.reload_command');

        if ($command === null || $command === '') {
            return;
        }

        try {
            $result = Process::run((string) $command);
            $error = $result->failed() ? 'exit '.$result->exitCode().': '.trim($result->errorOutput() ?: $result->output()) : null;
        } catch (Throwable $e) {
            // Timeout or the command can't start: the file is already written, so only report it.
            $error = $e->getMessage();
        }

        if ($error !== null) {
            Log::error('scanner-guard: Caddy reload command failed', ['command' => $command, 'error' => $error]);
        }
    }
}
