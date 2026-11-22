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

    public function configured(): bool
    {
        return $this->token() !== null && $this->applicationId() !== null;
    }

    public function applicationId(): ?string
    {
        $id = config('services.discord.access_application_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function inviteUrl(): string
    {
        $applicationId = $this->applicationId();
        if ($applicationId === null) {
            throw new DiscordConfigurationException('DISCORD_ACCESS_APPLICATION_ID is not configured.');
        }

        return 'https://discord.com/api/oauth2/authorize?'.http_build_query([
            'client_id' => $applicationId,
            'permissions' => '0',
            'scope' => 'bot applications.commands',
        ], '', '&', PHP_QUERY_RFC3986);
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

    public function registerLinkCommand(): void
    {
        $applicationId = $this->applicationId();
        if ($applicationId === null) {
            throw new DiscordConfigurationException('DISCORD_ACCESS_APPLICATION_ID is not configured.');
        }

        $this->request()->put('/applications/'.$applicationId.'/commands', [[
            'name' => 'link',
            'description' => 'Link this Discord account to your Wormhole Systems user',
            'type' => 1,
        ]])->throw();
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
