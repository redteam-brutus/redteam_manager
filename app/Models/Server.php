<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'host',
        'port',
        'ssh_user',
        'ssh_key_id',
        'host_fingerprint',
        'last_connected_at',
        'last_connection_status',
        'last_connection_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'last_connected_at' => 'datetime',
            'last_connection_status' => ConnectionStatus::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SshKey, $this>
     */
    public function sshKey(): BelongsTo
    {
        return $this->belongsTo(SshKey::class);
    }
}
