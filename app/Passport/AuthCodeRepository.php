<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * Persists the chosen workspace on the auth code so the subsequent token
 * exchange (no browser session) can bind the access token.
 *
 * Prefers `workspace_id` from the consent form when present and valid;
 * otherwise falls back to the authorizing user's current workspace (including
 * Passport silent re-consent). Always resolves membership at consent time.
 */
class AuthCodeRepository extends PassportAuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'workspace_id' => $this->resolveWorkspaceId($authCodeEntity->getUserIdentifier()),
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ])->save();
    }

    private function resolveWorkspaceId(?string $userId): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User && $userId) {
            $user = User::query()->find($userId);
        }

        if (! $user instanceof User) {
            return null;
        }

        $requestedId = request()->input('workspace_id');
        $candidateId = is_string($requestedId) && $requestedId !== ''
            ? $requestedId
            : ($user->current_workspace_id ? (string) $user->current_workspace_id : null);

        if ($candidateId === null) {
            return null;
        }

        $workspace = Workspace::query()->find($candidateId);

        if ($workspace === null || ! $user->belongsToWorkspace($workspace)) {
            return null;
        }

        return $candidateId;
    }
}
