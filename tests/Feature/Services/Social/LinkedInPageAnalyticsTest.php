<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkedInPageAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedInPage,
        'content_type' => ContentType::LinkedInPagePost,
        'platform_post_id' => 'urn:li:share:1234567890',
    ]);
});

test('linkedin page analytics refresh hits the configured oauth host', function () {
    $oauthApi = config('trypost.platforms.linkedin-page.oauth_api');
    $api = config('trypost.platforms.linkedin-page.api');

    Http::fake([
        "{$oauthApi}/oauth/v2/accessToken" => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 5184000,
        ], 200),
        "{$api}/rest/socialActions/*" => Http::response([
            'likesSummary' => ['totalLikes' => 0],
            'commentsSummary' => ['aggregatedTotalComments' => 0],
        ], 200),
    ]);

    (new LinkedInPageAnalytics)->fetchPostMetrics($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), "{$oauthApi}/oauth/v2/accessToken"));
});

test('linkedin page post analytics preserves explicit zero metrics', function () {
    $this->socialAccount->update(['token_expires_at' => now()->addHour()]);
    $api = config('trypost.platforms.linkedin-page.api');

    Http::fake([
        "{$api}/rest/socialActions/*" => Http::response([
            'likesSummary' => ['totalLikes' => 0],
            'commentsSummary' => ['aggregatedTotalComments' => 0],
        ], 200),
    ]);

    expect((new LinkedInPageAnalytics)->fetchPostMetrics($this->postPlatform))->toBe([
        ['label' => __('analytics.metrics.likes'), 'value' => 0],
        ['label' => __('analytics.metrics.comments'), 'value' => 0],
    ]);
});

test('linkedin page post analytics marks absent metrics unavailable instead of fabricating zeros', function () {
    $this->socialAccount->update(['token_expires_at' => now()->addHour()]);
    $api = config('trypost.platforms.linkedin-page.api');

    Http::fake([
        "{$api}/rest/socialActions/*" => Http::response(['status' => 'available'], 200),
    ]);

    expect((new LinkedInPageAnalytics)->fetchPostMetrics($this->postPlatform))->toBe([
        'unsupported' => true,
        'reason' => 'metrics_unavailable',
    ]);
});

test('linkedin page account analytics preserves only explicitly observed zero metrics', function () {
    $this->socialAccount->update(['token_expires_at' => now()->addHour()]);
    $api = config('trypost.platforms.linkedin-page.api');

    Http::fake([
        "{$api}/rest/organizationPageStatistics*" => Http::response([
            'elements' => [[
                'totalPageStatistics' => [
                    'views' => ['allPageViews' => ['pageViews' => 0]],
                ],
            ]],
        ], 200),
        "{$api}/rest/organizationalEntityFollowerStatistics*" => Http::response([
            'elements' => [[
                'followerGains' => [
                    'organicFollowerGain' => 0,
                    'paidFollowerGain' => 0,
                ],
            ]],
        ], 200),
        "{$api}/rest/organizationalEntityShareStatistics*" => Http::response([
            'elements' => [[
                'totalShareStatistics' => [
                    'shareCount' => 0,
                    'clickCount' => 0,
                    'likeCount' => 0,
                    'commentCount' => 0,
                    'impressionCount' => 0,
                ],
            ]],
        ], 200),
    ]);

    expect((new LinkedInPageAnalytics)->getMetrics($this->socialAccount))->toBe([
        ['label' => __('analytics.metrics.page_views'), 'value' => 0],
        ['label' => __('analytics.metrics.organic_followers'), 'value' => 0],
        ['label' => __('analytics.metrics.paid_followers'), 'value' => 0],
        ['label' => __('analytics.metrics.impressions'), 'value' => 0],
        ['label' => __('analytics.metrics.clicks'), 'value' => 0],
        ['label' => __('analytics.metrics.likes'), 'value' => 0],
        ['label' => __('analytics.metrics.comments'), 'value' => 0],
        ['label' => __('analytics.metrics.shares'), 'value' => 0],
    ]);
});

test('linkedin page account analytics omits absent and invalid metric observations', function () {
    $this->socialAccount->update(['token_expires_at' => now()->addHour()]);
    $api = config('trypost.platforms.linkedin-page.api');

    Http::fake([
        "{$api}/rest/organizationPageStatistics*" => Http::response([
            'elements' => [['totalPageStatistics' => []]],
        ], 200),
        "{$api}/rest/organizationalEntityFollowerStatistics*" => Http::response([
            'elements' => [['followerGains' => ['organicFollowerGain' => 'unknown']]],
        ], 200),
        "{$api}/rest/organizationalEntityShareStatistics*" => Http::response([
            'elements' => 'invalid',
        ], 200),
    ]);

    expect((new LinkedInPageAnalytics)->getMetrics($this->socialAccount))->toBe([]);
});

test('linkedin page analytics rejects a page outside the configured organization before network access', function (array $identity) {
    $this->socialAccount->update([
        ...$identity,
        'token_expires_at' => now()->addHour(),
    ]);
    Http::fake();

    $analytics = new LinkedInPageAnalytics;

    expect($analytics->getMetrics($this->socialAccount))->toBe([])
        ->and($analytics->fetchPostMetrics($this->postPlatform))->toBe([
            'unsupported' => true,
            'reason' => 'account_unavailable',
        ]);

    Http::assertNothingSent();
})->with([
    'different organization' => [[
        'platform_user_id' => '999999',
        'meta' => ['organization_id' => '999999'],
    ]],
    'mismatched organization metadata' => [[
        'platform_user_id' => '123456',
        'meta' => ['organization_id' => '999999'],
    ]],
]);

test('analytics account discovery omits a linkedin page outside the configured organization', function () {
    config(['trypost.self_hosted' => true]);
    $this->socialAccount->update([
        'platform_user_id' => '999999',
        'meta' => ['organization_id' => '999999'],
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->get(route('app.analytics'));

    $response->assertOk();
    $accounts = $response->original->getData()['page']['props']['accounts'];

    expect(collect($accounts)->pluck('id'))->not->toContain($this->socialAccount->id);
});
