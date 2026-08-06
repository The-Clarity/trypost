<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Listeners\BindWorkspaceToAccessToken;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Passport\AuthCode;
use App\Passport\AuthCodeRepository;
use App\Passport\OAuthPayloadDecryptor;
use Illuminate\Support\Str;
use Laravel\Passport\Events\AccessTokenCreated;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mockery;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();
    $this->clientId = mcpOauthClient();
    $this->decryptor = app(OAuthPayloadDecryptor::class);
});

test('authorization code grant binds workspace from the encrypted auth code payload', function () {
    $authCodeId = Str::random(80);

    AuthCode::query()->forceCreate([
        'id' => $authCodeId,
        'user_id' => $this->user->id,
        'client_id' => $this->clientId,
        'workspace_id' => $this->workspace->id,
        'scopes' => '[]',
        'revoked' => true,
        'expires_at' => now()->addMinutes(10),
    ]);

    // User switched workspace between consent and token exchange — auth code wins.
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);

    $token = mcpAccessToken($this->user, $this->clientId, workspace: null);

    request()->merge([
        'grant_type' => 'authorization_code',
        'code' => $this->decryptor->encrypt([
            'client_id' => $this->clientId,
            'auth_code_id' => $authCodeId,
            'user_id' => (string) $this->user->id,
            'scopes' => [],
            'expire_time' => now()->addMinutes(10)->timestamp,
        ]),
    ]);

    app(BindWorkspaceToAccessToken::class)->handle(new AccessTokenCreated(
        $token->id,
        (string) $this->user->id,
        $this->clientId,
    ));

    expect($token->refresh()->workspace_id)->toBe($this->workspace->id);
});

test('refresh grant inherits workspace from the refreshed access token id', function () {
    $previous = mcpAccessToken($this->user, $this->clientId, $this->workspace);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);

    // Newer grant on another workspace for the same client must not win.
    mcpAccessToken($this->user, $this->clientId, $otherWorkspace);
    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);

    $refreshed = mcpAccessToken($this->user, $this->clientId, workspace: null);

    request()->merge([
        'grant_type' => 'refresh_token',
        'refresh_token' => $this->decryptor->encrypt([
            'client_id' => $this->clientId,
            'refresh_token_id' => Str::random(80),
            'access_token_id' => $previous->id,
            'scopes' => [],
            'user_id' => (string) $this->user->id,
            'expire_time' => now()->addMonth()->timestamp,
        ]),
    ]);

    app(BindWorkspaceToAccessToken::class)->handle(new AccessTokenCreated(
        $refreshed->id,
        (string) $this->user->id,
        $this->clientId,
    ));

    expect($refreshed->refresh()->workspace_id)->toBe($previous->workspace_id);
    expect($refreshed->refresh()->workspace_id)->not->toBe($otherWorkspace->id);
});

test('personal access tokens are left alone for controllers to bind', function () {
    $result = $this->user->createToken('PAT');
    $token = AccessToken::query()->find($result->token->id);

    expect($token->workspace_id)->toBeNull();

    request()->merge(['grant_type' => 'personal_access']);

    app(BindWorkspaceToAccessToken::class)->handle(new AccessTokenCreated(
        $token->id,
        (string) $this->user->id,
        (string) $token->client_id,
    ));

    expect($token->refresh()->workspace_id)->toBeNull();
});

test('auth code repository captures the authorizing user current workspace', function () {
    $this->actingAs($this->user);

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored)->not->toBeNull();
    expect($stored->workspace_id)->toBe($this->workspace->id);
});

test('auth code repository uses current workspace on silent reconsent after switch', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
        'name' => 'Workspace B',
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);
    $this->actingAs($this->user->fresh());

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored->workspace_id)->toBe($otherWorkspace->id);
});

test('auth code repository rejects a current workspace the user no longer belongs to', function () {
    $this->workspace->members()->detach($this->user->id);
    $this->actingAs($this->user->fresh());

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored->workspace_id)->toBeNull();
});

test('oauth grant without a resolvable workspace fails the grant and revokes the token', function () {
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->members()->detach($this->user->id);

    $token = mcpAccessToken($this->user, $this->clientId, workspace: null);

    request()->merge(['grant_type' => 'authorization_code']);

    expect(fn () => app(BindWorkspaceToAccessToken::class)->handle(new AccessTokenCreated(
        $token->id,
        (string) $this->user->id,
        $this->clientId,
    )))->toThrow(OAuthServerException::class);

    expect($token->refresh()->revoked)->toBeTrue();
    expect($token->refresh()->workspace_id)->toBeNull();
});
