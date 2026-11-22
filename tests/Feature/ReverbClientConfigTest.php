<?php

declare(strict_types=1);

it('publishes the configured public reverb endpoint on the page', function () {
    config()->set('reverb.client', [
        'key' => 'public-test-key',
        'host' => 'ws.example.com',
        'port' => 443,
        'scheme' => 'https',
    ]);

    $this->get(route('landing'))
        ->assertSuccessful()
        ->assertSee('"public-test-key"', false)
        ->assertSee('"ws.example.com"', false)
        ->assertSee('443', false)
        ->assertSee('"https"', false);
});
