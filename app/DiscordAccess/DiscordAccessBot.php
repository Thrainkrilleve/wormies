<?php

declare(strict_types=1);

namespace App\DiscordAccess;

use App\Services\Discord\DiscordConfigurationException;
use App\Services\Discord\RetriesDiscordRateLimits;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * REST client for the access bot. Membership checks use this token, never a member OAuth token.
 */
final class DiscordAccessBot
{
    use RetriesDiscordRateLimits;

    /** Discord permission bit for Manage Nicknames. */
    public const int ManageNicknames = 134217728;

    public function configured(): bool
    {
        return $this->token() !== null && $this->applicationId() !== null;
    }

    public function applicationId(): ?string
    {
        $id = config('services.discord.access_application_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function oauthConfigured(): bool
    {
        if (! $this->configured() || $this->clientSecret() === null) {
            return false;
        }

        try {
            $this->redirectUri();
        } catch (DiscordConfigurationException) {
            return false;
        }

        return true;
    }

    public function inviteUrl(string $state): string
    {
        $applicationId = $this->applicationId();
        if ($applicationId === null) {
            throw new DiscordConfigurationException('DISCORD_ACCESS_APPLICATION_ID is not configured.');
        }
        if ($state === '' || mb_strlen($state) > 128) {
            throw new DiscordConfigurationException('The Discord invite state is invalid.');
        }

        return 'https://discord.com/api/oauth2/authorize?'.http_build_query([
            'client_id' => $applicationId,
            'permissions' => (string) self::ManageNicknames,
            'integration_type' => '0',
            'scope' => 'bot applications.commands',
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange an authorization code. The guild id comes from Discord's token
     * response. A guild_id on the redirect is ignored by the caller.
     */
    public function exchangeBotAuthorization(string $code): DiscordAccessAuthorization
    {
        $applicationId = $this->applicationId();
        $secret = $this->clientSecret();
        if ($applicationId === null || $secret === null) {
            throw new DiscordConfigurationException('The access bot OAuth client is not configured.');
        }
        if ($code === '' || mb_strlen($code) > 2048) {
            throw new DiscordAccessAuthorizationException('Discord did not confirm that authorization.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout(10)
            ->post('https://discord.com/api/v10/oauth2/token', [
                'client_id' => $applicationId,
                'client_secret' => $secret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ]);

        if (! $response->successful()) {
            throw new DiscordAccessAuthorizationException('Discord did not confirm that authorization.');
        }

        $guild = $response->json('guild');
        $id = is_array($guild) ? ($guild['id'] ?? null) : null;
        if (is_int($id)) {
            $id = (string) $id;
        }
        if (! is_string($id) || preg_match('/^\d{1,20}$/', $id) !== 1) {
            throw new DiscordAccessAuthorizationException('Discord did not confirm a server for that authorization.');
        }

        return new DiscordAccessAuthorization($id);
    }

    /**
     * @return list<string> Discord user ids, one page at a time.
     */
    public function listMemberIds(string $guildId): array
    {
        $ids = [];
        $after = null;

        while (true) {
            $query = ['limit' => 1000];
            if ($after !== null) {
                $query['after'] = $after;
            }

            $response = $this->request()->get('/guilds/'.$guildId.'/members', $query);
            $this->throwIfRemoved($response);
            $response->throw();

            $decoded = $response->json();
            if (! is_array($decoded) || $decoded === []) {
                break;
            }

            /** @var list<array<string, mixed>> $members */
            $members = array_values($decoded);
            foreach ($members as $member) {
                $user = $member['user'] ?? null;
                if (is_array($user) && isset($user['id'])) {
                    $ids[] = (string) $user['id'];
                }
            }

            $last = $members[array_key_last($members)]['user']['id'] ?? null;
            if (! is_scalar($last) || count($members) < 1000) {
                break;
            }

            $after = (string) $last;
            if (! app()->runningUnitTests()) {
                usleep(250_000);
            }
        }

        return $ids;
    }

    public function guildName(string $guildId): ?string
    {
        $response = $this->request()->get('/guilds/'.$guildId);
        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }
        $response->throw();
        $name = $response->json('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return 'present'|'absent'|'removed'
     */
    public function membership(string $guildId, string $discordUserId): string
    {
        $response = $this->request()->get('/guilds/'.$guildId.'/members/'.$discordUserId);
        if ($response->status() === 404) {
            return 'absent';
        }
        if (in_array($response->status(), [403, 401], true)) {
            return 'removed';
        }
        $response->throw();

        return 'present';
    }

    /**
     * @return 'updated'|'unchanged'|'absent'|'denied'|'failed'
     */
    public function changeNickname(string $guildId, string $discordUserId, ?string $nick): string
    {
        if (preg_match('/^\d{1,20}$/', $guildId) !== 1 || preg_match('/^\d{1,20}$/', $discordUserId) !== 1) {
            return 'failed';
        }

        try {
            $member = $this->request()->get('/guilds/'.$guildId.'/members/'.$discordUserId);
        } catch (Throwable) {
            return 'failed';
        }

        if ($member->status() === 404) {
            return 'absent';
        }
        if (in_array($member->status(), [401, 403], true)) {
            return 'denied';
        }
        if (! $member->successful()) {
            return 'failed';
        }

        $current = $member->json('nick');
        $current = is_string($current) && $current !== '' ? $current : null;
        if ($current === $nick) {
            return 'unchanged';
        }

        try {
            $updated = $this->request()->asJson()->patch('/guilds/'.$guildId.'/members/'.$discordUserId, [
                'nick' => $nick,
            ]);
        } catch (Throwable) {
            return 'failed';
        }

        if ($updated->successful()) {
            return 'updated';
        }
        if (in_array($updated->status(), [401, 403], true)) {
            return 'denied';
        }

        return 'failed';
    }

    public function registerCommands(): void
    {
        $applicationId = $this->applicationId();
        if ($applicationId === null) {
            throw new DiscordConfigurationException('DISCORD_ACCESS_APPLICATION_ID is not configured.');
        }

        $this->request()->put('/applications/'.$applicationId.'/commands', [
            [
                'name' => 'link',
                'description' => 'Link this Discord account to your Wormhole Systems user',
                'type' => 1,
            ],
            [
                'name' => 'unlink',
                'description' => 'Remove the link between this Discord account and Wormhole Systems',
                'type' => 1,
            ],
        ])->throw();
    }

    public function redirectUri(): string
    {
        $configured = config('services.discord.access_redirect');
        $uri = is_string($configured) && $configured !== '' ? $configured : route('discord-access.callback');
        $scheme = parse_url($uri, PHP_URL_SCHEME);
        $host = parse_url($uri, PHP_URL_HOST);
        $user = parse_url($uri, PHP_URL_USER);
        if (! is_string($scheme) || ! in_array(mb_strtolower($scheme), ['https', 'http'], true) || ! is_string($host) || $host === '' || is_string($user)) {
            throw new DiscordConfigurationException('DISCORD_ACCESS_REDIRECT_URI must be an absolute http(s) URL.');
        }

        return $uri;
    }

    private function request(): PendingRequest
    {
        $token = $this->token();
        if ($token === null) {
            throw new DiscordConfigurationException('DISCORD_ACCESS_BOT_TOKEN is not configured.');
        }

        return Http::baseUrl('https://discord.com/api/v10')
            ->acceptJson()
            ->withHeaders(['Authorization' => 'Bot '.$token])
            ->retry(
                3,
                fn (int $attempt, Throwable $exception): int => $this->retryDelayMilliseconds($exception),
                fn (Throwable $exception): bool => $this->wasRateLimited($exception),
                false,
            )
            ->timeout(10);
    }

    private function clientSecret(): ?string
    {
        $secret = config('services.discord.access_client_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function token(): ?string
    {
        $token = config('services.discord.access_bot_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function throwIfRemoved(Response $response): void
    {
        if (in_array($response->status(), [401, 403, 404], true)) {
            throw new DiscordAccessBotRemoved('The access bot can no longer see guild '.$response->effectiveUri()?->getPath());
        }
    }
}
