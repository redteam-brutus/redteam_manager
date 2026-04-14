<?php

declare(strict_types=1);

namespace App\Enums;

enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case HostMismatch = 'host_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Success => 'Connected',
            self::Failed => 'Failed',
            self::HostMismatch => 'Host key mismatch',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Success => 'success',
            self::Failed => 'danger',
            self::HostMismatch => 'warning',
        };
    }
}
