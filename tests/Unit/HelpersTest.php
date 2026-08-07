<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('uploadFromUrl returns null for null url', function () {
    $result = uploadFromUrl(null);

    expect($result)->toBeNull();
});

test('uploadFromUrl returns null for failed request', function () {
    Http::fake([
        '*' => Http::response('Not Found', 404),
    ]);

    $result = uploadFromUrl('https://example.com/image.jpg');

    expect($result)->toBeNull();
});

test('uploadFromUrl uploads image and returns path', function () {
    Storage::fake();

    Http::fake([
        '*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $result = uploadFromUrl('https://example.com/image.jpg');

    expect($result)->not->toBeNull();
    expect($result)->toContain('social-accounts/');
    expect($result)->toEndWith('.jpg');
    Storage::assertExists($result);
});

test('uploadFromUrl writes without requesting public object visibility', function () {
    Http::fake([
        '*' => Http::response('private-image-content', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    Storage::shouldReceive('put')
        ->once()
        ->with(
            Mockery::on(fn (string $path) => str_starts_with($path, 'social-accounts/')),
            'private-image-content',
        )
        ->andReturnTrue();

    expect(uploadFromUrl('https://example.com/image.jpg'))->toStartWith('social-accounts/');
});

test('uploadFromUrl does not hide object storage write failures', function () {
    Http::fake([
        '*' => Http::response('private-image-content', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    Storage::shouldReceive('put')
        ->once()
        ->andThrow(new RuntimeException('private object storage unavailable'));

    expect(fn () => uploadFromUrl('https://example.com/image.jpg'))
        ->toThrow(RuntimeException::class, 'private object storage unavailable');
});

test('uploadFromUrl detects png content type', function () {
    Storage::fake();

    Http::fake([
        '*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
    ]);

    $result = uploadFromUrl('https://example.com/image.png');

    expect($result)->toEndWith('.png');
});

test('uploadFromUrl detects gif content type', function () {
    Storage::fake();

    Http::fake([
        '*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/gif']),
    ]);

    $result = uploadFromUrl('https://example.com/image.gif');

    expect($result)->toEndWith('.gif');
});

test('uploadFromUrl detects webp content type', function () {
    Storage::fake();

    Http::fake([
        '*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/webp']),
    ]);

    $result = uploadFromUrl('https://example.com/image.webp');

    expect($result)->toEndWith('.webp');
});

test('uploadFromUrl uses custom directory', function () {
    Storage::fake();

    Http::fake([
        '*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $result = uploadFromUrl('https://example.com/image.jpg', 'avatars');

    expect($result)->toContain('avatars/');
});

test('uploadFromUrl handles exceptions gracefully', function () {
    Http::fake(function () {
        throw new Exception('Network error');
    });

    $result = uploadFromUrl('https://example.com/image.jpg');

    expect($result)->toBeNull();
});

test('uploadFromUrl returns null for a private-network url and never requests it', function () {
    Http::fake();

    $result = uploadFromUrl('http://127.0.0.1/evil.jpg');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

test('uploadFromUrl attempts the internal fetch when allow_private_network is enabled', function () {
    config(['trypost.security.allow_private_network' => true]);
    Storage::fake();

    Http::fake([
        'http://127.0.0.1/internal.jpg' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $result = uploadFromUrl('http://127.0.0.1/internal.jpg');

    expect($result)->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});
