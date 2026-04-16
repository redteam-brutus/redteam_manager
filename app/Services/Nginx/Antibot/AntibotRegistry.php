<?php

declare(strict_types=1);

namespace App\Services\Nginx\Antibot;

use App\Models\Server;
use App\Services\Nginx\Antibot\Dto\AntibotSettings;
use App\Services\Nginx\Dto\NginxSaveResult;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Exceptions\SshException;

class AntibotRegistry
{
    public function __construct(
        private readonly NginxManager $nginx,
        private readonly AntibotSettingsParser $parser,
        private readonly AntibotSettingsRenderer $renderer,
        private readonly string $nginxRoot = '/etc/nginx',
    ) {}

    public function load(Server $server): AntibotSettings
    {
        try {
            $content = $this->nginx->readFile($server, $this->managedPath());
        } catch (SshException) {
            return new AntibotSettings;
        }

        return $this->parser->parse($content);
    }

    public function hasManaged(Server $server): bool
    {
        try {
            $this->nginx->readFile($server, $this->managedPath());

            return true;
        } catch (SshException) {
            return false;
        }
    }

    public function save(Server $server, AntibotSettings $settings): NginxSaveResult
    {
        if ($settings->isEmpty()) {
            return $this->disable($server);
        }

        $content = $this->renderer->render($settings);

        return $this->nginx->writeManagedFile($server, $this->managedPath(), $content);
    }

    public function disable(Server $server): NginxSaveResult
    {
        return $this->nginx->deleteManagedFile($server, $this->managedPath());
    }

    private function managedPath(): string
    {
        return $this->nginxRoot.'/conf.d/redteam-antibot.conf';
    }
}
