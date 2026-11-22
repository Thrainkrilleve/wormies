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
        $nonces = Cache::get($this->indexKey($discordUserId), []);
        $nonces = is_array($nonces) ? $nonces : [];
        $nonces[] = $nonce;
        Cache::put($this->indexKey($discordUserId), $nonces, now()->addMinutes(10));

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

    public function forgetFor(string $discordUserId): void
    {
        $nonces = Cache::pull($this->indexKey($discordUserId), []);
        if (! is_array($nonces)) {
            return;
        }

        foreach ($nonces as $nonce) {
            if (is_string($nonce) && $nonce !== '') {
                Cache::forget($this->key($nonce));
            }
        }
    }

    private function indexKey(string $discordUserId): string
    {
        return 'discord-access.link.user.'.$discordUserId;
    }

    private function key(string $nonce): string
    {
        return 'discord-access.link.'.$nonce;
    }
}
