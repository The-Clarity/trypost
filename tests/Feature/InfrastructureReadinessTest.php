<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

test('readiness reports available only when the database and redis respond', function () {
    $redisConnection = Mockery::mock();
    $redisConnection->shouldReceive('ping')->once()->andReturn('PONG');
    Redis::shouldReceive('connection')->once()->andReturn($redisConnection);

    $this->getJson(route('health.ready'))
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

test('readiness fails closed without exposing dependency errors', function () {
    Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis.internal:6379 refused'));

    $response = $this->getJson(route('health.ready'));

    $response
        ->assertServiceUnavailable()
        ->assertExactJson(['status' => 'unavailable']);
    expect($response->getContent())->not->toContain('redis.internal');
});

test('production liveness stays independent from database and redis readiness', function () {
    $dockerfile = file_get_contents(base_path('docker/Dockerfile'));
    $productionStage = explode('FROM system-base AS production', $dockerfile, 2)[1];
    $compose = file_get_contents(base_path('compose.prod.yaml'));

    expect($productionStage)
        ->toContain('CMD curl -fsS http://127.0.0.1:8081/up || exit 1')
        ->not->toContain('CMD curl -fsS http://127.0.0.1:8081/health || exit 1')
        ->and($compose)
        ->toContain("test: ['CMD', 'curl', '-fsS', 'http://127.0.0.1:8081/health']");
});

test('s3 storage is private, fail visible, and uses the media gateway url', function () {
    $s3 = config('filesystems.disks.s3');

    expect($s3['throw'])->toBeTrue()
        ->and($s3['report'])->toBeTrue()
        ->and(array_key_exists('visibility', $s3))->toBeFalse();

    config([
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
        'filesystems.disks.s3.region' => 'us-east-1',
        'filesystems.disks.s3.bucket' => 'private-media-test',
        'filesystems.disks.s3.root' => 'social-tools/trypost',
        'filesystems.disks.s3.url' => 'https://media.theclarity.today',
    ]);
    Storage::forgetDisk('s3');

    expect(Storage::disk('s3')->url('medias/example.jpg'))
        ->toBe('https://media.theclarity.today/social-tools/trypost/medias/example.jpg');

    Storage::forgetDisk('s3');
});
