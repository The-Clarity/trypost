<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\AccessTokenRepository as PassportAccessTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Persists workspace_id on MCP OAuth access tokens at issue time — same seam
 * as AuthCodeRepository for consent codes. Personal-access tokens stay null
 * so CreateApiKey (and friends) can bind afterward.
 */
class AccessTokenRepository extends PassportAccessTokenRepository
{
    public function __construct(
        Dispatcher $events,
        private OAuthPayloadDecryptor $decryptor,
    ) {
        parent::__construct($events);
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $id = $accessTokenEntity->getIdentifier();
        $userId = $accessTokenEntity->getUserIdentifier();
        $clientId = $accessTokenEntity->getClient()->getIdentifier();
        $requiresWorkspace = $this->clientRequiresWorkspace($clientId);
        $workspaceId = $requiresWorkspace
            ? $this->resolveWorkspaceId($userId)
            : null;

        if ($requiresWorkspace && $workspaceId === null) {
            throw OAuthServerException::invalidGrant(
                'Unable to bind this connection to a workspace. Reconnect from a workspace you belong to.',
            );
        }

        Passport::token()->forceFill([
            'id' => $id,
            'user_id' => $userId,
            'client_id' => $clientId,
            'workspace_id' => $workspaceId,
            'scopes' => $accessTokenEntity->getScopes(),
            'revoked' => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
        ])->save();

        $this->events->dispatch(new AccessTokenCreated($id, $userId, $clientId));
    }

    private function clientRequiresWorkspace(string $clientId): bool
    {
        $client = Passport::client()->newQuery()->find($clientId);

        return $client !== null && ! $client->hasGrantType('personal_access');
    }

    private function resolveWorkspaceId(?string $userId): ?string
    {
        $grantType = request()->input('grant_type');

        if ($grantType === 'refresh_token') {
            return $this->workspaceFromRefreshedToken();
        }

        if ($grantType === 'authorization_code') {
            $fromAuthCode = $this->workspaceFromAuthCode();

            if ($fromAuthCode !== null && $this->userBelongsToWorkspace($userId, $fromAuthCode)) {
                return $fromAuthCode;
            }
        }

        return $this->workspaceFromUser($userId);
    }

    private function workspaceFromAuthCode(): ?string
    {
        $code = request()->input('code');

        if (! is_string($code) || $code === '') {
            return null;
        }

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
