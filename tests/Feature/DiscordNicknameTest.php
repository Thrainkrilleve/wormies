<?php

declare(strict_types=1);

use App\DiscordAccess\DiscordAccessBot;
use App\DiscordAccess\DiscordAccessNickname;
use App\DiscordAccess\DiscordAccessSync;
use App\DiscordAccess\DiscordNicknameSync;
use App\Models\Alliance;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapDiscordAccessAudit;
use App\Models\MapDiscordServer;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

it('formats a discord nickname and keeps tickers inside 32 characters', function () {
    expect(DiscordAccessNickname::format('EXA', 'EXC', 'Visible Pilot'))->toBe('[EXA] [EXC] Visible Pilot')
        ->and(DiscordAccessNickname::format(null, 'EXC', 'Visible Pilot'))->toBe('[EXC] Visible Pilot')
        ->and(DiscordAccessNickname::format('EXA', null, 'Visible Pilot'))->toBe('[EXA] Visible Pilot')
        ->and(DiscordAccessNickname::format(null, null, 'Visible Pilot'))->toBe('Visible Pilot')
        ->and(DiscordAccessNickname::format('', '  ', 'Visible Pilot'))->toBe('Visible Pilot');

    $nick = DiscordAccessNickname::format('ALLIA', 'CORPX', str_repeat('N', 40));

    expect(mb_strlen($nick))->toBe(32)
        ->and($nick)->toStartWith('[ALLIA] [CORPX] ')
        ->and($nick)->toEndWith(str_repeat('N', 32 - mb_strlen('[ALLIA] [CORPX] ')));
});

it('sets a nickname from the preferred character and skips an unchanged nick', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $map = Map::factory()->create();
    $alliance = Alliance::factory()->create(['ticker' => 'EXA']);
    $corporation = Corporation::factory()->create(['ticker' => 'EXC', 'alliance_id' => $alliance->id]);
    $user = User::factory()->create();
    Character::factory()->for($user)->create([
        'name' => 'Other Pilot',
        'corporation_id' => $corporation->id,
        'alliance_id' => $alliance->id,
    ]);
    $main = Character::factory()->for($user)->create([
        'name' => 'Main Pilot',
        'corporation_id' => $corporation->id,
        'alliance_id' => $alliance->id,
    ]);
    $user->forceFill(['preferred_character_id' => $main->id])->save();
    $discordUserId = '510000000000000711';
    DiscordAccount::factory()->for($user)->create(['discord_user_id' => $discordUserId, 'username' => 'nick-user']);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000710',
        'status' => MapDiscordServer::Active,
        'permission' => 'member',
        'nickname_sync' => true,
    ]);

    $patched = null;
    Http::fake(function (Illuminate\Http\Client\Request $request) use (&$patched, $discordUserId) {
        if ($request->method() === 'GET') {
            return Http::response(['nick' => null, 'user' => ['id' => $discordUserId]]);
        }
        $patched = $request->data()['nick'] ?? null;

        return Http::response(null, 200);
    });

    app(DiscordNicknameSync::class)->sync($discordUserId);

    expect($patched)->toBe('[EXA] [EXC] Main Pilot')
        ->and(MapDiscordAccessAudit::query()->where('action', MapDiscordAccessAudit::Nickname)->where('reason', 'set')->exists())->toBeTrue();
});

it('does not patch when the nickname already matches', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $discordUserId = '510000000000000712';
    Http::fake(fn () => Http::response(['nick' => '[EXC] Pilot', 'user' => ['id' => $discordUserId]]));

    expect(app(DiscordAccessBot::class)->changeNickname('510000000000000713', $discordUserId, '[EXC] Pilot'))->toBe('unchanged');

    Http::assertNotSent(fn (Illuminate\Http\Client\Request $request): bool => $request->method() === 'PATCH');
});

it('does not patch discord when nickname sync is off', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $map = Map::factory()->create();
    $user = User::factory()->has(Character::factory(['name' => 'Quiet Pilot']))->create();
    DiscordAccount::factory()->for($user)->create(['discord_user_id' => '510000000000000721', 'username' => 'quiet']);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000720',
        'status' => MapDiscordServer::Active,
        'permission' => 'member',
        'nickname_sync' => false,
    ]);
    Http::fake();

    app(DiscordNicknameSync::class)->sync('510000000000000721');

    Http::assertNothingSent();
});

it('keeps linking when discord refuses a nickname', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $map = Map::factory()->create();
    $user = User::factory()->has(Character::factory(['name' => 'Blocked Pilot']))->create();
    $discordUserId = '510000000000000731';
    DiscordAccount::factory()->for($user)->create(['discord_user_id' => $discordUserId, 'username' => 'blocked']);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000730',
        'status' => MapDiscordServer::Active,
        'permission' => 'member',
        'nickname_sync' => true,
    ]);
    Http::fake(function (Illuminate\Http\Client\Request $request) use ($discordUserId) {
        if ($request->method() === 'GET') {
            return Http::response(['nick' => null, 'user' => ['id' => $discordUserId]]);
        }

        return Http::response(['message' => 'Missing Permissions', 'code' => 50013], 403);
    });

    expect(fn () => app(DiscordNicknameSync::class)->sync($discordUserId))->not->toThrow(Throwable::class)
        ->and(DiscordAccount::query()->where('discord_user_id', $discordUserId)->exists())->toBeTrue()
        ->and(MapDiscordAccessAudit::query()->where('action', MapDiscordAccessAudit::Nickname)->exists())->toBeFalse();
});

it('clears the discord nickname before unlink deletes the account', function () {
    config()->set('services.discord.access_bot_token', 'test-token');
    config()->set('services.discord.access_application_id', '100000000000000001');
    $map = Map::factory()->create();
    $user = User::factory()->has(Character::factory(['name' => 'Leaving Pilot']))->create();
    $discordUserId = '510000000000000741';
    DiscordAccount::factory()->for($user)->create(['discord_user_id' => $discordUserId, 'username' => 'leaving']);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000740',
        'status' => MapDiscordServer::Active,
        'permission' => 'member',
        'nickname_sync' => true,
    ]);
    $cleared = false;
    Http::fake(function (Illuminate\Http\Client\Request $request) use (&$cleared, $discordUserId) {
        if ($request->method() === 'GET') {
            return Http::response(['nick' => '[EXC] Leaving Pilot', 'user' => ['id' => $discordUserId]]);
        }
        $cleared = array_key_exists('nick', $request->data()) && $request->data()['nick'] === null;

        return Http::response(null, 200);
    });

    expect(app(DiscordAccessSync::class)->unlinkInvokedUser($discordUserId))
        ->toBe('This Discord account is no longer linked. Derived map access was removed.')
        ->and($cleared)->toBeTrue()
        ->and(DiscordAccount::query()->where('discord_user_id', $discordUserId)->exists())->toBeFalse()
        ->and(MapDiscordAccessAudit::query()->where('action', MapDiscordAccessAudit::Nickname)->where('reason', 'reset')->exists())->toBeTrue();
});

it('lets a manager turn nickname sync off', function () {
    $map = Map::factory()->create();
    $manager = User::factory()->create();
    $character = Character::factory()->for($manager)->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_id' => $character->id,
        'accessible_type' => Character::class,
        'permission' => 'manager',
        'is_owner' => true,
    ]);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000750',
        'status' => MapDiscordServer::Active,
        'permission' => 'member',
        'nickname_sync' => true,
    ]);

    actingAs($manager->fresh())->post(route('maps.settings.access.discord.nicknames', $map), [
        'nickname_sync' => false,
    ])->assertRedirect();

    expect(MapDiscordServer::query()->where('map_id', $map->id)->first()?->nickname_sync)->toBeFalse();
});

it('advertises the manage nicknames permission on the invite', function () {
    config()->set('services.discord.access_application_id', '100000000000000001');
    config()->set('services.discord.access_redirect', 'https://wormhole.example.com/discord-access/callback');

    $invite = app(DiscordAccessBot::class)->inviteUrl('state');

    expect($invite)->toContain('permissions='.DiscordAccessBot::ManageNicknames);
});
