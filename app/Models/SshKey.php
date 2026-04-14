<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SshKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

class SshKey extends Model
{
    /** @use HasFactory<SshKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'private_key',
        'public_key',
        'passphrase',
        'fingerprint',
    ];

    protected $hidden = [
        'private_key',
        'passphrase',
    ];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * Derive the OpenSSH public key + SHA256 fingerprint from a PEM private key.
     *
     * @return array{public_key: string, fingerprint: string}
     */
    public static function deriveKeyMaterial(string $privateKey, ?string $passphrase = null): array
    {
        try {
            $key = PublicKeyLoader::loadPrivateKey($privateKey, $passphrase ?? false);
            $public = $key->getPublicKey();
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Unable to parse private key: '.$e->getMessage(), previous: $e);
        }

        $openssh = $public->toString('OpenSSH');
        $fingerprint = $public->getFingerprint('sha256');

        if (! is_string($openssh) || ! is_string($fingerprint)) {
            throw new InvalidArgumentException('Key type does not support OpenSSH serialization.');
        }

        return [
            'public_key' => $openssh,
            'fingerprint' => 'SHA256:'.$fingerprint,
        ];
    }
}
