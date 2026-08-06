<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\User;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Scope;

class AuthorizationView
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __invoke(array $parameters): Response
    {
        $user = data_get($parameters, 'user');

        $workspaces = $user instanceof User
            ? $user->accountWorkspaces()->orderBy('name')->get()
            : collect();

        $currentId = $user instanceof User ? $user->current_workspace_id : null;
        $selectedWorkspaceId = $workspaces->firstWhere('id', $currentId)?->id
            ?? $workspaces->first()?->id
            ?? '';

        return Inertia::render('mcp/Authorize', [
            'client' => [
                'id' => data_get($parameters, 'client.id'),
                'name' => data_get($parameters, 'client.name'),
            ],
            'user' => [
                'email' => data_get($parameters, 'user.email'),
            ],
            'workspaces' => $workspaces
                ->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ])
                ->values()
                ->all(),
            'selectedWorkspaceId' => $selectedWorkspaceId,
            'scopes' => collect(data_get($parameters, 'scopes', []))
                ->map(fn (Scope $scope): array => [
                    'id' => $scope->id,
                    'description' => $scope->description,
                ])
                ->values()
                ->all(),
            'authToken' => data_get($parameters, 'authToken'),
            'state' => data_get($parameters, 'request.state', ''),
        ]);
    }
}
