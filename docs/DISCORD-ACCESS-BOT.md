# Discord access bot

A second Discord application grants map access to linked members of one external server. It is not the alerts bot, and it is not Alliance Auth's Discord service. There is no role picker. A linked member of the connected server can view that map. Nicknames are never used.

The bot runs `php artisan discord:access-listen` in its own container of this image. It needs the `GUILDS` and privileged `GUILD_MEMBERS` intents. The invite is an OAuth2 authorization-code redirect with scope `bot` and `applications.commands`, permissions `0`, and guild install (`integration_type=0`). Slash replies are ephemeral.

## Create the application

1. Create a new application at the Discord developer portal. Do not reuse the alerts bot.
2. Add a bot. Enable the Server Members privileged intent. Do not enable message content.
3. Under Installation, enable Guild Install. Set the guild install scopes to `bot` and `applications.commands`, and set the bot permissions to `0`. User Install is not required.
4. Under OAuth2, copy the client secret. This is not the bot token. Add a redirect of `{APP_URL}/discord-access/callback` with no extra trailing slash. The value must match exactly, including `https` and the host. An example is `https://wormhole.example.com/discord-access/callback`.
5. Copy the application id, bot token, and client secret into the environment of the web app. Do not commit them. Leave `DISCORD_ACCESS_REDIRECT_URI` empty when the redirect is `{APP_URL}/discord-access/callback`. Set it only when the public URL differs from `APP_URL`, and register that same value in the portal.

```
DISCORD_ACCESS_BOT_TOKEN=
DISCORD_ACCESS_APPLICATION_ID=
DISCORD_ACCESS_CLIENT_SECRET=
DISCORD_ACCESS_REDIRECT_URI=
```

6. Recreate the web app and the `discord-access` container (`wormholesystems-discord-access` in the Alliance Auth snippet). The callback runs in the web app. The listener does not exchange the code, but it uses the same environment file.

The Developer Portal "Install" link has no map state. Installing the bot that way does not bind a map. A map manager still has to finish **Connect a Discord server**.

## Connect a map

A map manager opens the map access settings and chooses **Connect a Discord server**. That starts a 15 minute invite for that map only. The browser must finish the Discord prompt in the same session. Discord redirects back to this app with a `code` and the `state` from that invite.

The callback checks the state against the session and against the hash stored for that map. The state is single-use, expires with the invite, and is not accepted from another browser. The guild id is taken from Discord's token response (`guild.id`). A `guild_id` query parameter is ignored. The app then requests that guild with the bot token. If the bot is not in the guild, nothing is bound and the access page shows the error. This works whether or not the bot was already in the server.

A guild that is already active on a map cannot be bound to a second map. Disconnect it first, and kick the bot in Discord before using that server on another map.

The access page shows Pending, Active (with the server name), Expired, or Removed. Pending includes the authorization link. An error from the callback stays on the page.

If the bot is not yet in the server, joining still binds when exactly one unexpired invite is waiting. That path does not run for guilds the bot already knew at startup, and a repeated `GUILD_CREATE` for a known guild does not bind. Those guilds have no invite state, so attaching them would let any server the bot is in take a pending map.

If two invites are waiting, a new join is ignored. The OAuth callback still binds the map whose state it carries.

A manager cannot type a guild id.

## How members are matched

Alliance Auth login already stores the Discord user id from the OIDC `discord` claim. Everyone else runs `/link` once in the external server, opens the signed link while signed in here, and confirms. The link expires in 10 minutes, works once, and requires the session CSRF token. The Discord user id comes from the interaction, not from a nickname.

Unmatched members are a count on the access page. They are not listed and they are not granted access.

Derived grants are separate from character, corporation, and alliance rows. Those rows are never edited by the bot. Leaving the server, losing the Discord link, or the bot being removed drops only the derived grants. A full member list runs when the bot starts and every hour. Pages of 1,000 pause between chunks, and HTTP 429 responses back off. A 403 from Discord fails closed and removes grants for that server.

The OAuth user token from the code exchange is discarded. Membership uses the bot token.

## Release

Package version `0.0.17` is the Alliance Auth plugin version for this change. Tag `v0.0.17` only after this is merged, as described in `docs/RELEASING.md`.
