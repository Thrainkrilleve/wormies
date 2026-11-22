<?php

declare(strict_types=1);

namespace App\DiscordAccess;

/**
 * Gateway decisions for the access bot, separate from the Discord connection
 * so membership changes can be tested without a live socket.
 */
final class DiscordAccessGateway
{
    private bool $ready = false;

    /** @var array<string, true> */
    private array $knownGuilds = [];

    public function __construct(private readonly DiscordAccessSync $sync) {}

    /**
     * Guilds already visible when the socket opens are not invites. Binding one
     * of them here would hand a pending map to whichever server the bot is in.
     *
     * @param  list<string>  $guildIds
     */
    public function markReady(array $guildIds): void
    {
        $this->knownGuilds = [];
        foreach ($guildIds as $guildId) {
            $this->knownGuilds[$guildId] = true;
        }
        $this->ready = true;
        $this->sync->resyncAll();
    }

    public function onGuildCreate(string $guildId): void
    {
        if (! $this->ready || isset($this->knownGuilds[$guildId])) {
            $this->knownGuilds[$guildId] = true;

            return;
        }

        $this->knownGuilds[$guildId] = true;
        $this->sync->bindNewGuild($guildId);
    }

    public function onGuildDelete(string $guildId): void
    {
        unset($this->knownGuilds[$guildId]);
        $this->sync->revokeGuild($guildId, DiscordAccessReason::BotRemoved);
    }

    public function onMemberAdd(string $guildId, string $discordUserId): void
    {
        $this->sync->onMemberJoin($guildId, $discordUserId);
    }

    public function onMemberRemove(string $guildId, string $discordUserId): void
    {
        $this->sync->onMemberLeave($guildId, $discordUserId);
    }
}
