<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

/**
 * Load the data migration as an instance so its up() can run against rows that
 * still have unbound MCP OAuth grants.
 */
function backfillMcpOAuthTokenWorkspacesMigration(): object
{
    return require database_path('migrations/2026_08_06_144848_backfill_mcp_oauth_token_workspaces.php');
}

test('backfill binds mcp oauth tokens to the users current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->workspace_id)->toBe($workspace->id)
        ->and($token->refresh()->revoked)->toBeFalse();
});

test('backfill falls back to the first account workspace when current is missing', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => null]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->workspace_id)->toBe($workspace->id);
});

test('backfill revokes tokens that cannot be mapped to a workspace', function () {
    $user = User::factory()->create();
    $user->update(['current_workspace_id' => null]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->revoked)->toBeTrue()
        ->and($token->refresh()->workspace_id)->toBeNull();
});

test('backfill ignores personal access tokens with null workspace', function () {
    $user = User::factory()->create();
    $result = $user->createToken('PAT');
    $token = $result->token;
    $token->forceFill(['workspace_id' => null])->saveQuietly();

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->fresh()->revoked)->toBeFalse()
        ->and($token->fresh()->workspace_id)->toBeNull();
});

test('backfill falls back when current workspace membership was removed', function () {
    $user = User::factory()->create();
    $current = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $fallback = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $fallback->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $current->id]);

    $token = mcpAccessToken($user, mcpOauthClient(), workspace: null);

    backfillMcpOAuthTokenWorkspacesMigration()->up();

    expect($token->refresh()->workspace_id)->toBe($fallback->id);
});
