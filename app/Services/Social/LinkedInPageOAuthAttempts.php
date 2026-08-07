<?php

declare(strict_types=1);

namespace App\Services\Social;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class LinkedInPageOAuthAttempts
{
    public const string PHASE_OAUTH = 'oauth';

    public const string PHASE_SELECTION = 'selection';

    private const int MAX_ATTEMPTS = 5;

    private const int STATE_LENGTH = 64;

    private const int TTL_SECONDS = 600;

    /**
     * Issue an opaque attempt value while persisting only its hash in the
     * current session. Each phase owns an independent, bounded ledger.
     *
     * @param  array<string, mixed>  $payload
     */
    public function issue(Request $request, string $phase, string $workspaceId, array $payload = []): string
    {
        $state = Str::random(self::STATE_LENGTH);
        $stateHash = hash('sha256', $state);
        $attempts = $this->liveAttempts($request, $phase);

        $attempts[$stateHash] = [
            'workspace_id' => $workspaceId,
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
            'payload' => $payload,
        ];

        $request->session()->put(
            $this->sessionKey($phase),
            array_slice($attempts, -self::MAX_ATTEMPTS, null, true),
        );

        return $state;
    }

    /**
     * Remove an attempt from its same-session ledger, then claim it once in
     * Redis. Redis uncertainty fails closed; the removed session entry is never
     * restored, so neither transport errors nor retries can replay the attempt.
     *
     * @return array<string, mixed>|null
     */
    public function consume(Request $request, string $phase, mixed $state): ?array
    {
        $stateHash = $this->stateHash($state);
        $attempts = $this->liveAttempts($request, $phase);
        $attempt = $stateHash === null ? null : ($attempts[$stateHash] ?? null);

        if ($stateHash !== null) {
            unset($attempts[$stateHash]);
        }

        // Persist removal before the Redis claim or any provider/network call.
        $request->session()->put($this->sessionKey($phase), $attempts);

        if (! is_array($attempt) || $stateHash === null) {
            return null;
        }

        try {
            $claimed = Cache::store('redis')->add(
                $this->claimKey($phase, $stateHash),
                true,
                self::TTL_SECONDS,
            );
        } catch (Throwable $exception) {
            Log::warning('LinkedIn Page OAuth attempt claim is unavailable.', [
                'phase' => $phase,
                'exception' => $exception::class,
            ]);

            return null;
        }

        return $claimed ? $attempt : null;
    }

    /** @return array<string, array<string, mixed>> */
    private function liveAttempts(Request $request, string $phase): array
    {
        $stored = $request->session()->get($this->sessionKey($phase), []);

        if (! is_array($stored)) {
            return [];
        }

        $now = now()->timestamp;
        $live = [];

        foreach ($stored as $stateHash => $attempt) {
            if (! is_string($stateHash)
                || preg_match('/\A[a-f0-9]{64}\z/', $stateHash) !== 1
                || ! is_array($attempt)
                || ! is_string($attempt['workspace_id'] ?? null)
                || ! is_numeric($attempt['expires_at'] ?? null)
                || (int) $attempt['expires_at'] <= $now
                || ! is_array($attempt['payload'] ?? null)) {
                continue;
            }

            $live[$stateHash] = $attempt;
        }

        return array_slice($live, -self::MAX_ATTEMPTS, null, true);
    }

    private function stateHash(mixed $state): ?string
    {
        if (! is_string($state)
            || preg_match('/\A[A-Za-z0-9]{'.self::STATE_LENGTH.'}\z/', $state) !== 1) {
            return null;
        }

        return hash('sha256', $state);
    }

    private function sessionKey(string $phase): string
    {
        return match ($phase) {
            self::PHASE_OAUTH => 'linkedin_page_oauth_attempts',
            self::PHASE_SELECTION => 'linkedin_page_selection_attempts',
            default => throw new LogicException('Unsupported LinkedIn Page OAuth attempt phase.'),
        };
    }

    private function claimKey(string $phase, string $stateHash): string
    {
        // sessionKey validates the phase before it becomes part of a cache key.
        $this->sessionKey($phase);

        return "trypost:linkedin-page-oauth:claim:{$phase}:{$stateHash}";
    }
}
