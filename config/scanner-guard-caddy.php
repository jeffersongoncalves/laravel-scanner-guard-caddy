<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Denylist Path
    |--------------------------------------------------------------------------
    |
    | Relative to base_path(). `import` it from your Caddy site block. The file
    | is written to a temp file and renamed over the target, so Caddy never
    | reads a half-written list, and only when its content changed.
    |
    */
    'path' => env('SCANNER_GUARD_CADDY_PATH', 'storage/app/scanner-guard/denylist.caddy'),

    /*
    |--------------------------------------------------------------------------
    | Reload Command
    |--------------------------------------------------------------------------
    |
    | Caddy only re-reads an imported file on reload (or with --watch). Runs
    | (e.g. "caddy reload --config /etc/caddy/Caddyfile") only when the file
    | content changed; a non-zero exit or a timeout is logged, never thrown.
    | Null = don't reload.
    |
    */
    'reload_command' => env('SCANNER_GUARD_CADDY_RELOAD_COMMAND'),

];
