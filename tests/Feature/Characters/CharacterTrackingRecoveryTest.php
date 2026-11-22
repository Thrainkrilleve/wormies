<?php

declare(strict_types=1);

use App\Jobs\Characters\UpdateCharacterLocation;
use App\Jobs\Characters\UpdateCharacterOnlineStatus;
use App\Models\Category;
use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\EsiScope;
use App\Models\EsiToken;
use App\Models\Group;
use App\Models\Type;
use App\Models\User;
use App\Services\Esi\CharacterEsiClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\DTO\Location;
use NicolasKion\Esi\DTO\Ship;
use NicolasKion\Esi\Enums\EsiScope as EsiScopeName;
use NicolasKion\Esi\Esi;

use function Pest\Laravel\artisan;

function characterWithTrackingScopes(array $status = [], array $token = []): array
{
    $user = User::factory()->create(['last_active_at' => now()->subHours(3)]);
    $character = Character::factory()->for($user)->create();
    $esiToken = $character->esiTokens()->create(array_merge([
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_type' => 'Bearer',
        'character_owner_hash' => 'owner-hash',
        'expires_at' => now()->addHour(),
    ], $token));

    $scopeIds = collect([
        EsiScopeName::ReadLocations,
        EsiScopeName::ReadOnlineStatus,
        EsiScopeName::ReadShip,
    ])->map(fn (EsiScopeName $scope) => EsiScope::query()->firstOrCreate(
        ['name' => $scope->value],
        ['is_default' => false],
    )->id);

    $esiToken->esiScopes()->sync($scopeIds);

    $characterStatus = CharacterStatus::query()->create(array_merge([
        'character_id' => $character->id,
        'is_online' => true,
        'solarsystem_id' => null,
    ], $status));

    return [$user, $character, $esiToken, $characterStatus];
}

it('keeps polling an online character after the website user goes idle', function () {
    Bus::fake();
    [, , , $status] = characterWithTrackingScopes();

    artisan('app:get-online-characters')->assertSuccessful();

    expect((bool) $status->fresh()->is_online)->toBeTrue();

    Bus::assertBatched(fn ($batch) => collect($batch->jobs)->contains(
        fn ($job) => $job instanceof UpdateCharacterOnlineStatus && $job->character_status_id === $status->id
    ));
});

it('does not delete an ESI token when ESI returns unauthorized', function () {
    Http::fake([
        'esi.evetech.net/*' => Http::response('token expired', 401),
    ]);

    [, $character, $token] = characterWithTrackingScopes();

    app(CharacterEsiClient::class)->location($character);

    expect(EsiToken::query()->find($token->id))->not->toBeNull();
});

it('deletes an ESI token only when the refresh token is revoked', function () {
    Http::fake([
        'login.eveonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    [, $character, $token] = characterWithTrackingScopes([], [
        'expires_at' => now()->subHour(),
    ]);

    app(CharacterEsiClient::class)->online($character);

    expect(EsiToken::query()->find($token->id))->toBeNull();
});

it('stores ESI tokens encrypted at rest', function () {
    [, , $token] = characterWithTrackingScopes();

    $raw = DB::table('esi_tokens')->where('id', $token->id)->value('access_token');

    expect($raw)->not->toBe('access-token')
        ->and($token->fresh()->getAccessToken())->toBe('access-token');
});

it('does not queue another broadcast while the heartbeat is still fresh', function () {
    $systemId = makeSolarsystem(31000221);
    Category::query()->firstOrCreate(['id' => 6], ['name' => 'Ship']);
    Group::query()->firstOrCreate(['id' => 27], ['name' => 'Battleship', 'category_id' => 6]);
    Type::query()->firstOrCreate(['id' => 73791], ['name' => 'Nestor', 'group_id' => 27, 'mass' => 130_000_000]);

    $character = Character::factory()->create();
    $status = CharacterStatus::query()->create([
        'character_id' => $character->id,
        'solarsystem_id' => $systemId,
        'is_online' => true,
        'ship_type_id' => 73791,
        'ship_item_id' => 9001,
        'ship_name' => 'Blackbetty',
    ]);

    $esi = Mockery::mock(Esi::class);
    $esi->shouldReceive('getLocation')->andReturn(new EsiResult(data: new Location(solar_system_id: $systemId)));
    $esi->shouldReceive('getShip')->andReturn(new EsiResult(data: new Ship(
        ship_type_id: 73791,
        ship_item_id: 9001,
        ship_name: 'Blackbetty',
    )));
    app()->instance(Esi::class, $esi);

    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->fresh()->event_queued_at)->not->toBeNull();

    $status->update(['event_queued_at' => null]);
    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->fresh()->event_queued_at)->toBeNull()
        ->and($status->fresh()->location_last_checked_at)->not->toBeNull();
});
