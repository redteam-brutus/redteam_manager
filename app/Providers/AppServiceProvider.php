<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\PhpSecLibSshClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SshClient::class, PhpSecLibSshClient::class);
    }

    public function boot(): void
    {
        //
    }
}
