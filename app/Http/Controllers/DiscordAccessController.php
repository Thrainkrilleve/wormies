<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DiscordAccess\CreateDiscordAccessLink;
use App\DiscordAccess\DiscordAccessAuthorizationException;
use App\DiscordAccess\DiscordAccessBot;
use App\DiscordAccess\DiscordAccessState;
use App\DiscordAccess\DiscordAccessSync;
use App\Enums\Permission;
use App\Http\Requests\UpdateDiscordAccessPermissionRequest;
use App\Models\Character;
use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\MapDiscordServer;
use App\Models\User;
use App\Services\Discord\DiscordConfigurationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class DiscordAccessController extends Controller
{
    public function __construct(#[CurrentUser] private readonly User $user) {}

    public function store(Map $map, DiscordAccessBot $bot, DiscordAccessSync $sync): RedirectResponse
    {
        Gate::authorize('manageAccess', $map);

        if (! $bot->oauthConfigured()) {
            return back()
                ->with('discord_access_error', 'The access bot OAuth client is not configured.')
                ->notify('Discord access is not configured', 'Set the access bot token, application id, and client secret.', 'error');
        }

        $server = MapDiscordServer::query()->firstOrNew(['map_id' => $map->id]);
        if ($server->status === MapDiscordServer::Active && $server->guild_id !== null) {
            return back()->notify('Discord server already connected', 'Remove the bot from that server before connecting a different one.', 'error');
        }

        $state = DiscordAccessState::issue();
        session([DiscordAccessState::SessionKey => $state]);
        $sync->startInvite($server, $state);

        return back()->notify('Authorize the access bot', 'Choose the Discord server that should grant access to this map. This works when the bot is already in that server.');
    }

    public function callback(Request $request, DiscordAccessBot $bot, DiscordAccessSync $sync): RedirectResponse
    {
        $state = $request->query('state');
        $expected = session(DiscordAccessState::SessionKey);
        if (! is_string($state) || $state === '' || ! is_string($expected) || ! hash_equals($expected, $state)) {
            abort(403);
        }

        $server = MapDiscordServer::query()->with('map')->where('pending_state_hash', DiscordAccessState::hash($state))->first();
        if (! $server instanceof MapDiscordServer) {
            abort(403);
        }

        Gate::authorize('manageAccess', $server->map);

        if ($server->status === MapDiscordServer::Pending && ! $server->isPending()) {
            return $this->accessError($server->map, 'This invite has expired. Start a new one.');
        }

        if (! $server->isPending() && $server->status !== MapDiscordServer::Active) {
            return $this->accessError($server->map, 'This invite has expired. Start a new one.');
        }

        $discordError = $request->query('error');
        $code = $request->query('code');
        if ((is_string($discordError) && $discordError !== '') || ! is_string($code) || $code === '') {
            return $this->accessError($server->map, 'Discord authorization was cancelled.');
        }

        if (! $bot->oauthConfigured()) {
            return $this->accessError($server->map, 'The access bot OAuth client is not configured.');
        }

        try {
            $authorization = $bot->exchangeBotAuthorization($code);
        } catch (DiscordAccessAuthorizationException|DiscordConfigurationException) {
            return $this->accessError($server->map, 'Discord did not confirm that authorization.');
        }

        $error = $sync->bindAuthorizedGuild($server, $authorization->guildId);
        if ($error !== null) {
            return $this->accessError($server->map, $error);
        }

        session()->forget(DiscordAccessState::SessionKey);
        $name = $server->fresh()?->guild_name;

        return to_route('maps.settings.access.show', $server->map)
            ->notify('Discord server connected', is_string($name) && $name !== '' ? 'Connected to '.$name.'.' : 'Linked members can now view this map.');
    }

    public function updatePermission(UpdateDiscordAccessPermissionRequest $request, Map $map, DiscordAccessSync $sync): RedirectResponse
    {
        $server = MapDiscordServer::query()->where('map_id', $map->id)->first();
        if (! $server instanceof MapDiscordServer || $server->status !== MapDiscordServer::Active || $server->guild_id === null) {
            return back()->notify('Discord server is not connected', 'Connect a server before choosing an access level.', 'error');
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $permission = Permission::from($request->string('permission')->toString());
        $sync->changeDerivedPermission($server, $actor, $permission);

        return back()->notify('Discord access updated', 'Linked members now have '.$permission->value.' access.');
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

    public function link(Request $request): Response
    {
        $problem = $this->signatureProblem($request);
        if ($problem !== null) {
            return $this->linkScreen($problem, Response::HTTP_FORBIDDEN);
        }

        return $this->linkScreen('confirm', Response::HTTP_OK, $request->fullUrl());
    }

    public function linked(): Response
    {
        return $this->linkScreen('success', Response::HTTP_OK);
    }

    public function confirmLink(Request $request, CreateDiscordAccessLink $links, DiscordAccessSync $sync): Response|RedirectResponse
    {
        $problem = $this->signatureProblem($request);
        if ($problem !== null) {
            return $this->linkScreen($problem, Response::HTTP_FORBIDDEN);
        }

        $pulled = $links->pull($request->string('nonce')->toString());
        if (! is_array($pulled)) {
            return $this->linkScreen('invalid', Response::HTTP_FORBIDDEN);
        }

        $discordUserId = $pulled['id'];
        if (DiscordAccount::query()->where('discord_user_id', $discordUserId)->where('user_id', '!=', $this->user->id)->exists()) {
            return $this->linkScreen('taken', Response::HTTP_CONFLICT);
        }

        $this->user->discordAccount()->updateOrCreate([], [
            'discord_user_id' => $discordUserId,
            'username' => $pulled['username'],
            'display_name' => null,
            'avatar' => null,
        ]);
        $sync->confirmMembership($discordUserId);

        return to_route('discord-access.linked');
    }

    private function signatureProblem(Request $request): ?string
    {
        if ($request->hasValidSignature()) {
            return null;
        }

        return URL::hasCorrectSignature($request) ? 'expired' : 'invalid';
    }

    private function linkScreen(string $state, int $status, ?string $action = null): Response
    {
        $this->user->load('characters');
        $sessionId = session('active_character_id');
        $character = is_numeric($sessionId)
            ? $this->user->characters->firstWhere('id', (int) $sessionId)
            : null;
        $character ??= $this->user->characters->first();

        return Inertia::render('discord/LinkAccount', [
            'state' => $state,
            'action' => $action,
            'account' => [
                'name' => $this->user->name,
                'character' => $character instanceof Character ? [
                    'id' => $character->id,
                    'name' => $character->name,
                ] : null,
            ],
            'cancelUrl' => route('home'),
        ])->toResponse(request())->setStatusCode($status);
    }

    private function accessError(Map $map, string $message): RedirectResponse
    {
        return to_route('maps.settings.access.show', $map)
            ->with('discord_access_error', $message)
            ->notify('Discord server not connected', $message, 'error');
    }
}
