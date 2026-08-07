<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Http\Middleware\App\HandleInertiaRequests;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    config([
        'trypost.platforms.linkedin-page.organization_id' => '123456',
        'services.linkedin-page.client_id' => 'page-client-id',
        'services.linkedin-page.client_secret' => 'page-client-secret',
        'services.linkedin-page.redirect' => 'https://trypost.example/accounts/linkedin/callback',
        'cache.stores.redis' => ['driver' => 'array'],
    ]);
    Cache::forgetDriver('redis');
    Socialite::forgetDrivers();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

/** Build the token-only OAuth result returned by the Page provider. */
function linkedInSocialiteUser(): SocialiteUser
{
    return (new SocialiteUser)
        ->setToken('test-access-token')
        ->setRefreshToken('test-refresh-token')
        ->setExpiresIn(5184000)
        ->setApprovedScopes([
            'rw_organization_admin',
            'w_organization_social',
            'r_organization_social',
        ]);
}

/** @return array<string, mixed> */
function linkedInPagePending(Workspace $workspace, array $overrides = []): array
{
    return array_replace_recursive([
        'workspace_id' => $workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['rw_organization_admin', 'w_organization_social'],
        'organizations' => [
            [
                'id' => 123456,
                'name' => 'Test Company',
                'vanity_name' => 'testcompany',
                'logo' => null,
            ],
        ],
    ], $overrides);
}

function linkedInTestState(string $character): string
{
    return str_repeat($character, 64);
}

/** @return array<string, string> */
function linkedInPageInertiaHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
    ];
}

function seedLinkedInOAuthAttempt(
    string $state,
    Workspace $workspace,
    ?int $expiresAt = null,
): void {
    $attempts = session('linkedin_page_oauth_attempts', []);
    $attempts[hash('sha256', $state)] = [
        'workspace_id' => $workspace->id,
        'issued_at' => now()->timestamp,
        'expires_at' => $expiresAt ?? now()->addMinutes(10)->timestamp,
        'payload' => [],
    ];
    session(['linkedin_page_oauth_attempts' => $attempts]);
}

function seedLinkedInSelectionAttempt(
    Workspace $workspace,
    array $overrides = [],
    ?string $state = null,
    ?int $expiresAt = null,
): string {
    $state ??= linkedInTestState('S');
    $attempts = session('linkedin_page_selection_attempts', []);
    $attempts[hash('sha256', $state)] = [
        'workspace_id' => $workspace->id,
        'issued_at' => now()->timestamp,
        'expires_at' => $expiresAt ?? now()->addMinutes(10)->timestamp,
        'payload' => linkedInPagePending($workspace, $overrides),
    ];
    session(['linkedin_page_selection_attempts' => $attempts]);

    return $state;
}

test('personal linkedin connect route does not exist', function () {
    Socialite::shouldReceive('driver')->never();

    $this->actingAs($this->user)
        ->get('/connect/linkedin')
        ->assertNotFound();
});

test('linkedin page connect emits a controller-issued state and stores only its hash', function () {
    $this->freezeTime();

    $scopes = [
        'rw_organization_admin',
        'w_organization_social',
        'r_organization_social',
    ];
    config(['trypost.platforms.linkedin-page.scopes' => $scopes]);

    $response = $this->actingAs($this->user)
        ->withHeaders(linkedInPageInertiaHeaders())
        ->get(route('app.social.linkedin-page.connect'));

    $response->assertStatus(409);
    $authorizeUrl = $response->headers->get('X-Inertia-Location');
    parse_str((string) parse_url((string) $authorizeUrl, PHP_URL_QUERY), $query);
    $state = $query['state'] ?? null;

    expect($authorizeUrl)->toStartWith('https://www.linkedin.com/oauth/v2/authorization')
        ->and($query['scope'] ?? null)->toBe(implode(' ', $scopes))
        ->and($state)->toBeString()->toMatch('/\A[A-Za-z0-9]{64}\z/')
        ->and(session('state'))->toBeNull()
        ->and(session('social_connect_workspace'))->toBeNull();

    $attempts = session('linkedin_page_oauth_attempts');
    $stateHash = hash('sha256', $state);

    expect($attempts)->toHaveCount(1)
        ->and($attempts[$stateHash]['workspace_id'])->toBe($this->workspace->id)
        ->and($attempts[$stateHash]['expires_at'])->toBe(now()->addMinutes(10)->timestamp)
        ->and(json_encode($attempts, JSON_THROW_ON_ERROR))->not->toContain($state);
});

test('linkedin page connect retains independent states for concurrent workspace attempts', function () {
    $firstResponse = $this->actingAs($this->user)
        ->withHeaders(linkedInPageInertiaHeaders())
        ->get(route('app.social.linkedin-page.connect'));
    parse_str((string) parse_url((string) $firstResponse->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $firstQuery);
    $firstState = $firstQuery['state'];

    $secondWorkspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $secondWorkspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $secondWorkspace->id]);

    $secondResponse = $this->actingAs($this->user->fresh())
        ->withHeaders(linkedInPageInertiaHeaders())
        ->get(route('app.social.linkedin-page.connect'));
    parse_str((string) parse_url((string) $secondResponse->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $secondQuery);
    $secondState = $secondQuery['state'];

    $attempts = session('linkedin_page_oauth_attempts');

    expect($secondState)->not->toBe($firstState)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[hash('sha256', $firstState)]['workspace_id'])->toBe($this->workspace->id)
        ->and($attempts[hash('sha256', $secondState)]['workspace_id'])->toBe($secondWorkspace->id);
});

test('linkedin page connect prunes expired attempts and retains at most five live attempts', function () {
    $expiredState = linkedInTestState('E');
    seedLinkedInOAuthAttempt($expiredState, $this->workspace, now()->subSecond()->timestamp);

    $issuedStates = [];

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $response = $this->actingAs($this->user)
            ->withHeaders(linkedInPageInertiaHeaders())
            ->get(route('app.social.linkedin-page.connect'));
        parse_str((string) parse_url((string) $response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $query);
        $issuedStates[] = $query['state'];
    }

    $attempts = session('linkedin_page_oauth_attempts');

    expect($attempts)->toHaveCount(5)
        ->not->toHaveKey(hash('sha256', $expiredState))
        ->not->toHaveKey(hash('sha256', $issuedStates[0]));

    foreach (array_slice($issuedStates, 1) as $state) {
        expect($attempts)->toHaveKey(hash('sha256', $state));
    }
});

test('linkedin page connect is forbidden when the page capability is disabled', function () {
    config(['trypost.platforms.linkedin-page.enabled' => false]);

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin-page.connect'))
        ->assertForbidden();
});

test('linkedin page connect fails closed when the configured organization is missing', function () {
    config(['trypost.platforms.linkedin-page.organization_id' => null]);
    Socialite::shouldReceive('driver')->never();

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin-page.connect'))
        ->assertForbidden();
});

test('linkedin page connect redirects to workspace creation when there is no current workspace', function () {
    $this->user->update(['current_workspace_id' => null]);

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin-page.connect'))
        ->assertRedirect(route('app.workspaces.create'));
});

test('linkedin page selector has no reusable get route', function () {
    $this->actingAs($this->user)
        ->get('/accounts/linkedin/select')
        ->assertMethodNotAllowed();
});

test('linkedin page state-consuming routes serialize requests from the same session in redis', function () {
    expect(config('session.block_store'))->toBe('redis');

    foreach (['app.social.linkedin.callback', 'app.social.linkedin.select'] as $routeName) {
        $route = app('router')->getRoutes()->getByName($routeName);

        expect($route)->not->toBeNull()
            ->and($route->locksFor())->toBe(60)
            ->and($route->waitsFor())->toBe(5);
    }
});

test('linkedin page callback consumes its state before external calls and renders a non-url selection attempt', function () {
    $state = linkedInTestState('A');
    $stateHash = hash('sha256', $state);
    seedLinkedInOAuthAttempt($state, $this->workspace);

    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andReturnUsing(function () use ($stateHash) {
        expect(session('linkedin_page_oauth_attempts', []))->not->toHaveKey($stateHash);

        return linkedInSocialiteUser();
    });
    Socialite::shouldReceive('driver')->with('linkedin-page')->once()->andReturn($driver);

    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/v2/organizationAcls*' => Http::response([
            'elements' => [
                ['organization~' => ['id' => 123456, 'localizedName' => 'Test Company', 'vanityName' => 'testcompany']],
                ['organization~' => ['id' => 999999, 'localizedName' => 'Unrelated Company', 'vanityName' => 'unrelated']],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('accounts/LinkedInSelect')
        ->missing('person')
        ->has('organizations', 1)
        ->has('attempt')
    );

    $page = $response->original->getData()['page'];
    $selectionAttempt = $page['props']['attempt'];
    $selectionHash = hash('sha256', $selectionAttempt);
    $selectionAttempts = session('linkedin_page_selection_attempts');

    expect($response->headers->get('Location'))->toBeNull()
        ->and($selectionAttempt)->toMatch('/\A[A-Za-z0-9]{64}\z/')
        ->and($selectionAttempts[$selectionHash]['workspace_id'])->toBe($this->workspace->id)
        ->and(json_encode($selectionAttempts, JSON_THROW_ON_ERROR))->not->toContain($selectionAttempt)
        ->and(session('linkedin_page_oauth_attempts', []))->not->toHaveKey($stateHash)
        ->and(Cache::store('redis')->has("trypost:linkedin-page-oauth:claim:oauth:{$stateHash}"))->toBeTrue();

});

test('linkedin page organization lookup is bounded below the session lock lease', function () {
    $state = linkedInTestState('5');
    seedLinkedInOAuthAttempt($state, $this->workspace);

    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andReturn(linkedInSocialiteUser());
    Socialite::shouldReceive('driver')->with('linkedin-page')->once()->andReturn($driver);

    $requestOptions = null;
    Http::globalOptions([
        'handler' => function (RequestInterface $request, array $options) use (&$requestOptions) {
            $requestOptions = $options;

            return Create::promiseFor(new GuzzleResponse(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['elements' => []], JSON_THROW_ON_ERROR),
            ));
        },
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]))
        ->assertInertia(fn (Assert $page) => $page->component('accounts/LinkedInSelect'));

    expect($requestOptions)->toBeArray()
        ->and($requestOptions['connect_timeout'] ?? null)->toBe(5)
        ->and($requestOptions['timeout'] ?? null)->toBe(10);
});

test('linkedin page callbacks complete out of order and preserve each workspace binding through selection', function () {
    $secondWorkspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $secondWorkspace->members()->attach($this->user->id, ['role' => Role::Member->value]);

    $firstState = linkedInTestState('B');
    $secondState = linkedInTestState('C');
    seedLinkedInOAuthAttempt($firstState, $this->workspace);
    seedLinkedInOAuthAttempt($secondState, $secondWorkspace);

    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->twice()->andReturnSelf();
    $driver->shouldReceive('user')->twice()->andReturn(
        linkedInSocialiteUser(),
        linkedInSocialiteUser(),
    );
    Socialite::shouldReceive('driver')->with('linkedin-page')->twice()->andReturn($driver);

    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/v2/organizationAcls*' => Http::response([
            'elements' => [
                ['organization~' => ['id' => 123456, 'localizedName' => 'Test Company', 'vanityName' => 'testcompany']],
            ],
        ], 200),
    ]);

    $secondResponse = $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $secondState]));
    $firstResponse = $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $firstState]));

    $secondAttempt = $secondResponse->original->getData()['page']['props']['attempt'];
    $firstAttempt = $firstResponse->original->getData()['page']['props']['attempt'];
    $selectionAttempts = session('linkedin_page_selection_attempts');

    expect($selectionAttempts)->toHaveCount(2)
        ->and($selectionAttempts[hash('sha256', $secondAttempt)]['workspace_id'])->toBe($secondWorkspace->id)
        ->and($selectionAttempts[hash('sha256', $firstAttempt)]['workspace_id'])->toBe($this->workspace->id);

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $firstAttempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', true));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $secondAttempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', true));

    $firstAccount = SocialAccount::where('workspace_id', $this->workspace->id)->firstOrFail();
    $secondAccount = SocialAccount::where('workspace_id', $secondWorkspace->id)->firstOrFail();

    expect($firstAccount->meta)->toBe(['organization_id' => '123456'])
        ->and($secondAccount->meta)->toBe(['organization_id' => '123456']);
});

test('linkedin page callback state is single use and replay never reaches linkedin', function () {
    $state = linkedInTestState('D');
    seedLinkedInOAuthAttempt($state, $this->workspace);

    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andReturn(linkedInSocialiteUser());
    Socialite::shouldReceive('driver')->with('linkedin-page')->once()->andReturn($driver);

    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/v2/organizationAcls*' => Http::response(['elements' => []], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]))
        ->assertInertia(fn (Assert $page) => $page->component('accounts/LinkedInSelect'));

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', 'Session expired. Please try again.')
        );

    Http::assertSentCount(1);
});

test('linkedin page callback rejects a stale session snapshot after another request claimed the state', function () {
    $state = linkedInTestState('6');
    $stateHash = hash('sha256', $state);
    seedLinkedInOAuthAttempt($state, $this->workspace);
    Cache::store('redis')->put(
        "trypost:linkedin-page-oauth:claim:oauth:{$stateHash}",
        true,
        600,
    );
    Socialite::shouldReceive('driver')->never();

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
        );

    expect(session('linkedin_page_oauth_attempts', []))->not->toHaveKey($stateHash);
});

test('linkedin page callback rejects and removes expired state before linkedin calls', function () {
    $state = linkedInTestState('F');
    seedLinkedInOAuthAttempt($state, $this->workspace, now()->subSecond()->timestamp);
    Socialite::shouldReceive('driver')->never();

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
        );

    expect(session('linkedin_page_oauth_attempts', []))
        ->not->toHaveKey(hash('sha256', $state));
});

test('linkedin page callback fails closed and removes state when redis claims are unavailable', function () {
    $state = linkedInTestState('G');
    $stateHash = hash('sha256', $state);
    seedLinkedInOAuthAttempt($state, $this->workspace);
    $sessionLockStore = Cache::store('redis');

    Cache::shouldReceive('driver')
        ->once()
        ->with('redis')
        ->andReturn($sessionLockStore);

    Cache::shouldReceive('store')
        ->once()
        ->with('redis')
        ->andReturnUsing(function () use ($stateHash): never {
            expect(session('linkedin_page_oauth_attempts', []))->not->toHaveKey($stateHash);

            throw new RuntimeException('Redis unavailable');
        });
    Socialite::shouldReceive('driver')->never();

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
        );
});

test('linkedin page callback keeps at most five live selection attempts', function () {
    foreach (['H', 'I', 'J', 'K', 'L'] as $character) {
        seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState($character));
    }

    $oauthState = linkedInTestState('M');
    seedLinkedInOAuthAttempt($oauthState, $this->workspace);

    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andReturn(linkedInSocialiteUser());
    Socialite::shouldReceive('driver')->with('linkedin-page')->once()->andReturn($driver);
    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/v2/organizationAcls*' => Http::response(['elements' => []], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $oauthState]));
    $newAttempt = $response->original->getData()['page']['props']['attempt'];
    $attempts = session('linkedin_page_selection_attempts');

    expect($attempts)->toHaveCount(5)
        ->not->toHaveKey(hash('sha256', linkedInTestState('H')))
        ->toHaveKey(hash('sha256', $newAttempt));
});

test('linkedin page selection attempt is single use', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('N'));

    $payload = [
        'attempt' => $attempt,
        'type' => 'organization',
        'organization_id' => 123456,
    ];

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), $payload)
        ->assertInertia(fn (Assert $page) => $page->where('success', true));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), $payload)
        ->assertInertia(fn (Assert $page) => $page
            ->where('success', false)
            ->where('message', 'Session expired. Please try again.')
        );

    expect(SocialAccount::where('workspace_id', $this->workspace->id)->count())->toBe(1)
        ->and(Cache::store('redis')->has(
            'trypost:linkedin-page-oauth:claim:selection:'.hash('sha256', $attempt),
        ))->toBeTrue();
});

test('linkedin page selection rejects a stale session snapshot after another request claimed the attempt', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('7'));
    $attemptHash = hash('sha256', $attempt);
    Cache::store('redis')->put(
        "trypost:linkedin-page-oauth:claim:selection:{$attemptHash}",
        true,
        600,
    );

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
        );

    expect(session('linkedin_page_selection_attempts', []))->not->toHaveKey($attemptHash);
    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 123456]);
});

test('linkedin page selection works only in the session that issued each attempt', function () {
    $validAttempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('P'));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $validAttempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', true));

    $otherWorkspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $otherAttempt = seedLinkedInSelectionAttempt($otherWorkspace, state: linkedInTestState('T'));
    $this->flushSession();

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $otherAttempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page
            ->where('success', false)
            ->where('message', 'Session expired. Please try again.')
        );

    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $otherWorkspace->id,
        'platform_user_id' => 123456,
    ]);
});

test('linkedin page selection rejects and removes an expired attempt', function () {
    $attempt = seedLinkedInSelectionAttempt(
        $this->workspace,
        state: linkedInTestState('Q'),
        expiresAt: now()->subSecond()->timestamp,
    );

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', false));

    expect(session('linkedin_page_selection_attempts', []))
        ->not->toHaveKey(hash('sha256', $attempt));
    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 123456]);
});

test('linkedin page selection fails closed and consumes its attempt when redis is unavailable', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('R'));
    $attemptHash = hash('sha256', $attempt);
    $sessionLockStore = Cache::store('redis');

    Cache::shouldReceive('driver')
        ->once()
        ->with('redis')
        ->andReturn($sessionLockStore);

    Cache::shouldReceive('store')
        ->once()
        ->with('redis')
        ->andReturnUsing(function () use ($attemptHash): never {
            expect(session('linkedin_page_selection_attempts', []))->not->toHaveKey($attemptHash);

            throw new RuntimeException('Redis unavailable');
        });

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', false));

    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 123456]);
});

test('linkedin page callback stores only token and administered organization data', function () {
    $state = linkedInTestState('U');
    seedLinkedInOAuthAttempt($state, $this->workspace);

    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andReturn(linkedInSocialiteUser());
    Socialite::shouldReceive('driver')->with('linkedin-page')->once()->andReturn($driver);

    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/v2/organizationAcls*' => Http::response([
            'elements' => [
                ['organization~' => ['id' => 123456, 'localizedName' => 'Test Company', 'vanityName' => 'testcompany']],
                ['organization~' => ['id' => 999999, 'localizedName' => 'Unrelated Company', 'vanityName' => 'unrelated']],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]));
    $response->assertInertia(fn (Assert $page) => $page
        ->component('accounts/LinkedInSelect')
        ->has('organizations', 1)
    );

    $selectionAttempt = $response->original->getData()['page']['props']['attempt'];
    $pending = session('linkedin_page_selection_attempts.'.hash('sha256', $selectionAttempt).'.payload');

    expect($pending)->not->toHaveKey('person')
        ->and($pending['organizations'])->toHaveCount(1)
        ->and($pending)->not->toHaveKey('identity');
});

test('linkedin page callback still opens the selector when no pages are administered', function () {
    $state = linkedInTestState('V');
    seedLinkedInOAuthAttempt($state, $this->workspace);

    $driver = Mockery::mock();
    $driver->shouldReceive('stateless')->once()->andReturnSelf();
    $driver->shouldReceive('user')->once()->andReturn(linkedInSocialiteUser());
    Socialite::shouldReceive('driver')->with('linkedin-page')->once()->andReturn($driver);

    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/v2/organizationAcls*' => Http::response(['elements' => []], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/LinkedInSelect')
            ->has('organizations', 0)
        );
});

test('linkedin page callback fails with an expired session', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.callback'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', false)
        ->where('message', 'Session expired. Please try again.')
    );
});

test('linkedin page callback handles oauth errors gracefully', function () {
    $state = linkedInTestState('W');
    seedLinkedInOAuthAttempt($state, $this->workspace);

    $mock = Mockery::mock();
    $mock->shouldReceive('stateless')->once()->andReturnSelf();
    $mock->shouldReceive('user')->once()->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')->with('linkedin-page')->once()->andReturn($mock);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback', ['state' => $state]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('success', false)
        ->where('message', 'Error connecting account. Please try again.')
    );
});

test('personal linkedin cannot be selected from a page oauth session', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('X'));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'person',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', false));

    $this->assertDatabaseMissing('social_accounts', [
        'platform' => Platform::LinkedIn->value,
        'platform_user_id' => 'person-123',
    ]);
});

test('selecting an organization is rejected when linkedin pages are disabled', function () {
    config(['trypost.platforms.linkedin-page.enabled' => false]);
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('Y'));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', false));

    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 123456]);
});

test('selecting an organization persists only the linkedin page identity', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('Z'));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', true));

    $account = SocialAccount::where('platform', Platform::LinkedInPage->value)
        ->where('platform_user_id', 123456)
        ->first();

    expect($account)->not->toBeNull();
    expect($account->display_name)->toBe('Test Company');
    expect($account->username)->toBe('testcompany');
    expect($account->status)->toBe(Status::Connected);
    expect($account->meta)->toBe(['organization_id' => '123456']);
    expect(session('linkedin_page_selection_attempts', []))->not->toHaveKey(hash('sha256', $attempt));
});

test('selecting an organization the member does not administer is rejected', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('0'));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'organization',
            'organization_id' => 999,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', false));

    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 999]);
});

test('selecting a different administered organization is rejected by the clarity page allowlist', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, [
        'organizations' => [[
            'id' => 999999,
            'name' => 'Unrelated Company',
            'vanity_name' => 'unrelated',
            'logo' => null,
        ]],
    ], linkedInTestState('1'));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'organization',
            'organization_id' => 999999,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', false));

    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 999999]);
});

test('selecting an organization splits comma separated approved scopes before saving', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, [
        'approved_scopes' => ['w_organization_social,r_organization_social,rw_organization_admin'],
        'organizations' => [[
            'id' => 123456,
            'name' => 'Scope Company',
            'vanity_name' => 'scopecompany',
            'logo' => null,
        ]],
    ], linkedInTestState('2'));

    $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'attempt' => $attempt,
        'type' => 'organization',
        'organization_id' => 123456,
    ]);

    $account = SocialAccount::where('platform_user_id', 123456)->firstOrFail();
    expect($account->scopes)->toBe([
        'w_organization_social',
        'r_organization_social',
        'rw_organization_admin',
    ]);
});

test('selecting an invalid identity type returns the popup callback', function () {
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('3'));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'bogus',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
        );
});

test('selecting a page after the session expires returns a useful error', function () {
    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page
            ->where('success', false)
            ->where('message', 'Session expired. Please try again.')
        );
});

test('a stored legacy personal row does not block connecting the clarity page', function () {
    config(['trypost.self_hosted' => false]);
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'legacy-person',
    ]);
    $attempt = seedLinkedInSelectionAttempt($this->workspace, state: linkedInTestState('4'));

    $this->actingAs($this->user)
        ->post(route('app.social.linkedin.select'), [
            'attempt' => $attempt,
            'type' => 'organization',
            'organization_id' => 123456,
        ])
        ->assertInertia(fn (Assert $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'platform' => Platform::LinkedInPage->value,
        'platform_user_id' => 123456,
    ]);
});
