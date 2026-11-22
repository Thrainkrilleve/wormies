<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapAccess\CreateMapAccessAction;
use App\Actions\MapAccess\DeleteMapAccessAction;
use App\Actions\MapAccess\UpdateMapAccessAction;
use App\DiscordAccess\DiscordAccessBot;
use App\DiscordAccess\DiscordAccessState;
use App\Enums\Permission;
use App\Http\Requests\UpdateMapAccessRequest;
use App\Http\Resources\MapAccessEntityResource;
use App\Http\Resources\MapInfoResource;
use App\Models\Alliance;
use App\Models\Corporation;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapDiscordServer;
use App\Services\Discord\DiscordConfigurationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

final class MapAccessController extends Controller
{
    /**
     * @throws Throwable
     */
    public function show(Request $request, Map $map, DiscordAccessBot $bot): Response
    {
        Gate::authorize('manageAccess', $map);

        $search = $request->string('search');
        $entities = DB::query()->fromSub(DB::table('characters')
            ->selectRaw('id, name, "character" as type, (
                SELECT permission
                FROM map_access
                WHERE accessible_type = "App\\\\Models\\\\Character"
                AND accessible_id = characters.id
                AND map_id = ?
            ) as permission, (
                SELECT is_owner
                FROM map_access
                WHERE accessible_type = "App\\\\Models\\\\Character"
                AND accessible_id = characters.id
                AND map_id = ?
            ) as is_owner', [$map->id, $map->id])->unionAll(
                Corporation::query()->selectRaw('id, name, "corporation" as type, (
                    SELECT permission
                    FROM map_access
                    WHERE accessible_type = "App\\\\Models\\\\Corporation"
                    AND accessible_id = corporations.id
                    AND map_id = ?
                ) as permission, (
                    SELECT is_owner
                    FROM map_access
                    WHERE accessible_type = "App\\\\Models\\\\Corporation"
                    AND accessible_id = corporations.id
                    AND map_id = ?
                ) as is_owner', [$map->id, $map->id])
            )->unionAll(
                Alliance::query()->selectRaw('id, name, "alliance" as type, (
                    SELECT permission
                    FROM map_access
                    WHERE accessible_type = "App\\\\Models\\\\Alliance"
                    AND accessible_id = alliances.id
                    AND map_id = ?
                ) as permission, (
                    SELECT is_owner
                    FROM map_access
                    WHERE accessible_type = "App\\\\Models\\\\Alliance"
                    AND accessible_id = alliances.id
                    AND map_id = ?
                ) as is_owner', [$map->id, $map->id])
            ), 'entities')
            ->whereLike('name', sprintf('%s%%', $search))
            ->orderBy('name')
            ->take(30)
            ->select(['id', 'name', 'type', 'permission', 'is_owner'])
            ->get();

        $entitiesWithAccess = $map->mapAccessors()
            ->with('accessible')
            ->orderBy('accessible_id')
            ->get()
            ->toResourceCollection(MapAccessEntityResource::class);

        $discordServer = MapDiscordServer::query()->where('map_id', $map->id)->first();

        return Inertia::render('maps/settings/ShowAccess', [
            'map' => $map->toResource(MapInfoResource::class),
            'entities' => $entities,
            'entitiesWithAccess' => $entitiesWithAccess,
            'search' => $search,
            'permission' => 'manager', // If we reach here, user has manager access (due to gate check)
            'discordAccess' => [
                'status' => $discordServer instanceof MapDiscordServer ? $discordServer->accessState() : null,
                'guild_name' => $discordServer?->guild_name,
                'unmatched_count' => $discordServer instanceof MapDiscordServer ? $discordServer->unmatched_count : 0,
                'invite_url' => $this->discordInviteUrl($discordServer, $bot),
                'error' => $this->discordAccessError(),
            ],
        ]);
    }

    /**
     * @throws Throwable
     */
    public function store(
        UpdateMapAccessRequest $request,
        Map $map,
        CreateMapAccessAction $createMapAccessAction,
        UpdateMapAccessAction $updateMapAccessAction,
        DeleteMapAccessAction $deleteMapAccessAction
    ): RedirectResponse {
        $expires_at = $request->filled('expires_at')
            ? CarbonImmutable::parse($request->validated('expires_at'))
            : null;

        if ($this->isUpdatingExistingAccess($request)) {
            if (! $this->canUpdateExistingAccess($request, $map)) {
                return back()->notify('Access denied.', 'You do not have permission to change this character access.');
            }

            if (($permission = $request->permission) instanceof Permission) {
                $updateMapAccessAction->handle($request->map_access, permission: $permission, expires_at: $expires_at);

                return back()->notify('Access updated successfully.', sprintf('The access for %s has been updated.', $request->map_access->accessible->name));
            }

            $name = $request->map_access->accessible->name;

            $deleteMapAccessAction->handle($request->map_access);

            return back()->notify('Access removed successfully.', sprintf('The access for %s has been removed.', $name));
        }

        $createMapAccessAction->handle($map, $request->accessor, permission: $request->permission, expires_at: $expires_at);

        return back()->notify('Access updated successfully.', 'You have successfully granted access to the entity.');
    }

    private function isUpdatingExistingAccess(UpdateMapAccessRequest $request): bool
    {
        return $request->map_access instanceof MapAccess;
    }

    private function canUpdateExistingAccess(UpdateMapAccessRequest $request, Map $map): bool
    {
        return Gate::check('update', [MapAccess::class, $map, $request->map_access]);
    }

    private function discordInviteUrl(?MapDiscordServer $server, DiscordAccessBot $bot): ?string
    {
        if (! $server instanceof MapDiscordServer || ! $server->isPending() || ! $bot->oauthConfigured()) {
            return null;
        }

        $state = session(DiscordAccessState::SessionKey);
        $hash = $server->pending_state_hash;
        if (! is_string($state) || $state === '' || ! is_string($hash) || ! hash_equals($hash, DiscordAccessState::hash($state))) {
            return null;
        }

        try {
            return $bot->inviteUrl($state);
        } catch (DiscordConfigurationException) {
            return null;
        }
    }

    private function discordAccessError(): ?string
    {
        $error = session('discord_access_error');

        return is_string($error) && $error !== '' ? $error : null;
    }
}
