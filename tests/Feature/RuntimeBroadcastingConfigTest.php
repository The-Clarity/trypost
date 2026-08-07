<?php

declare(strict_types=1);

test('inertia pages receive the runtime reverb connection settings', function () {
    config([
        'broadcasting.connections.reverb.key' => 'runtime-public-key',
        'broadcasting.connections.reverb.options.host' => 'trypost.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $response = $this->get(route('login'));

    $response->assertOk();
    $page = $response->original->getData()['page'];

    expect($page['props']['broadcasting'])->toBe([
        'key' => 'runtime-public-key',
        'host' => 'trypost.example.test',
        'port' => 443,
        'scheme' => 'https',
    ]);
});
