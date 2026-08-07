<?php

declare(strict_types=1);

use App\Socialite\LinkedInPageProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\LinkedInProvider;

beforeEach(function () {
    config([
        'services.linkedin-page.client_id' => 'page-client-id',
        'services.linkedin-page.client_secret' => 'page-client-secret',
        'services.linkedin-page.redirect' => 'https://trypost.example/accounts/linkedin/callback',
    ]);

    Socialite::forgetDrivers();
});

test('linkedin page oauth transport has bounded connection and response deadlines', function () {
    $provider = Socialite::driver('linkedin-page');
    $getHttpClient = new ReflectionMethod($provider, 'getHttpClient');
    $httpClient = $getHttpClient->invoke($provider);

    expect($httpClient)->toBeInstanceOf(Client::class)
        ->and($httpClient->getConfig('connect_timeout'))->toBe(5)
        ->and($httpClient->getConfig('timeout'))->toBe(10);
});

test('linkedin page oauth exchanges the code without requesting a personal profile', function () {
    $responses = new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => 'page-access-token',
            'refresh_token' => 'page-refresh-token',
            'expires_in' => 5184000,
            'scope' => 'rw_organization_admin,w_organization_social',
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
            'id' => 'personal-member-id-that-must-not-be-read',
            'firstName' => [
                'localized' => ['en_US' => 'Clarity'],
                'preferredLocale' => ['language' => 'en', 'country' => 'US'],
            ],
            'lastName' => [
                'localized' => ['en_US' => 'Admin'],
                'preferredLocale' => ['language' => 'en', 'country' => 'US'],
            ],
            'profilePicture' => ['displayImage~' => ['elements' => []]],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $history = [];
    $stack = HandlerStack::create($responses);
    $stack->push(Middleware::history($history));
    $request = Request::create(
        'https://trypost.example/accounts/linkedin/callback?code=oauth-code',
        'GET',
    );
    $provider = new LinkedInPageProvider(
        $request,
        'page-client-id',
        'page-client-secret',
        'https://trypost.example/accounts/linkedin/callback',
    );

    $provider->setHttpClient(new Client([
        'handler' => $stack,
    ]));

    $user = $provider->stateless()->user();

    expect($provider)
        ->toBeInstanceOf(LinkedInProvider::class)
        ->and($provider->getScopes())->toBe([])
        ->and($user->token)->toBe('page-access-token')
        ->and($user->refreshToken)->toBe('page-refresh-token')
        ->and($user->expiresIn)->toBe(5184000)
        ->and($user->approvedScopes)->toBe([
            'rw_organization_admin,w_organization_social',
        ])
        ->and($user->getId())->toBeNull()
        ->and($user->getName())->toBeNull()
        ->and($user->getEmail())->toBeNull()
        ->and($user->getAvatar())->toBeNull()
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toBe('https://www.linkedin.com/oauth/v2/accessToken')
        ->and($responses->count())->toBe(1);
});
