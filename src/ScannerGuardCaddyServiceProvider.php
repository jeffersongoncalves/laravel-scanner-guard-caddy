<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ScannerGuardCaddy;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ScannerGuardCaddyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-scanner-guard-caddy')
            ->hasConfigFile('scanner-guard-caddy');
    }
}
