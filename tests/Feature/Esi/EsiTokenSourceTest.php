<?php

declare(strict_types=1);

use App\Enums\EsiTokenSource;
use App\Models\Character;
use App\Models\EsiScope;
use App\Models\EsiToken;
use App\Models\User;
use App\Services\AllianceAuthService;
use App\Services\Esi\CharacterEsiClient;
use App\Services\Esi\RepairAllianceAuthEsiTokens;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use NicolasKion\Esi\DTO\EsiError;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\Enums\EsiScope as EsiScopeName;
use NicolasKion\Esi\Esi;

function esiClientConfig(): void
{
    config()->set('esi.client_id', 'wormies-client');
    config()->set('esi.client_secret', 'wormies-secret');
    config()->set('esi.user_agent', 'wormies-test');
    config()->set('services.allianceauth.base_url', 'https://auth.example.com');
    config()->set('services.allianceauth.internal_url', '');
    config()->set('services.allianceauth.client_id', 'aa-client');
    config()->set('services.allianceauth.client_secret', 'aa-secret');
}

function silenceEsi(): void
{
    $esi = Mockery::mock(Esi::class);
    $esi->shouldReceive('getOnline')->andReturn(EsiResult::fromError(new EsiError(204, 'unused')));
    app()->instance(Esi::class, $esi);
}

function captureLogs(): TestHandler
{
    $handler = new TestHandler();
    Log::getLogger()->pushHandler($handler);

    return $handler;
}

function expectLogsOmit(TestHandler $handler, string $secret): void
{
    foreach ($handler->getRecords() as $record) {
        expect(json_encode($record))->not->toContain($secret);
    }
}

function expiredToken(Character $character, array $overrides = []): EsiToken
{
    return $character->esiTokens()->create(array_merge([
        'access_token' => 'stale-access',
        'refresh_token' => 'stale-refresh',
        'token_type' => 'Bearer',
        'source' => EsiTokenSource::EveSso,
        'oauth_client_id' => 'wormies-client',
        'character_owner_hash' => 'owner-hash',
        'expires_at' => now()->subHour(),
    ], $overrides));
}

it('refreshes an eve sso token with the wormies client and deletes it on invalid_grant', function () {
    esiClientConfig();
    $logs = captureLogs();

    $character = Character::factory()->create();
    $token = expiredToken($character);
    silenceEsi();

    Http::fake([
        'https://login.eveonline.com/v2/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    app(CharacterEsiClient::class)->online($character);

    expect(EsiToken::query()->find($token->id))->toBeNull();

    Http::assertSent(function ($request): bool {
        $expected = 'Basic '.base64_encode('wormies-client:wormies-secret');

        return $request->url() === 'https://login.eveonline.com/v2/oauth/token'
            && $request->hasHeader('Authorization', $expected)
            && $request['grant_type'] === 'refresh_token';
    });

    expectLogsOmit($logs, 'stale-refresh');
});

it('keeps an eve sso token when invalid_grant comes from a different client', function () {
    esiClientConfig();

    $character = Character::factory()->create();
    $token = expiredToken($character, ['oauth_client_id' => 'some-other-client']);
    silenceEsi();

    Http::fake([
        'https://login.eveonline.com/v2/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    app(CharacterEsiClient::class)->online($character);

    expect(EsiToken::query()->find($token->id))->not->toBeNull()
        ->and($token->fresh()->getRefreshToken())->toBe('stale-refresh');
});

it('refreshes an alliance auth token from alliance auth and never from eve', function () {
    esiClientConfig();
    $logs = captureLogs();

    $character = Character::factory()->create();
    $token = expiredToken($character, [
        'token_type' => 'character',
        'source' => EsiTokenSource::AllianceAuth,
        'oauth_client_id' => null,
    ]);
    silenceEsi();

    EsiScope::query()->firstOrCreate(
        ['name' => EsiScopeName::ReadOnlineStatus->value],
        ['is_default' => false],
    );

    expect($token->isExpired())->toBeFalse()
        ->and($token->needsRefresh())->toBeTrue();

    Http::fake([
        'https://login.eveonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        'https://auth.example.com/wormholesystems/api/character-token/' => Http::response([
            'access_token' => 'fresh-aa-access',
            'expires_in' => 900,
            'scopes' => [EsiScopeName::ReadOnlineStatus->value],
        ]),
    ]);

    app(CharacterEsiClient::class)->online($character);

    $fresh = $token->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->getAccessToken())->toBe('fresh-aa-access')
        ->and($fresh->getRefreshToken())->toBe('stale-refresh')
        ->and($fresh->reauth_required)->toBeFalse()
        ->and($fresh->source)->toBe(EsiTokenSource::AllianceAuth)
        ->and($fresh->esiScopes()->pluck('name')->all())->toBe([EsiScopeName::ReadOnlineStatus->value]);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'login.eveonline.com'));
    Http::assertSent(function ($request) use ($character): bool {
        $header = $request->header('Authorization');
        $authorization = is_array($header) ? implode(' ', $header) : (string) $header;

        return str_contains($request->url(), '/wormholesystems/api/character-token/')
            && str_contains($authorization, base64_encode('aa-client:aa-secret'))
            && (int) ($request->data()['character_id'] ?? 0) === (int) $character->id;
    });

    expectLogsOmit($logs, 'stale-refresh');
    expectLogsOmit($logs, 'fresh-aa-access');
});

it('marks an alliance auth token for a direct eve login when alliance auth has no token route', function () {
    esiClientConfig();
    $logs = captureLogs();

    $user = User::factory()->create()->fresh();
    $character = Character::factory()->for($user)->create();
    $user->update(['preferred_character_id' => $character->id]);
    $user = $user->fresh();
    $token = expiredToken($character, [
        'source' => EsiTokenSource::AllianceAuth,
        'oauth_client_id' => null,
    ]);
    $scope = EsiScope::query()->firstOrCreate(
        ['name' => EsiScopeName::ReadOnlineStatus->value],
        ['is_default' => false],
    );
    $token->esiScopes()->sync([$scope->id]);
    foreach ([EsiScopeName::ReadShip, EsiScopeName::ReadLocations] as $name) {
        $id = EsiScope::query()->firstOrCreate(['name' => $name->value], ['is_default' => false])->id;
        $token->esiScopes()->attach($id);
    }
    silenceEsi();

    Http::fake([
        'https://login.eveonline.com/*' => Http::response(['error' => 'invalid_grant', 'refresh_token' => 'do-not-log'], 400),
        'https://auth.example.com/*' => Http::response('missing', 404),
    ]);

    app(CharacterEsiClient::class)->online($character);

    expect(EsiToken::query()->find($token->id))->not->toBeNull()
        ->and($token->fresh()->reauth_required)->toBeTrue()
        ->and($character->fresh()->needsDirectSso())->toBeTrue();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'login.eveonline.com'));

    expectLogsOmit($logs, 'do-not-log');
    expectLogsOmit($logs, 'stale-refresh');

    $this->actingAs($user)
        ->get(route('settings.show'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('characters.0.needs_direct_sso', true)
            ->where('missing_scopes.0.id', fn ($id): bool => (int) $id === (int) $character->id));
});

it('marks existing character-typed tokens for the alliance auth refresh path', function () {
    $character = Character::factory()->create();
    $copied = expiredToken($character, [
        'token_type' => 'character',
        'source' => EsiTokenSource::EveSso,
        'expires_at' => now()->addHour(),
    ]);
    $direct = expiredToken($character, [
        'token_type' => 'Bearer',
        'source' => EsiTokenSource::EveSso,
        'expires_at' => now()->addHour(),
    ]);

    expect(RepairAllianceAuthEsiTokens::markCharacterTypedTokens())->toBe(1)
        ->and($copied->fresh()->source)->toBe(EsiTokenSource::AllianceAuth)
        ->and($direct->fresh()->source)->toBe(EsiTokenSource::EveSso);
});

it('asks alliance auth for a character token without sending the refresh token', function () {
    esiClientConfig();

    Http::fake([
        'https://auth.example.com/wormholesystems/api/character-token/' => Http::response([
            'access_token' => 'from-aa',
            'expires_in' => 600,
            'scopes' => [],
        ]),
    ]);

    $fresh = app(AllianceAuthService::class)->freshCharacterAccessToken(12345);

    expect($fresh)->toBe([
        'access_token' => 'from-aa',
        'expires_in' => 600,
        'scopes' => [],
    ]);

    Http::assertSent(fn ($request): bool => $request['character_id'] === 12345
        && ! array_key_exists('refresh_token', $request->data()));
});
