<?php

declare(strict_types=1);

use App\Filament\Resources\SshKeys\Pages\CreateSshKey;
use App\Filament\Resources\SshKeys\Pages\EditSshKey;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use phpseclib3\Crypt\EC;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->privateKeyPem = EC::createKey('Ed25519')->toString('OpenSSH');
});

it('creates a key and derives the public key and fingerprint', function () {
    Livewire::test(CreateSshKey::class)
        ->fillForm([
            'name' => 'deploy-key',
            'private_key' => $this->privateKeyPem,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $key = SshKey::firstWhere('name', 'deploy-key');

    expect($key)->not->toBeNull()
        ->and($key->public_key)->toStartWith('ssh-')
        ->and($key->fingerprint)->toStartWith('SHA256:')
        ->and($key->private_key)->toBe($this->privateKeyPem);
});

it('stores the private key encrypted at rest', function () {
    $key = SshKey::factory()->create([
        'private_key' => $this->privateKeyPem,
    ]);

    $raw = DB::table('ssh_keys')->where('id', $key->id)->value('private_key');

    expect($raw)->not->toBe($this->privateKeyPem)
        ->and($raw)->not->toContain('BEGIN OPENSSH PRIVATE KEY');
});

it('rejects a private key that is not PEM encoded', function () {
    Livewire::test(CreateSshKey::class)
        ->fillForm([
            'name' => 'bad-key',
            'private_key' => 'not a key',
        ])
        ->call('create')
        ->assertHasFormErrors(['private_key']);
});

it('keeps the existing key when the edit form leaves private_key blank', function () {
    $key = SshKey::factory()->create([
        'private_key' => $this->privateKeyPem,
        'public_key' => 'existing-public-key',
        'fingerprint' => 'SHA256:existing',
    ]);

    Livewire::test(EditSshKey::class, ['record' => $key->getRouteKey()])
        ->fillForm([
            'name' => 'renamed-key',
            'private_key' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $key->refresh();

    expect($key->name)->toBe('renamed-key')
        ->and($key->public_key)->toBe('existing-public-key')
        ->and($key->fingerprint)->toBe('SHA256:existing')
        ->and($key->private_key)->toBe($this->privateKeyPem);
});
