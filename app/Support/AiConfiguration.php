<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for reading the Laravel AI SDK provider configuration.
 *
 * Agents intentionally do not override provider() — Promptable falls back to
 * config('ai.default'), which already includes first-class drivers such as
 * OpenRouter. Availability checks must therefore look at ai.providers.*.key,
 * not the legacy services.* mirrors.
 */
final class AiConfiguration
{
    /**
     * Whether the configured default text provider has an API key.
     */
    public static function isTextProviderConfigured(): bool
    {
        $provider = (string) config('ai.default');

        return filled(config("ai.providers.{$provider}.key"));
    }
}
