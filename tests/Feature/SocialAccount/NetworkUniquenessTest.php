<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Models\Workspace;

beforeEach(function () {
    config()->set('trypost.self_hosted', false);
    $this->workspace = Workspace::factory()->create();
});

test('blocks a second account of the same network', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('a compatibility-only personal linkedin row does not block the supported page', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'li-profile',
    ]);

    $page = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    expect($page->exists)->toBeTrue();
});

test('allows different networks in the same workspace', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $x = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'platform_user_id' => 'x-a',
    ]);

    expect($x->exists)->toBeTrue();
});

test('allows the same network in different workspaces', function () {
    $other = Workspace::factory()->create();

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $other->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect($second->exists)->toBeTrue();
});

test('reconnecting the same account via updateOrCreate is allowed', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
        'username' => 'old',
    ]);

    $this->workspace->socialAccounts()->updateOrCreate(
        ['platform' => Platform::Instagram->value, 'platform_user_id' => 'ig-a'],
        ['username' => 'new', 'status' => Status::Connected],
    );

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($this->workspace->socialAccounts()->first()->username)->toBe('new');
});

test('self-hosted mode bypasses the one-per-network rule', function () {
    config()->set('trypost.self_hosted', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]);

    expect($second->exists)->toBeTrue();
});

test('blocks a same-id account connected via a different network variant', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig-id',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'shared-ig-id',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});
