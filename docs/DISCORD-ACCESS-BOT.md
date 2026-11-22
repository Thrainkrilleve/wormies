# Discord access bot

A second Discord application grants map access to linked members of one external server. It is not the alerts bot, and it is not Alliance Auth's Discord service. There is no role picker. A linked member of the connected server can view that map. Nicknames are never used.

The bot runs `php artisan discord:access-listen` in its own container of this image. It needs the `GUILDS` and privileged `GUILD_MEMBERS` intents. The invite is `bot` and `applications.commands` with permissions `0`. Slash replies are ephemeral.

## Create the application

1. Create a new application at the Discord developer portal. Do not reuse the alerts bot.
2. Add a bot. Enable the Server Members privileged intent. Do not enable message content.
3. Copy the application id and bot token into the environment. Do not commit them.

```
DISCORD_ACCESS_BOT_TOKEN=
DISCORD_ACCESS_APPLICATION_ID=
```

4. Recreate the `discord-access` container (`wormholesystems-discord-access` in the Alliance Auth snippet).

## Connect a map

A map manager opens the map access settings and chooses **Connect a Discord server**. That starts a 15 minute invite for that map only. The manager adds the bot to the external server. The bot must not already be in that server. When it joins, that guild is bound to the map that started the invite. A manager cannot type a guild id, and cannot attach a server the bot is already in.

If two invites are waiting, the join is ignored. Kick the bot and try again with one invite.

## How members are matched

Alliance Auth login already stores the Discord user id from the OIDC `discord` claim. Everyone else runs `/link` once in the external server, opens the signed link while signed in here, and confirms. The link expires in 10 minutes, works once, and requires the session CSRF token. The Discord user id comes from the interaction, not from a nickname.

Unmatched members are a count on the access page. They are not listed and they are not granted access.

Derived grants are separate from character, corporation, and alliance rows. Those rows are never edited by the bot. Leaving the server, losing the Discord link, or the bot being removed drops only the derived grants. A full member list runs when the bot starts and every hour. Pages of 1,000 pause between chunks, and HTTP 429 responses back off. A 403 from Discord fails closed and removes grants for that server.

## Release

Package version `0.0.16` is the Alliance Auth plugin version for this change. Tag `v0.0.16` only after this is merged, as described in `docs/RELEASING.md`.
