<?php

declare(strict_types=1);

use App\Actions\AccessToken\BackfillMcpOAuthWorkspaces;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('backfill binds mcp oauth tokens to the users current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $clientId = mcpOauthClient();
    $token = mcpAccessToken($user, $clientId, workspace: null);

    $result = BackfillMcpOAuthWorkspaces::execute();

    expect($result['bound'])->toBe(1);
    expect($result['revoked'])->toBe(0);
    expect($token->refresh()->workspace_id)->toBe($workspace->id);
});

test('backfill falls back to the first account workspace when current is missing', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => null]);

    $clientId = mcpOauthClient();
    $token = mcpAccessToken($user, $clientId, workspace: null);

    $result = BackfillMcpOAuthWorkspaces::execute();

    expect($result['bound'])->toBe(1);
    expect($token->refresh()->workspace_id)->toBe($workspace->id);
});

test('backfill revokes tokens that cannot be mapped to a workspace', function () {
    $user = User::factory()->create();
    $user->update(['current_workspace_id' => null]);

    $clientId = mcpOauthClient();
    $token = mcpAccessToken($user, $clientId, workspace: null);

    $result = BackfillMcpOAuthWorkspaces::execute();

    expect($result['bound'])->toBe(0);
    expect($result['revoked'])->toBe(1);
    expect($token->refresh()->revoked)->toBeTrue();
    expect($token->refresh()->workspace_id)->toBeNull();
});

test('backfill ignores personal access tokens with null workspace', function () {
    $user = User::factory()->create();
    $result = $user->createToken('PAT');
    $token = $result->token;
    $token->forceFill(['workspace_id' => null])->saveQuietly();

    $stats = BackfillMcpOAuthWorkspaces::execute();

    expect($stats['bound'])->toBe(0);
    expect($stats['revoked'])->toBe(0);
    expect($token->fresh()->revoked)->toBeFalse();
    expect($token->fresh()->workspace_id)->toBeNull();
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

    $clientId = mcpOauthClient();
    $token = mcpAccessToken($user, $clientId, workspace: null);

    $result = BackfillMcpOAuthWorkspaces::execute();

    expect($result['bound'])->toBe(1);
    expect($token->refresh()->workspace_id)->toBe($fallback->id);
});
