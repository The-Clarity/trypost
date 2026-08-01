<?php

declare(strict_types=1);

use App\Support\AiConfiguration;

test('isTextProviderConfigured is true when the default provider has a key', function (string $provider) {
    config()->set('ai.default', $provider);
    config()->set("ai.providers.{$provider}.key", 'fake-key');

    expect(AiConfiguration::isTextProviderConfigured())->toBeTrue();
})->with([
    'openai',
    'gemini',
    'anthropic',
    'openrouter',
]);

test('isTextProviderConfigured is false when the default provider key is empty', function () {
    config()->set('ai.default', 'openrouter');
    config()->set('ai.providers.openrouter.key', '');

    expect(AiConfiguration::isTextProviderConfigured())->toBeFalse();
});

test('isTextProviderConfigured ignores keys on non-default providers', function () {
    config()->set('ai.default', 'openrouter');
    config()->set('ai.providers.openrouter.key', '');
    config()->set('ai.providers.openai.key', 'fake-key');

    expect(AiConfiguration::isTextProviderConfigured())->toBeFalse();
});
