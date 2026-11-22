<?php

declare(strict_types=1);

use App\Http\TrustedProxies;

it('trusts loopback and private ranges by default', function () {
    expect(TrustedProxies::fromEnv(null))->toBe([
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ]);
});

it('trusts every hop only when the environment asks for it', function () {
    expect(TrustedProxies::fromEnv('*'))->toBe('*')
        ->and(TrustedProxies::fromEnv(' 10.1.0.0/16 , 192.0.2.1 '))->toBe(['10.1.0.0/16', '192.0.2.1']);
});
