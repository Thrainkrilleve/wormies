<?php

declare(strict_types=1);

use App\DiscordAccess\CreateDiscordAccessLink;
use App\DiscordAccess\DiscordAccessBot;
use App\DiscordAccess\DiscordAccessGateway;
use App\DiscordAccess\DiscordAccessReason;
use App\DiscordAccess\DiscordAccessSync;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapDiscordAccessAudit;
use App\Models\MapDiscordGrant;
use App\Models\MapDiscordServer;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;

function discordAccessUser(Permission $permission, Map $map): User
{
    $user = User::factory()->create();
    $character = Character::factory()->for($user)->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_id' => $character->id,
        'accessible_type' => Character::class,
        'permission' => $permission,
        'is_owner' => false,
    ]);

    return $user->fresh();
}

it('rejects an expired or reused discord link and binds a signed one once', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $user = User::factory()->has(Character::factory())->create();
    $url = app(CreateDiscordAccessLink::class)->url('123456789012345678', 'pilot');

    actingAs($user)->get($url)->assertOk();
    actingAs($user)->post($url)->assertRedirect();

    $account = DiscordAccount::query()->where('user_id', $user->id)->first();
    expect($account?->discord_user_id)->toBe('123456789012345678')
        ->and($account?->username)->toBe('pilot');

    actingAs($user)->post($url)->assertForbidden();

    $expired = URL::temporarySignedRoute('discord-access.link', now()->subMinute(), ['nonce' => 'gone']);
    actingAs($user)->get($expired)->assertForbidden();
});

it('does not bind a discord id that already belongs to someone else', function () {
    $owner = User::factory()->create();
    DiscordAccount::factory()->for($owner)->create(['discord_user_id' => '223456789012345678']);
    $user = User::factory()->has(Character::factory())->create();
    $url = app(CreateDiscordAccessLink::class)->url('223456789012345678', 'pilot');

    actingAs($user)->post($url)->assertStatus(409);
    expect($user->fresh()->discordAccount)->toBeNull();
});

it('grants viewer access from a linked member without changing hand edited rows', function () {
    $map = Map::factory()->create();
    $manual = discordAccessUser(Permission::Viewer, $map);
    $manualRows = MapAccess::query()->where('map_id', $map->id)->count();
    $member = User::factory()->has(Character::factory())->create();
    DiscordAccount::factory()->for($member)->create(['discord_user_id' => '323456789012345678']);
    $server = MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '423456789012345678',
        'status' => MapDiscordServer::Active,
    ]);

    app(DiscordAccessSync::class)->onMemberJoin('423456789012345678', '323456789012345678');

    expect($map->fresh()->getUserPermission($member))->toBe(Permission::Viewer)
        ->and(app(App\Policies\MapPolicy::class)->view($member, $map->fresh()))->toBeTrue()
        ->and(app(App\Policies\MapPolicy::class)->manageAccess($member, $map->fresh()))->toBeFalse()
        ->and(MapAccess::query()->where('map_id', $map->id)->count())->toBe($manualRows)
        ->and(MapDiscordAccessAudit::query()->where('action', MapDiscordAccessAudit::Grant)->where('reason', DiscordAccessReason::Join)->exists())->toBeTrue();

    app(DiscordAccessSync::class)->onMemberLeave('423456789012345678', '323456789012345678');
    Context::flush();

    expect($map->fresh()->getUserPermission($member))->toBeNull()
        ->and(MapDiscordGrant::query()->count())->toBe(0)
        ->and(MapAccess::query()->where('accessible_id', $manual->characters()->first()->id)->exists())->toBeTrue()
        ->and(MapDiscordAccessAudit::query()->where('action', MapDiscordAccessAudit::Revoke)->where('reason', DiscordAccessReason::Leave)->exists())->toBeTrue();

    expect($server->fresh()->status)->toBe(MapDiscordServer::Active);
});

it('resyncs linked members, counts unmatched members, and fails closed when the bot is removed', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $map = Map::factory()->create();
    $linked = User::factory()->has(Character::factory())->create();
    DiscordAccount::factory()->for($linked)->create(['discord_user_id' => '523456789012345678']);
    $stale = User::factory()->has(Character::factory())->create();
    DiscordAccount::factory()->for($stale)->create(['discord_user_id' => '623456789012345678']);
    $server = MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '723456789012345678',
        'status' => MapDiscordServer::Active,
    ]);
    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '723456789012345678',
        'user_id' => $stale->id,
        'discord_user_id' => '623456789012345678',
    ]);

    $removed = false;
    Http::fake(function () use (&$removed) {
        if ($removed) {
            return Http::response(['message' => 'Missing Access'], 403);
        }

        return Http::response([
            ['user' => ['id' => '523456789012345678']],
            ['user' => ['id' => '823456789012345678']],
        ]);
    });

    app(DiscordAccessSync::class)->resync($server);

    expect(MapDiscordGrant::query()->where('discord_user_id', '523456789012345678')->exists())->toBeTrue()
        ->and(MapDiscordGrant::query()->where('discord_user_id', '623456789012345678')->exists())->toBeFalse()
        ->and($server->fresh()->unmatched_count)->toBe(1)
        ->and(MapDiscordAccessAudit::query()->where('reason', DiscordAccessReason::Resync)->where('action', MapDiscordAccessAudit::Revoke)->exists())->toBeTrue();

    $removed = true;

    app(DiscordAccessSync::class)->resync($server->fresh());

    expect(MapDiscordGrant::query()->count())->toBe(0)
        ->and($server->fresh()->status)->toBe(MapDiscordServer::Removed)
        ->and($server->fresh()->guild_id)->toBeNull()
        ->and(MapDiscordAccessAudit::query()->where('reason', DiscordAccessReason::BotRemoved)->exists())->toBeTrue();
});

it('pages member lists and backs off when discord rate limits', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $calls = 0;
    Http::fake(function (Illuminate\Http\Client\Request $request) use (&$calls) {
        $calls++;
        if ($calls === 1) {
            return Http::response([], 429, ['Retry-After' => '0']);
        }
        if (! str_contains($request->url(), 'after=')) {
            return Http::response(array_map(
                fn (int $id): array => ['user' => ['id' => (string) $id]],
                range(1, 1000),
            ));
        }

        return Http::response([
            ['user' => ['id' => '923456789012345678']],
        ]);
    });

    $ids = app(DiscordAccessBot::class)->listMemberIds('723456789012345678');

    expect($ids)->toHaveCount(1001)
        ->and($calls)->toBeGreaterThan(2);
});

it('lets only managers start an invite and ignores a pasted guild id', function () {
    config()->set('services.discord.access_application_id', '100000000000000001');
    $map = Map::factory()->create();
    $viewer = discordAccessUser(Permission::Viewer, $map);
    $manager = discordAccessUser(Permission::Manager, $map);

    actingAs($viewer)->post(route('maps.settings.access.discord.store', $map))->assertForbidden();

    actingAs($manager)
        ->post(route('maps.settings.access.discord.store', $map), ['guild_id' => '111111111111111111'])
        ->assertRedirect()
        ->assertSessionHas('discord_access_invite_url');

    $server = MapDiscordServer::query()->where('map_id', $map->id)->first();
    $invite = session('discord_access_invite_url');
    expect($server?->status)->toBe(MapDiscordServer::Pending)
        ->and($server?->guild_id)->toBeNull()
        ->and($invite)->toContain('permissions=0')
        ->and($invite)->toContain('bot%20applications.commands');
});

it('binds a newly joined guild only to the single pending map', function () {
    $map = Map::factory()->create();
    $other = Map::factory()->create();
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'status' => MapDiscordServer::Pending,
        'pending_expires_at' => now()->addMinutes(10),
    ]);
    MapDiscordServer::query()->create([
        'map_id' => $other->id,
        'status' => MapDiscordServer::Pending,
        'pending_expires_at' => now()->addMinutes(10),
    ]);

    $gateway = app(DiscordAccessGateway::class);
    $gateway->markReady([]);
    $gateway->onGuildCreate('333456789012345678');

    expect(MapDiscordServer::query()->whereNotNull('guild_id')->exists())->toBeFalse();

    MapDiscordServer::query()->where('map_id', $other->id)->update(['pending_expires_at' => now()->subMinute()]);
    $gateway->onGuildCreate('343456789012345678');

    expect(MapDiscordServer::query()->where('map_id', $map->id)->first()?->guild_id)->toBe('343456789012345678')
        ->and(MapDiscordServer::query()->where('map_id', $map->id)->first()?->status)->toBe(MapDiscordServer::Active);
});

it('drops grants when the discord id changes or the account is unlinked', function () {
    $map = Map::factory()->create();
    $user = User::factory()->has(Character::factory())->create();
    $account = DiscordAccount::factory()->for($user)->create(['discord_user_id' => '433456789012345678']);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '533456789012345678',
        'status' => MapDiscordServer::Active,
    ]);
    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '533456789012345678',
        'user_id' => $user->id,
        'discord_user_id' => '433456789012345678',
    ]);

    $account->update(['discord_user_id' => '633456789012345678']);

    expect(MapDiscordGrant::query()->count())->toBe(0)
        ->and(MapDiscordAccessAudit::query()->where('reason', DiscordAccessReason::AaIdChange)->exists())->toBeTrue();

    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '533456789012345678',
        'user_id' => $user->id,
        'discord_user_id' => '633456789012345678',
    ]);
    $user->discordAccount?->delete();

    expect(MapDiscordGrant::query()->count())->toBe(0)
        ->and(MapDiscordAccessAudit::query()->where('reason', DiscordAccessReason::Unlink)->exists())->toBeTrue();
});

it('does not grant an unmatched member', function () {
    $map = Map::factory()->create();
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '733456789012345678',
        'status' => MapDiscordServer::Active,
    ]);

    app(DiscordAccessSync::class)->onMemberJoin('733456789012345678', '833456789012345678');

    expect(MapDiscordGrant::query()->count())->toBe(0);
});

it('refuses to start the access listener without a token', function () {
    config()->set('services.discord.access_bot_token', null);
    config()->set('services.discord.access_application_id', null);

    $this->artisan('discord:access-listen')->assertFailed();
});
