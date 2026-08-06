<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Assign existing MCP OAuth tokens (workspace_id null) to a workspace, or revoke
 * them when no confident mapping exists.
 *
 * @return array{bound: int, revoked: int}
 */
class BackfillMcpOAuthWorkspaces
{
    /**
     * @return array{bound: int, revoked: int}
     */
    public static function execute(): array
    {
        $bound = 0;
        $revoked = 0;

        AccessToken::query()
            ->whereNull('workspace_id')
            ->where('revoked', false)
            ->whereHas(
                'client',
                fn (Builder $client): Builder => $client
                    ->where('revoked', false)
                    ->whereJsonDoesntContain('grant_types', 'personal_access'),
            )
            ->orderBy('id')
            ->chunkById(100, function (Collection $tokens) use (&$bound, &$revoked): void {
                $users = User::query()
                    ->whereIn('id', $tokens->pluck('user_id')->unique()->filter()->all())
                    ->with('workspaces')
                    ->get()
                    ->keyBy('id');

                $toRevoke = collect();

                foreach ($tokens as $token) {
                    $workspaceId = self::resolveWorkspaceId(
                        $users->get($token->user_id),
                    );

                    if ($workspaceId === null) {
                        $toRevoke->push($token);

                        continue;
                    }

                    $token->forceFill(['workspace_id' => $workspaceId])->saveQuietly();
                    $bound++;
                }

                if ($toRevoke->isNotEmpty()) {
                    RevokeAccessTokens::execute($toRevoke);
                    $revoked += $toRevoke->count();
                }
            });

        return ['bound' => $bound, 'revoked' => $revoked];
    }

    private static function resolveWorkspaceId(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $accountWorkspaces = $user->workspaces
            ->where('account_id', $user->account_id)
            ->sortBy('created_at')
            ->values();

        if ($accountWorkspaces->isEmpty()) {
            return null;
        }

        if ($user->current_workspace_id) {
            $current = $accountWorkspaces->firstWhere('id', $user->current_workspace_id);

            if ($current) {
                return (string) $current->id;
            }
        }

        return (string) $accountWorkspaces->first()->id;
    }
}
