<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\AccessToken\RevokeAccessTokens;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Passport\AuthCode;
use App\Passport\OAuthPayloadDecryptor;
use Laravel\Passport\Events\AccessTokenCreated;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Bind MCP OAuth access tokens to a workspace (mirroring personal API keys).
 *
 * Authorization-code grants copy workspace_id from the auth code captured at
 * consent. Refresh grants inherit the refreshed token's workspace so switching
 * the user's current workspace cannot silently retarget an existing connection.
 */
class BindWorkspaceToAccessToken
{
    public function __construct(private OAuthPayloadDecryptor $decryptor) {}

    public function handle(AccessTokenCreated $event): void
    {
        $token = AccessToken::query()->find($event->tokenId);

        if ($token === null || $token->workspace_id !== null) {
            return;
        }

        $token->loadMissing('client');

        if ($token->client === null || $token->client->hasGrantType('personal_access')) {
            return;
        }

        $workspaceId = $this->resolveWorkspaceId($event);

        if ($workspaceId === null) {
            RevokeAccessTokens::execute($token);

            throw OAuthServerException::invalidGrant(
                'Unable to bind this connection to a workspace. Reconnect from a workspace you belong to.',
            );
        }

        $token->forceFill(['workspace_id' => $workspaceId])->saveQuietly();
    }

    private function resolveWorkspaceId(AccessTokenCreated $event): ?string
    {
        $grantType = request()->input('grant_type');

        if ($grantType === 'refresh_token') {
            return $this->workspaceFromRefreshedToken();
        }

        if ($grantType === 'authorization_code') {
            $fromAuthCode = $this->workspaceFromAuthCode();

            if ($fromAuthCode !== null && $this->userBelongsToWorkspace($event->userId, $fromAuthCode)) {
                return $fromAuthCode;
            }
        }

        return $this->workspaceFromUser($event->userId);
    }

    private function workspaceFromAuthCode(): ?string
    {
        $code = request()->input('code');

        if (! is_string($code) || $code === '') {
            return null;
        }

        // League encrypts the redirect `code`; the DB id lives in auth_code_id.
        $payload = $this->decryptor->decrypt($code);
        $authCodeId = data_get($payload, 'auth_code_id');

        if (! is_string($authCodeId) || $authCodeId === '') {
            return null;
        }

        $authCode = AuthCode::query()->find($authCodeId);

        return $authCode?->workspace_id
            ? (string) $authCode->workspace_id
            : null;
    }

    private function workspaceFromRefreshedToken(): ?string
    {
        $refreshToken = request()->input('refresh_token');

        if (! is_string($refreshToken) || $refreshToken === '') {
            return null;
        }

        $payload = $this->decryptor->decrypt($refreshToken);
        $accessTokenId = data_get($payload, 'access_token_id');

        if (! is_string($accessTokenId) || $accessTokenId === '') {
            return null;
        }

        $previous = AccessToken::query()->find($accessTokenId);

        return $previous?->workspace_id
            ? (string) $previous->workspace_id
            : null;
    }

    private function workspaceFromUser(?string $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        $candidateId = $user->current_workspace_id
            ? (string) $user->current_workspace_id
            : null;

        if ($candidateId !== null && $this->userBelongsToWorkspace($user, $candidateId)) {
            return $candidateId;
        }

        $fallback = $user->accountWorkspaces()->orderBy('workspaces.created_at')->first();

        return $fallback?->id ? (string) $fallback->id : null;
    }

    private function userBelongsToWorkspace(User|string|null $user, string $workspaceId): bool
    {
        if ($user === null) {
            return false;
        }

        if (is_string($user)) {
            $user = User::query()->find($user);
        }

        if (! $user instanceof User) {
            return false;
        }

        $workspace = Workspace::query()->find($workspaceId);

        if ($workspace === null) {
            return false;
        }

        return $user->belongsToWorkspace($workspace);
    }
}
