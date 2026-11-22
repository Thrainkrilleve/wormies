<?php

declare(strict_types=1);

namespace App\DiscordAccess;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class CreateDiscordAccessLink
{
    public function url(string $discordUserId, string $username): string
    {
        $nonce = Str::random(40);
        Cache::put($this->key($nonce), [
            'id' => $discordUserId,
            'username' => $username,
        ], now()->addMinutes(10));

        return URL::temporarySignedRoute('discord-access.link', now()->addMinutes(10), [
            'nonce' => $nonce,
        ]);
    }

    /** @return array{id: string, username: string}|null */
    public function pull(string $nonce): ?array
    {
        $payload = Cache::pull($this->key($nonce));
        if (! is_array($payload) || ! isset($payload['id']) || ! is_string($payload['id']) || $payload['id'] === '') {
            return null;
        }

        return [
            'id' => $payload['id'],
            'username' => is_string($payload['username'] ?? null) && $payload['username'] !== '' ? $payload['username'] : 'member',
        ];
    }

    private function key(string $nonce): string
    {
        return 'discord-access.link.'.$nonce;
    }
}
