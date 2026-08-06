<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Http\Middleware\App\EnsureCanAuthorizeMcp;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

test('dynamic oauth client registration is rate limited', function () {
    $payload = [
        'client_name' => 'MCP Client',
        'redirect_uris' => ['https://client.example/callback'],
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ];

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->postJson('/oauth/register', $payload)->assertSuccessful();
    }

    $this->postJson('/oauth/register', $payload)->assertTooManyRequests();
});

test('dynamic oauth registration rejects custom callback schemes', function (string $redirectUri) {
    $this->postJson('/oauth/register', [
        'client_name' => 'Native MCP Client',
        'redirect_uris' => [$redirectUri],
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ])->assertBadRequest();
})->with([
    'cursor' => 'cursor://oauth/callback',
    'vscode' => 'vscode://oauth/callback',
]);

test('mcp oauth consent view is available for workspace viewers', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);

    $viewer = User::factory()->create(['account_id' => $account->id]);
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $workspace->id]);

    $html = view('mcp.authorize', [
        'client' => (object) [
            'id' => (string) Str::uuid(),
            'name' => 'Viewer Agent',
        ],
        'user' => $viewer,
        'workspace' => $workspace,
        'scopes' => collect([(object) ['id' => 'mcp:use', 'description' => 'Use MCP server']]),
        'authToken' => 'test-auth-token',
        'request' => request(),
    ])->render();

    expect($html)
        ->toContain(__('mcp.authorize.heading', ['client' => 'Viewer Agent']))
        ->toContain($viewer->email)
        ->toContain($workspace->name)
        ->toContain(__('mcp.authorize.workspace_scope'))
        ->toContain(__('mcp.authorize.scope_mcp_use'))
        ->and(view()->exists('mcp.authorize-denied'))->toBeFalse()
        ->and(class_exists(EnsureCanAuthorizeMcp::class))->toBeFalse();
});

test('mcp oauth consent view uses the active locale', function () {
    app()->setLocale('pt-BR');

    $html = view('mcp.authorize', [
        'client' => (object) [
            'id' => (string) Str::uuid(),
            'name' => 'Claude',
        ],
        'user' => (object) ['email' => 'user@example.com'],
        'workspace' => (object) ['name' => 'Acme'],
        'scopes' => collect([(object) ['id' => 'mcp:use', 'description' => 'Use MCP server']]),
        'authToken' => 'test-auth-token',
        'request' => request(),
    ])->render();

    expect($html)
        ->toContain('Autorizar Claude')
        ->toContain('Conectado como:')
        ->toContain('Esta conexão terá acesso somente a este workspace.')
        ->toContain('Autorizar')
        ->toContain('Cancelar');
});

test('passport approve route has no mcp create-post role gate', function () {
    $route = app('router')->getRoutes()->getByName('passport.authorizations.approve');

    expect($route)->not->toBeNull();

    $middleware = collect($route->gatherMiddleware())
        ->map(fn (mixed $middleware): string => is_string($middleware) ? $middleware : $middleware::class)
        ->implode(',');

    expect($middleware)->not->toContain('EnsureCanAuthorizeMcp');
});
