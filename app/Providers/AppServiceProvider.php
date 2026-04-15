<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SiteLogs\UserAgentParser;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\PhpSecLibSshClient;
use App\Services\Ssh\SshConnectionManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SshClient::class, PhpSecLibSshClient::class);
        $this->app->scoped(SshConnectionManager::class);
        $this->app->singleton(UserAgentParser::class);
    }

    public function boot(): void
    {
        //
    }
}
