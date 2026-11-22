<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DiscordAccess\CreateDiscordAccessLink;
use App\DiscordAccess\DiscordAccessBot;
use App\DiscordAccess\DiscordAccessSync;
use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\MapDiscordServer;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class DiscordAccessController extends Controller
{
    public function __construct(#[CurrentUser] private readonly User $user) {}

    public function store(Map $map, DiscordAccessBot $bot, DiscordAccessSync $sync): RedirectResponse
    {
        Gate::authorize('manageAccess', $map);

        $server = MapDiscordServer::query()->firstOrNew(['map_id' => $map->id]);
        if ($server->status === MapDiscordServer::Active && $server->guild_id !== null) {
            return back()->notify('Discord server already connected', 'Remove the bot from that server before connecting a different one.', 'error');
        }

        $server->status = MapDiscordServer::Pending;
        $server->guild_id = null;
        $server->guild_name = null;
        $server->unmatched_count = 0;
        $sync->startInvite($server);

        return back()
            ->with('discord_access_invite_url', $bot->inviteUrl())
            ->notify('Invite the access bot', 'Add it to the Discord server that should grant access to this map. The bot must not already be in that server.');
    }

    public function disconnect(Map $map, DiscordAccessSync $sync): RedirectResponse
    {
        Gate::authorize('manageAccess', $map);

        $server = MapDiscordServer::query()->where('map_id', $map->id)->first();
        if ($server instanceof MapDiscordServer && $server->guild_id !== null) {
            $sync->revokeGuild($server->guild_id, 'disconnect');
        }

        return back()->notify('Discord server disconnected', 'Derived access was removed. Kick the bot in Discord before connecting that server to another map.');
    }

    public function link(Request $request): View
    {
        abort_unless($request->hasValidSignature(), 403);

        return view('discord-access.link', [
            'action' => $request->fullUrl(),
        ]);
    }

    public function confirmLink(Request $request, CreateDiscordAccessLink $links, DiscordAccessSync $sync): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $pulled = $links->pull($request->string('nonce')->toString());
        abort_unless(is_array($pulled), 403);

        $discordUserId = $pulled['id'];
        abort_if(
            DiscordAccount::query()->where('discord_user_id', $discordUserId)->where('user_id', '!=', $this->user->id)->exists(),
            409,
            'This Discord account is already linked to someone else.',
        );

        $this->user->discordAccount()->updateOrCreate([], [
            'discord_user_id' => $discordUserId,
            'username' => $pulled['username'],
            'display_name' => null,
            'avatar' => null,
        ]);
        $sync->confirmMembership($discordUserId);

        return to_route('settings.show')->notify('Discord linked', 'Map access from configured Discord servers now applies to your characters.');
    }
}
