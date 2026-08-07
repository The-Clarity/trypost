<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LinkedInPageAnalytics
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        // Versioned API (`/rest/`) is the only one that honours the
        // LinkedIn-Version header and the current analytics schemas.
        // The legacy `/v2/` path rejects newer parameter formats with
        // "Parameter 'timeIntervals' is invalid".
        $this->baseUrl = config('trypost.platforms.linkedin-page.api').'/rest';
    }

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        if ($account->platform !== Platform::LinkedInPage || ! $account->isAvailableForUse()) {
            return [];
        }

        $since ??= now()->subDays(7);
        $until ??= now();

        $cacheKey = "analytics:linkedin-page:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}";
        $cacheTtl = app()->isProduction() ? 3600 : 1;

        return Cache::remember($cacheKey, $cacheTtl, function () use ($account, $since, $until) {
            return $this->fetchMetricsFromApi($account, $since, $until);
        });
    }

    public function fetchPostMetrics(PostPlatform $postPlatform): array
    {
        $account = $postPlatform->socialAccount;

        if (! $account
            || $account->platform !== Platform::LinkedInPage
            || ! $account->isAvailableForUse()) {
            return ['unsupported' => true, 'reason' => 'account_unavailable'];
        }

        if (! $postPlatform->platform_post_id) {
            return ['unsupported' => true, 'reason' => 'missing_post_id'];
        }

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        // platform_post_id is the share URN (e.g., "urn:li:share:12345").
        $shareUrn = urlencode($postPlatform->platform_post_id);

        $response = $this->socialHttp()
            ->withToken($account->access_token)
            ->get("{$this->baseUrl}/socialActions/{$shareUrn}");

        if ($response->failed()) {
            Log::warning('LinkedIn Page post metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        $data = $response->json();
        $metrics = [];

        foreach ([
            'likesSummary.totalLikes' => __('analytics.metrics.likes'),
            'commentsSummary.aggregatedTotalComments' => __('analytics.metrics.comments'),
        ] as $path => $label) {
            $value = is_array($data) ? $this->observedMetricTotal([$data], $path) : null;

            if ($value !== null) {
                $metrics[] = ['label' => $label, 'value' => $value];
            }
        }

        return $metrics !== []
            ? $metrics
            : ['unsupported' => true, 'reason' => 'metrics_unavailable'];
    }

    private function fetchMetricsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $orgUrn = "urn:li:organization:{$account->platform_user_id}";
        // LinkedIn requires both endpoints of the timeRange to be at midnight
        // UTC (00:00:00.000). endOfDay() produces 23:59:59.999 which the API
        // silently rejects with "Parameter 'timeIntervals' is invalid".
        $startMs = $since->copy()->utc()->startOfDay()->getTimestampMs();
        $endMs = $until->copy()->utc()->startOfDay()->addDay()->getTimestampMs();
        $timeInterval = "(timeRange:(start:{$startMs},end:{$endMs}),timeGranularityType:DAY)";

        $metrics = [];

        // Page statistics (page views)
        $pageStats = $this->fetchPageStatistics($orgUrn, $timeInterval);
        $metrics = array_merge($metrics, $pageStats);

        // Follower statistics
        $followerStats = $this->fetchFollowerStatistics($orgUrn, $timeInterval);
        $metrics = array_merge($metrics, $followerStats);

        // Share statistics (engagement)
        $shareStats = $this->fetchShareStatistics($orgUrn, $timeInterval);
        $metrics = array_merge($metrics, $shareStats);

        return $metrics;
    }

    private function fetchPageStatistics(string $orgUrn, string $timeInterval): array
    {
        $org = rawurlencode($orgUrn);
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/organizationPageStatistics?q=organization&organization={$org}&timeIntervals={$timeInterval}");

        if ($response->failed()) {
            Log::warning('LinkedIn page statistics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $totalPageViews = $this->observedMetricTotal(
            data_get($response->json(), 'elements'),
            'totalPageStatistics.views.allPageViews.pageViews',
        );

        return $totalPageViews !== null
            ? [['label' => __('analytics.metrics.page_views'), 'value' => $totalPageViews]]
            : [];
    }

    private function fetchFollowerStatistics(string $orgUrn, string $timeInterval): array
    {
        $org = rawurlencode($orgUrn);
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity={$org}&timeIntervals={$timeInterval}");

        if ($response->failed()) {
            Log::warning('LinkedIn follower statistics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $metrics = [];

        foreach ([
            'followerGains.organicFollowerGain' => __('analytics.metrics.organic_followers'),
            'followerGains.paidFollowerGain' => __('analytics.metrics.paid_followers'),
        ] as $path => $label) {
            $value = $this->observedMetricTotal(data_get($response->json(), 'elements'), $path);

            if ($value !== null) {
                $metrics[] = ['label' => $label, 'value' => $value];
            }
        }

        return $metrics;
    }

    private function fetchShareStatistics(string $orgUrn, string $timeInterval): array
    {
        $org = rawurlencode($orgUrn);
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity={$org}&timeIntervals={$timeInterval}");

        if ($response->failed()) {
            Log::warning('LinkedIn share statistics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $metrics = [];

        foreach ([
            'totalShareStatistics.impressionCount' => __('analytics.metrics.impressions'),
            'totalShareStatistics.clickCount' => __('analytics.metrics.clicks'),
            'totalShareStatistics.likeCount' => __('analytics.metrics.likes'),
            'totalShareStatistics.commentCount' => __('analytics.metrics.comments'),
            'totalShareStatistics.shareCount' => __('analytics.metrics.shares'),
        ] as $path => $label) {
            $value = $this->observedMetricTotal(data_get($response->json(), 'elements'), $path);

            if ($value !== null) {
                $metrics[] = ['label' => $label, 'value' => $value];
            }
        }

        return $metrics;
    }

    private function observedMetricTotal(mixed $elements, string $path): ?int
    {
        if (! is_array($elements) || $elements === [] || ! array_is_list($elements)) {
            return null;
        }

        $total = 0;

        foreach ($elements as $element) {
            if (! is_array($element)) {
                return null;
            }

            $value = data_get($element, $path);

            if (! is_int($value) || $value < 0 || $value > PHP_INT_MAX - $total) {
                return null;
            }

            $total += $value;
        }

        return $total;
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->withToken($this->accessToken)
            ->withHeaders([
                'Linkedin-Version' => '202601',
                'X-Restli-Protocol-Version' => '2.0.0',
            ]);
    }
}
