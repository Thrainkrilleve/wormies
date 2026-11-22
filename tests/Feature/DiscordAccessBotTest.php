<?php

declare(strict_types=1);

use App\DiscordAccess\CreateDiscordAccessLink;
use App\DiscordAccess\DiscordAccessBot;
use App\DiscordAccess\DiscordAccessGateway;
use App\DiscordAccess\DiscordAccessReason;
use App\DiscordAccess\DiscordAccessState;
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
use Inertia\Testing\AssertableInertia as Assert;

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

function discordAccessOwner(Map $map): void
{
    if ($map->mapOwner()->exists()) {
        return;
    }

    $character = Character::factory()->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_id' => $character->id,
        'accessible_type' => Character::class,
        'permission' => Permission::Manager,
        'is_owner' => true,
    ]);
}

it('rejects an expired or reused discord link and binds a signed one once', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $user = User::factory()->has(Character::factory())->create()->fresh();
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
    $user = User::factory()->has(Character::factory())->create()->fresh();
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
        'permission' => Permission::Viewer->value,
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
    configureDiscordAccessOauth();
    $map = Map::factory()->create();
    discordAccessOwner($map);
    $viewer = discordAccessUser(Permission::Viewer, $map);
    $manager = discordAccessUser(Permission::Manager, $map);

    actingAs($viewer)->post(route('maps.settings.access.discord.store', $map))->assertForbidden();

    actingAs($manager)
        ->post(route('maps.settings.access.discord.store', $map), ['guild_id' => '111111111111111111'])
        ->assertRedirect()
        ->assertSessionHas(DiscordAccessState::SessionKey);

    $state = session(DiscordAccessState::SessionKey);
    $server = MapDiscordServer::query()->where('map_id', $map->id)->first();
    $invite = app(DiscordAccessBot::class)->inviteUrl(is_string($state) ? $state : '');

    expect($state)->toBeString()
        ->and($server?->status)->toBe(MapDiscordServer::Pending)
        ->and($server?->guild_id)->toBeNull()
        ->and($server?->pending_state_hash)->toBe(DiscordAccessState::hash((string) $state))
        ->and($server?->pending_state_hash)->not->toBe($state)
        ->and($invite)->toContain('response_type=code')
        ->and($invite)->toContain('permissions=0')
        ->and($invite)->toContain('integration_type=0')
        ->and($invite)->toContain('bot%20applications.commands')
        ->and($invite)->toContain('redirect_uri='.rawurlencode('https://wormhole.example.com/discord-access/callback'))
        ->and($invite)->toContain('state='.$state)
        ->and($invite)->not->toContain('test-client-secret')
        ->and($invite)->not->toContain('111111111111111111');

    $response = actingAs($manager)->get(route('maps.settings.access.show', $map));
    $policy = (string) $response->headers->get('Content-Security-Policy');
    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('discordAccess.status', 'pending')
            ->where('discordAccess.invite_url', $invite)
            ->where('discordAccess.error', null)
            ->etc());
    expect($policy)->toContain("default-src 'self'")
        ->and($policy)->toContain("script-src 'self'")
        ->and($policy)->not->toContain('discord.com');
});

it('does not start an invite when the oauth client secret is missing', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    config()->set('services.discord.access_client_secret', null);
    $map = Map::factory()->create();
    $manager = discordAccessUser(Permission::Manager, $map);

    actingAs($manager)->post(route('maps.settings.access.discord.store', $map))->assertRedirect();

    expect(MapDiscordServer::query()->where('map_id', $map->id)->exists())->toBeFalse()
        ->and(session('discord_access_error'))->toBe('The access bot OAuth client is not configured.');
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

it('does not bind a pending map to guilds the bot already knows', function () {
    $map = Map::factory()->create();
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'status' => MapDiscordServer::Pending,
        'pending_expires_at' => now()->addMinutes(10),
        'pending_state_hash' => DiscordAccessState::hash('kept-for-the-invite'),
    ]);
    $guildId = '943456789012345678';
    $gateway = app(DiscordAccessGateway::class);

    $gateway->onGuildCreate($guildId);
    $gateway->markReady([$guildId]);
    $gateway->onGuildCreate($guildId);

    $server = MapDiscordServer::query()->where('map_id', $map->id)->first();
    expect($server?->guild_id)->toBeNull()
        ->and($server?->status)->toBe(MapDiscordServer::Pending)
        ->and($server?->pending_state_hash)->toBe(DiscordAccessState::hash('kept-for-the-invite'));
});

it('binds an already present bot from the oauth callback and ignores the query guild id', function () {
    configureDiscordAccessOauth();
    $map = Map::factory()->create();
    discordAccessOwner($map);
    $manager = discordAccessUser(Permission::Manager, $map);
    $linked = User::factory()->has(Character::factory())->create();
    DiscordAccount::factory()->for($linked)->create(['discord_user_id' => '133456789012345678']);
    $state = startDiscordAccessInvite($manager, $map);
    $authorizedGuild = '343456789012345699';
    $queryGuild = '343456789012345698';

    fakeDiscordAccessGuilds([
        $authorizedGuild => 'Example Server',
    ], ['133456789012345678']);

    actingAs($manager)->get(route('discord-access.callback', [
        'code' => 'auth-code',
        'state' => $state,
        'guild_id' => $queryGuild,
    ]))->assertRedirect(route('maps.settings.access.show', $map));

    $server = MapDiscordServer::query()->where('map_id', $map->id)->first();
    expect($server?->status)->toBe(MapDiscordServer::Active)
        ->and($server?->guild_id)->toBe($authorizedGuild)
        ->and($server?->guild_name)->toBe('Example Server')
        ->and($server?->pending_state_hash)->toBeNull()
        ->and(MapDiscordGrant::query()->where('discord_user_id', '133456789012345678')->exists())->toBeTrue()
        ->and(collect(Http::recorded())->contains(function (array $pair): bool {
            $header = $pair[0]->header('Authorization')[0] ?? '';

            return str_contains($header, 'discarded-user-token');
        }))->toBeFalse();

    actingAs($manager)->get(route('maps.settings.access.show', $map))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('discordAccess.status', 'active')
            ->where('discordAccess.guild_name', 'Example Server')
            ->where('discordAccess.error', null)
            ->where('discordAccess.invite_url', null)
            ->etc());
});

it('rejects a bad, expired, or reused oauth state', function () {
    configureDiscordAccessOauth();
    $map = Map::factory()->create();
    discordAccessOwner($map);
    $manager = discordAccessUser(Permission::Manager, $map);
    $state = startDiscordAccessInvite($manager, $map);

    actingAs($manager)->get(route('discord-access.callback', [
        'code' => 'auth-code',
        'state' => 'not-the-issued-state',
    ]))->assertForbidden();
    expect(MapDiscordServer::query()->where('map_id', $map->id)->first()?->guild_id)->toBeNull();

    MapDiscordServer::query()->where('map_id', $map->id)->update(['pending_expires_at' => now()->subMinute()]);
    actingAs($manager)->get(route('discord-access.callback', [
        'code' => 'auth-code',
        'state' => $state,
    ]))->assertRedirect(route('maps.settings.access.show', $map));
    expect(MapDiscordServer::query()->where('map_id', $map->id)->first()?->guild_id)->toBeNull()
        ->and(session('discord_access_error'))->toBe('This invite has expired. Start a new one.');

    actingAs($manager)->get(route('maps.settings.access.show', $map))
        ->assertInertia(fn (Assert $page) => $page
            ->where('discordAccess.status', 'expired')
            ->where('discordAccess.error', 'This invite has expired. Start a new one.')
            ->etc());

    MapDiscordServer::query()->where('map_id', $map->id)->update(['pending_expires_at' => now()->addMinutes(10)]);
    fakeDiscordAccessGuilds(['353456789012345678' => 'Example Server'], []);
    actingAs($manager)->get(route('discord-access.callback', [
        'code' => 'auth-code',
        'state' => $state,
        'guild_id' => '999999999999999999',
    ]))->assertRedirect(route('maps.settings.access.show', $map));

    session([DiscordAccessState::SessionKey => $state]);
    actingAs($manager)->get(route('discord-access.callback', [
        'code' => 'auth-code',
        'state' => $state,
    ]))->assertForbidden();

    expect(MapDiscordServer::query()->where('guild_id', '353456789012345678')->count())->toBe(1)
        ->and(MapDiscordServer::query()->where('map_id', $map->id)->first()?->pending_state_hash)->toBeNull();
});

it('rejects a guild the access bot is not in, even when the query names a guild it is in', function () {
    configureDiscordAccessOauth();
    $map = Map::factory()->create();
    discordAccessOwner($map);
    $manager = discordAccessUser(Permission::Manager, $map);
    $state = startDiscordAccessInvite($manager, $map);
    $present = '363456789012345678';
    $absent = '373456789012345678';

    Http::fake(function (Illuminate\Http\Client\Request $request) use ($present, $absent) {
        if (str_contains($request->url(), '/oauth2/token')) {
            return Http::response([
                'access_token' => 'discarded-user-token',
                'guild' => ['id' => $absent, 'name' => 'Absent Server'],
            ]);
        }
        if (str_ends_with($request->url(), '/guilds/'.$present)) {
            return Http::response(['id' => $present, 'name' => 'Present Server']);
        }

        return Http::response(['message' => 'Unknown Guild'], 404);
    });

    actingAs($manager)->get(route('discord-access.callback', [
        'code' => 'auth-code',
        'state' => $state,
        'guild_id' => $present,
    ]))->assertRedirect(route('maps.settings.access.show', $map));

    expect(MapDiscordServer::query()->where('map_id', $map->id)->first()?->guild_id)->toBeNull()
        ->and(MapDiscordServer::query()->where('map_id', $map->id)->first()?->status)->toBe(MapDiscordServer::Pending)
        ->and(session('discord_access_error'))->toBe('The access bot is not in that Discord server.');

    actingAs($manager)->get(route('maps.settings.access.show', $map))
        ->assertInertia(fn (Assert $page) => $page
            ->where('discordAccess.status', 'pending')
            ->where('discordAccess.error', 'The access bot is not in that Discord server.')
            ->etc());
});

it('does not let one guild bind to a second map', function () {
    configureDiscordAccessOauth();
    $first = Map::factory()->create();
    $second = Map::factory()->create();
    $guildId = '383456789012345678';
    MapDiscordServer::query()->create([
        'map_id' => $first->id,
        'guild_id' => $guildId,
        'guild_name' => 'Example Server',
        'status' => MapDiscordServer::Active,
    ]);
    $manager = discordAccessUser(Permission::Manager, $second);
    $state = startDiscordAccessInvite($manager, $second);
    fakeDiscordAccessGuilds([$guildId => 'Example Server'], []);

    actingAs($manager)->get(route('discord-access.callback', [
        'code' => 'auth-code',
        'state' => $state,
        'guild_id' => $guildId,
    ]))->assertRedirect(route('maps.settings.access.show', $second));

    expect(MapDiscordServer::query()->where('guild_id', $guildId)->count())->toBe(1)
        ->and(MapDiscordServer::query()->where('map_id', $first->id)->first()?->status)->toBe(MapDiscordServer::Active)
        ->and(MapDiscordServer::query()->where('map_id', $second->id)->first()?->guild_id)->toBeNull()
        ->and(MapDiscordServer::query()->where('map_id', $second->id)->first()?->status)->toBe(MapDiscordServer::Pending)
        ->and(session('discord_access_error'))->toBe('That Discord server is already connected to another map.');
});

it('binds only the map whose state matches when another invite is also pending', function () {
    configureDiscordAccessOauth();
    $map = Map::factory()->create();
    $other = Map::factory()->create();
    $manager = discordAccessUser(Permission::Manager, $map);
    discordAccessUser(Permission::Manager, $other);
    MapDiscordServer::query()->create([
        'map_id' => $other->id,
        'status' => MapDiscordServer::Pending,
        'pending_expires_at' => now()->addMinutes(10),
        'pending_state_hash' => DiscordAccessState::hash('other-invite'),
    ]);
    $state = startDiscordAccessInvite($manager, $map);
    $guildId = '393456789012345678';
    fakeDiscordAccessGuilds([$guildId => 'Example Server'], []);

    actingAs($manager)->get(route('discord-access.callback', [
        'code' => 'auth-code',
        'state' => $state,
    ]))->assertRedirect(route('maps.settings.access.show', $map));

    expect(MapDiscordServer::query()->where('map_id', $map->id)->first()?->guild_id)->toBe($guildId)
        ->and(MapDiscordServer::query()->where('map_id', $other->id)->first()?->guild_id)->toBeNull()
        ->and(MapDiscordServer::query()->where('map_id', $other->id)->first()?->status)->toBe(MapDiscordServer::Pending);
});

it('does not let a viewer complete an invite state', function () {
    configureDiscordAccessOauth();
    $map = Map::factory()->create();
    $manager = discordAccessUser(Permission::Manager, $map);
    $viewer = discordAccessUser(Permission::Viewer, $map);
    $state = startDiscordAccessInvite($manager, $map);
    fakeDiscordAccessGuilds(['403456789012345678' => 'Example Server'], []);

    actingAs($viewer)
        ->withSession([DiscordAccessState::SessionKey => $state])
        ->get(route('discord-access.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertForbidden();

    expect(MapDiscordServer::query()->where('map_id', $map->id)->first()?->guild_id)->toBeNull();
});

function configureDiscordAccessOauth(): void
{
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    config()->set('services.discord.access_client_secret', 'test-client-secret');
    config()->set('services.discord.access_redirect', 'https://wormhole.example.com/discord-access/callback');
}

function startDiscordAccessInvite(User $manager, Map $map): string
{
    actingAs($manager)->post(route('maps.settings.access.discord.store', $map))->assertRedirect();
    $state = session(DiscordAccessState::SessionKey);
    expect($state)->toBeString()->not->toBeEmpty();

    return (string) $state;
}

/**
 * @param  array<string, string>  $guildNames
 * @param  list<string>  $memberIds
 */
function fakeDiscordAccessGuilds(array $guildNames, array $memberIds): void
{
    Http::fake(function (Illuminate\Http\Client\Request $request) use ($guildNames, $memberIds) {
        if (str_contains($request->url(), '/oauth2/token')) {
            expect($request->data()['client_secret'] ?? null)->toBe('test-client-secret')
                ->and($request->data()['redirect_uri'] ?? null)->toBe('https://wormhole.example.com/discord-access/callback')
                ->and($request->data()['grant_type'] ?? null)->toBe('authorization_code');
            $guildId = array_key_first($guildNames);

            return Http::response([
                'access_token' => 'discarded-user-token',
                'token_type' => 'Bearer',
                'guild' => [
                    'id' => $guildId,
                    'name' => $guildNames[$guildId] ?? null,
                ],
            ]);
        }

        if (preg_match('#/guilds/(\d+)/members#', $request->url()) === 1) {
            $header = $request->header('Authorization')[0] ?? '';
            expect($header)->toBe('Bot test-token');

            return Http::response(array_map(
                fn (string $id): array => ['user' => ['id' => $id]],
                $memberIds,
            ));
        }

        if (preg_match('#/guilds/(\d+)$#', $request->url(), $match) === 1) {
            $header = $request->header('Authorization')[0] ?? '';
            expect($header)->toBe('Bot test-token');
            $name = $guildNames[$match[1]] ?? null;
            if (! is_string($name)) {
                return Http::response(['message' => 'Unknown Guild'], 404);
            }

            return Http::response(['id' => $match[1], 'name' => $name]);
        }

        return Http::response(['message' => 'unexpected'], 500);
    });
}
