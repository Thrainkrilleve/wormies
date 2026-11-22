<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\TrustedProxies;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapWebhook;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

it('accepts a share token from a header without putting it in the url', function () {
    $token = (string) Str::uuid();
    $map = Map::factory()->create(['is_public' => false, 'share_token' => $token]);
    User::factory()->ownsMap($map)->create();

    $this->withHeader('X-Map-Share-Token', $token)
        ->get(route('maps.show', $map))
        ->assertSuccessful();
});

it('encrypts a webhook url that was stored before the cast', function () {
    $url = 'https://discord.com/api/webhooks/100/secret-token';
    $webhook = MapWebhook::factory()->create(['discord_webhook_url' => $url]);

    DB::table('map_webhooks')->where('id', $webhook->id)->update([
        'discord_webhook_url' => $url,
    ]);

    $migration = require database_path('migrations/2026_11_22_170000_encrypt_existing_webhook_urls.php');
    $migration->up();
    $migration->up();

    $raw = DB::table('map_webhooks')->where('id', $webhook->id)->value('discord_webhook_url');

    expect($raw)->not->toBe($url)
        ->and($webhook->fresh()->discord_webhook_url)->toBe($url);
});

it('lets a read token list maps and refuses a write', function () {
    $user = User::factory()->create();
    $character = Character::factory()->for($user)->create();
    $map = Map::factory()->create(['name' => 'Home']);
    User::factory()->ownsMap($map)->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_type' => Character::class,
        'accessible_id' => $character->id,
        'permission' => Permission::Member,
        'is_owner' => false,
    ]);

    $read = $user->createToken('reader', ['maps:read'])->plainTextToken;

    $this->withToken($read)->getJson('/api/maps')->assertOk();
    $this->withToken($read)->patchJson("/api/maps/{$map->id}", ['name' => 'Renamed'])->assertForbidden();
    expect($map->fresh()->name)->toBe('Home');
});

it('lets a legacy wildcard token change a map', function () {
    $user = User::factory()->create();
    $character = Character::factory()->for($user)->create();
    $map = Map::factory()->create(['name' => 'Home']);
    User::factory()->ownsMap($map)->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_type' => Character::class,
        'accessible_id' => $character->id,
        'permission' => Permission::Member,
        'is_owner' => false,
    ]);

    $legacy = $user->createToken('legacy', ['*'])->plainTextToken;

    $this->withToken($legacy)
        ->patchJson("/api/maps/{$map->id}", ['name' => 'Renamed'])
        ->assertOk();

    expect($map->fresh()->name)->toBe('Renamed');
});

it('requires an ability when a person creates a token', function () {
    $user = User::factory()->has(Character::factory())->create();

    actingAs($user)->post(route('tokens.store'), ['name' => 'Client'])
        ->assertSessionHasErrors('abilities');

    actingAs($user)->post(route('tokens.store'), [
        'name' => 'Client',
        'abilities' => ['maps:read'],
    ])->assertRedirect();

    expect($user->tokens()->first()->abilities)->toBe(['maps:read']);
});

it('ignores forwarded client addresses that do not come from a trusted proxy', function () {
    TrustProxies::at(['10.0.0.0/8']);

    try {
        $untrusted = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        ]);
        app(TrustProxies::class)->handle($untrusted, fn (Request $request) => response($request->ip()));

        expect($untrusted->ip())->toBe('203.0.113.5');

        TrustProxies::at(['203.0.113.5']);
        $trusted = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        ]);
        app(TrustProxies::class)->handle($trusted, fn (Request $request) => response($request->ip()));

        expect($trusted->ip())->toBe('198.51.100.10');
    } finally {
        TrustProxies::at(TrustedProxies::fromEnv(getenv('TRUSTED_PROXIES') ?: null));
    }
});
