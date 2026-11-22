<?php

declare(strict_types=1);

use App\Data\TrackingData;
use App\DiscordAccess\CreateDiscordAccessLink;
use App\DiscordAccess\DiscordAccessBot;
use App\DiscordAccess\DiscordAccessRoster;
use App\DiscordAccess\DiscordAccessSync;
use App\Enums\Permission;
use App\Http\Requests\DeleteMapSelectionRequest;
use App\Http\Requests\StoreMapConnectionRequest;
use App\Models\Alliance;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapDiscordAccessAudit;
use App\Models\MapDiscordGrant;
use App\Models\MapDiscordServer;
use App\Models\Signature;
use App\Models\User;
use App\Policies\MapPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

function memberAccessManager(Map $map): User
{
    $owner = User::factory()->create();
    $character = Character::factory()->for($owner)->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_id' => $character->id,
        'accessible_type' => Character::class,
        'permission' => Permission::Manager,
        'is_owner' => true,
    ]);

    return $owner->fresh();
}

function derivedDiscordMember(Map $map, Permission $permission, string $discordUserId, string $username): User
{
    $user = User::factory()->has(Character::factory())->create();
    DiscordAccount::factory()->for($user)->create([
        'discord_user_id' => $discordUserId,
        'username' => $username,
        'avatar' => 'avatar-hash',
    ]);
    MapDiscordServer::query()->updateOrCreate(['map_id' => $map->id], [
        'guild_id' => '510000000000000001',
        'guild_name' => 'Example Server',
        'status' => MapDiscordServer::Active,
        'permission' => $permission->value,
    ]);
    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000001',
        'user_id' => $user->id,
        'discord_user_id' => $discordUserId,
    ]);

    return $user->fresh();
}

it('gives discord members edit access and keeps an explicit row in front', function () {
    $map = Map::factory()->create();
    $member = derivedDiscordMember($map, Permission::Member, '510000000000000101', 'member-pilot');
    $viewer = User::factory()->has(Character::factory())->create();
    DiscordAccount::factory()->for($viewer)->create([
        'discord_user_id' => '510000000000000102',
        'username' => 'viewer-pilot',
    ]);
    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000001',
        'user_id' => $viewer->id,
        'discord_user_id' => '510000000000000102',
    ]);
    MapDiscordServer::query()->where('map_id', $map->id)->update(['permission' => Permission::Viewer->value]);

    expect($map->fresh()->getUserPermission($viewer))->toBe(Permission::Viewer)
        ->and(app(MapPolicy::class)->view($viewer, $map->fresh()))->toBeTrue()
        ->and(app(MapPolicy::class)->update($viewer, $map->fresh()))->toBeFalse()
        ->and(app(MapPolicy::class)->manageAccess($viewer, $map->fresh()))->toBeFalse();

    MapDiscordServer::query()->where('map_id', $map->id)->update(['permission' => Permission::Member->value]);
    $map = $map->fresh();

    expect($map->getUserPermission($member->fresh()))->toBe(Permission::Member)
        ->and(app(MapPolicy::class)->update($member, $map))->toBeTrue()
        ->and(app(MapPolicy::class)->viewCharacters($member, $map))->toBeTrue()
        ->and(app(MapPolicy::class)->manageAccess($member, $map))->toBeFalse();

    $explicit = Character::factory()->for($member)->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_id' => $explicit->id,
        'accessible_type' => Character::class,
        'permission' => Permission::Viewer,
    ]);

    expect($map->fresh()->getUserPermission($member->fresh()))->toBe(Permission::Viewer);

    MapDiscordServer::query()->where('map_id', $map->id)->update(['permission' => Permission::Manager->value]);

    expect($map->fresh()->getUserPermission($viewer->fresh()))->toBeNull()
        ->and(app(MapPolicy::class)->manageAccess($viewer->fresh(), $map->fresh()))->toBeFalse();
});

it('lets a discord member edit connections and signatures', function () {
    $map = Map::factory()->create();
    $member = derivedDiscordMember($map, Permission::Member, '510000000000000201', 'editor');
    $from = placeMapSolarsystem($map, 30011101);
    $to = placeMapSolarsystem($map, 30011102, 200, 200);
    $signature = Signature::factory()->create(['map_solarsystem_id' => $from->id]);

    expect(Gate::forUser($member)->allows('update', $signature))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $map))->toBeTrue();

    $store = StoreMapConnectionRequest::create('/', 'POST', [
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
    ]);
    $store->setUserResolver(fn () => $member);
    expect($store->authorize($member))->toBeTrue();

    $delete = DeleteMapSelectionRequest::create('/', 'DELETE', [
        'map_solarsystem_ids' => [$from->id],
    ]);
    $delete->setUserResolver(fn () => $member);
    expect($delete->authorize($member))->toBeTrue();

    expect(TrackingData::authorize($member, Request::create('/', 'POST', [
        'from_map_solarsystem_id' => $from->id,
    ])))->toBeTrue();

    MapDiscordServer::query()->where('map_id', $map->id)->update(['permission' => Permission::Viewer->value]);
    $viewer = $member->fresh();

    expect(Gate::forUser($viewer)->allows('update', $signature))->toBeFalse()
        ->and($store->authorize($viewer))->toBeFalse()
        ->and(TrackingData::authorize($viewer, Request::create('/', 'POST', [
            'from_map_solarsystem_id' => $from->id,
        ])))->toBeFalse();
});

it('lets only a manager change the discord level and writes an audit', function () {
    $map = Map::factory()->create();
    $manager = memberAccessManager($map);
    $viewer = User::factory()->has(Character::factory())->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_id' => $viewer->characters()->first()->id,
        'accessible_type' => Character::class,
        'permission' => Permission::Viewer,
    ]);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000301',
        'status' => MapDiscordServer::Active,
        'permission' => Permission::Member->value,
    ]);

    actingAs($viewer)->post(route('maps.settings.access.discord.permission', $map), [
        'permission' => Permission::Viewer->value,
    ])->assertForbidden();

    $this->flushSession();

    actingAs($manager)->post(route('maps.settings.access.discord.permission', $map), [
        'permission' => Permission::Manager->value,
    ])->assertSessionHasErrors('permission');

    actingAs($manager)->post(route('maps.settings.access.discord.permission', $map), [
        'permission' => Permission::Viewer->value,
    ])->assertRedirect();

    $audit = MapDiscordAccessAudit::query()->where('action', MapDiscordAccessAudit::Permission)->first();
    expect(MapDiscordServer::query()->where('map_id', $map->id)->first()?->permission)->toBe(Permission::Viewer->value)
        ->and($audit?->reason)->toBe('member->viewer')
        ->and($audit?->user_id)->toBe($manager->id)
        ->and($audit?->reason)->not->toContain($manager->name);
});

it('lists linked discord members for a manager and hides discord ids', function () {
    $map = Map::factory()->create();
    $manager = memberAccessManager($map);
    $corporation = Corporation::factory()->create(['name' => 'Example Corp', 'ticker' => 'EXC', 'last_updated' => '2026-11-01 00:00:00']);
    $alliance = Alliance::factory()->create(['name' => 'Example Alliance', 'ticker' => 'EXA', 'last_updated' => '2026-11-02 00:00:00']);
    $user = User::factory()->create(['name' => 'Map Pilot']);
    Character::factory()->for($user)->create([
        'name' => 'Roster Pilot',
        'corporation_id' => $corporation->id,
        'alliance_id' => $alliance->id,
    ]);
    $discordUserId = '510000000000000401';
    DiscordAccount::factory()->for($user)->create([
        'discord_user_id' => $discordUserId,
        'username' => 'roster-user',
    ]);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000402',
        'guild_name' => 'Example Server',
        'status' => MapDiscordServer::Active,
        'permission' => Permission::Member->value,
        'unmatched_count' => 4,
    ]);
    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000402',
        'user_id' => $user->id,
        'discord_user_id' => $discordUserId,
    ]);
    $outsider = User::factory()->has(Character::factory())->create();

    actingAs($outsider)->get(route('maps.settings.access.show', $map))->assertForbidden();

    $response = actingAs($manager)->get(route('maps.settings.access.show', [
        'map' => $map,
        'corporation_id' => $corporation->id,
    ]));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('discordAccess.permission', 'member')
        ->where('discordAccess.roster.unmatched_count', 4)
        ->where('discordAccess.roster.total', 1)
        ->where('discordAccess.roster.members.0.username', 'roster-user')
        ->where('discordAccess.roster.members.0.user_name', 'Map Pilot')
        ->where('discordAccess.roster.members.0.characters.0.name', 'Roster Pilot')
        ->where('discordAccess.roster.members.0.characters.0.corporation.name', 'Example Corp')
        ->where('discordAccess.roster.members.0.characters.0.corporation.ticker', 'EXC')
        ->where('discordAccess.roster.members.0.characters.0.corporation.as_of', '2026-11-01 00:00:00')
        ->where('discordAccess.roster.members.0.characters.0.alliance.ticker', 'EXA')
        ->where('discordAccess.roster.corporations.0.count', 1)
        ->etc());

    expect($response->getContent())->not->toContain($discordUserId)
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")
        ->and((string) $response->headers->get('Content-Security-Policy'))->not->toContain('discord.com');

    actingAs($manager)->get(route('maps.settings.access.show', [
        'map' => $map,
        'corporation_id' => 999999,
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('discordAccess.roster.total', 0)
        ->etc());
});

it('pages the discord roster', function () {
    $map = Map::factory()->create();
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000501',
        'status' => MapDiscordServer::Active,
        'permission' => Permission::Member->value,
    ]);
    foreach (range(1, DiscordAccessRoster::PerPage + 1) as $index) {
        $user = User::factory()->create(['name' => 'Pilot '.$index]);
        DiscordAccount::factory()->for($user)->create([
            'discord_user_id' => (string) (510000000000100000 + $index),
            'username' => 'page-pilot-'.$index,
        ]);
        MapDiscordGrant::query()->create([
            'map_id' => $map->id,
            'guild_id' => '510000000000000501',
            'user_id' => $user->id,
            'discord_user_id' => (string) (510000000000100000 + $index),
        ]);
    }

    $request = Request::create('/', 'GET', ['roster_page' => 2]);
    $page = app(DiscordAccessRoster::class)->for($map, MapDiscordServer::query()->where('map_id', $map->id)->first(), $request);

    expect($page['total'])->toBe(DiscordAccessRoster::PerPage + 1)
        ->and($page['page'])->toBe(2)
        ->and($page['members'])->toHaveCount(1)
        ->and($page['members'][0]['username'])->toBe('page-pilot-'.(DiscordAccessRoster::PerPage + 1));
});

it('unlinks only the invoking user and removes them from the roster', function () {
    $map = Map::factory()->create();
    $manager = memberAccessManager($map);
    $discordUserId = '510000000000000601';
    $user = User::factory()->create(['name' => 'Unlink Pilot']);
    Character::factory()->for($user)->create(['name' => 'Unlink Character']);
    $account = DiscordAccount::factory()->for($user)->create([
        'discord_user_id' => $discordUserId,
        'username' => 'unlink-me',
        'avatar' => 'avatar-hash',
    ]);
    $other = User::factory()->create();
    DiscordAccount::factory()->for($other)->create([
        'discord_user_id' => '510000000000000602',
        'username' => 'stay-linked',
    ]);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000603',
        'status' => MapDiscordServer::Active,
        'permission' => Permission::Member->value,
    ]);
    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000603',
        'user_id' => $user->id,
        'discord_user_id' => $discordUserId,
    ]);
    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000603',
        'user_id' => $other->id,
        'discord_user_id' => '510000000000000602',
    ]);
    $url = app(CreateDiscordAccessLink::class)->url($discordUserId, 'unlink-me');
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $nonce = $query['nonce'] ?? null;

    expect(app(DiscordAccessSync::class)->unlinkInvokedUser($discordUserId))
        ->toBe('This Discord account is no longer linked. Derived map access was removed.')
        ->and(DiscordAccount::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(DiscordAccount::query()->where('username', 'unlink-me')->exists())->toBeFalse()
        ->and(MapDiscordGrant::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(MapDiscordGrant::query()->where('user_id', $other->id)->exists())->toBeTrue()
        ->and(Cache::get('discord-access.link.'.$nonce))->toBeNull()
        ->and(MapDiscordAccessAudit::query()->where('reason', 'unlink')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(MapDiscordAccessAudit::query()->where('reason', 'unlink')->first()?->reason)->not->toContain('unlink-me');

    actingAs($manager)->get(route('maps.settings.access.show', $map))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('discordAccess.roster.total', 1)
            ->where('discordAccess.roster.members.0.username', 'stay-linked')
            ->etc());

    expect(app(DiscordAccessSync::class)->unlinkInvokedUser($discordUserId))->toBe('You are not linked.');
});

it('registers link and unlink commands together', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    Http::fake();

    app(DiscordAccessBot::class)->registerCommands();

    Http::assertSent(function ($request): bool {
        $names = array_column($request->data(), 'name');

        return in_array('link', $names, true) && in_array('unlink', $names, true);
    });
});
