<?php

declare(strict_types=1);

use App\Actions\AccessToken\RevokeAccessTokens;
use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assign existing MCP OAuth tokens (workspace_id null) to a workspace, or revoke
 * them when no confident mapping exists. Runs in a single transaction so a
 * failure leaves no partially backfilled rows.
 */
return new class extends Migration
{
    /**
     * Test seam: invoked after all chunks and before commit.
     *
     * @var (callable(): void)|null
     */
    public $beforeCommit = null;

    public function up(): void
    {
        DB::beginTransaction();

        try {
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
                ->chunkById(100, function (Collection $tokens): void {
                    $users = User::query()
                        ->whereIn('id', $tokens->pluck('user_id')->unique()->filter()->all())
                        ->with('workspaces')
                        ->get()
                        ->keyBy('id');

                    $toRevoke = collect();

                    foreach ($tokens as $token) {
                        $workspaceId = $this->resolveWorkspaceId(
                            $users->get($token->user_id),
                        );

                        if ($workspaceId === null) {
                            $toRevoke->push($token);

                            continue;
                        }

                        $token->forceFill(['workspace_id' => $workspaceId])->saveQuietly();
                    }

                    if ($toRevoke->isNotEmpty()) {
                        RevokeAccessTokens::execute($toRevoke);
                    }
                });

            if (is_callable($this->beforeCommit)) {
                ($this->beforeCommit)();
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    public function down(): void
    {
        // Irreversible data migration — bound tokens keep their workspace_id.
    }

    private function resolveWorkspaceId(?User $user): ?string
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
};
