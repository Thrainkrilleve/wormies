<?php

declare(strict_types=1);

use App\Support\ReverbAllowedOrigins;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\EventHandler;
use Laravel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Laravel\Reverb\Protocols\Pusher\Server;
use Ratchet\RFC6455\Messaging\Frame;

it('allows the app url host and rejects a different origin', function () {
    $allowed = ReverbAllowedOrigins::fromConfig(
        'https://wormhole.example.com',
        '*.other.example.com, https://map.example.com/maps',
    );

    expect($allowed)->toBe([
        'wormhole.example.com',
        '*.other.example.com',
        'map.example.com',
    ]);

    $server = new Server(
        Mockery::mock(ChannelManager::class),
        Mockery::mock(EventHandler::class),
    );
    $verify = new ReflectionMethod(Server::class, 'verifyOrigin');

    $verify->invoke($server, originConnection($allowed, 'https://wormhole.example.com'));
    $verify->invoke($server, originConnection($allowed, 'https://a.other.example.com'));

    expect(fn () => $verify->invoke($server, originConnection($allowed, 'https://evil.example.net')))
        ->toThrow(InvalidOrigin::class);
});

it('uses a wildcard only when no origins are configured', function () {
    expect(ReverbAllowedOrigins::fromConfig(null, null))->toBe(['*'])
        ->and(ReverbAllowedOrigins::fromConfig('', ' , '))->toBe(['*'])
        ->and(ReverbAllowedOrigins::fromConfig('https://', ''))->toBe([]);
});

/**
 * @param  list<string>  $allowed
 */
function originConnection(array $allowed, string $origin): Connection
{
    return new ReverbOriginConnection(
        Mockery::mock(WebSocketConnection::class),
        new Application(
            id: 'wormies',
            key: 'key',
            secret: 'secret',
            pingInterval: 60,
            activityTimeout: 30,
            allowedOrigins: $allowed,
            maxMessageSize: 10_000,
        ),
        $origin,
    );
}

final class ReverbOriginConnection extends Connection
{
    public function identifier(): string
    {
        return 'socket';
    }

    public function id(): string
    {
        return '1.1';
    }

    public function send(string $message): void {}

    public function control(string $type = Frame::OP_PING): void {}

    public function terminate(): void {}
}
