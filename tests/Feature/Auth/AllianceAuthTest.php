<?php

declare(strict_types=1);

use App\Exception\CharacterNotAuthorizedException;
use App\Models\Character;
use App\Models\User;
use App\Services\AllianceAuthService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use NicolasKion\Esi\DTO\CharacterAffiliation;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\Esi;

beforeEach(function () {
    Bus::fake();

    config()->set('services.allianceauth.base_url', 'https://auth.r3v-w.space');
    config()->set('services.allianceauth.client_id', 'test-client-id');
    config()->set('services.allianceauth.client_secret', 'test-client-secret');
    config()->set('services.allianceauth.redirect', 'https://wormhole.r3v-w.space/auth/allianceauth/callback');
    config()->set('access.allowed_affiliation_ids', []);
});

function fakeAllianceAuthEsi(int $characterId = 8888, int $corporationId = 2000, ?int $allianceId = 3000): void
{
    $esi = Mockery::mock(Esi::class);
    $esi->shouldReceive('getAffiliations')->andReturn(new EsiResult(
        data: [new CharacterAffiliation($characterId, $corporationId, $allianceId, null)],
    ));
    app()->instance(Esi::class, $esi);
}

it('redirects to the Alliance Auth authorization URL with valid state', function () {
    $response = $this->get(route('allianceauth.redirect'));

    $response->assertRedirect();
    $targetUrl = $response->headers->get('Location');

    expect($targetUrl)->toContain('https://auth.r3v-w.space/o/authorize/?')
        ->and($targetUrl)->toContain('client_id=test-client-id')
        ->and($targetUrl)->toContain('response_type=code')
        ->and(session('allianceauth_state'))->not()->toBeEmpty();
});

it('rejects callback with invalid or mismatched state', function () {
    session(['allianceauth_state' => 'correct-state']);

    $response = $this->get(route('allianceauth.callback', [
        'code' => 'valid-code',
        'state' => 'wrong-state',
    ]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('auth');
    $this->assertGuest();
});

it('logs in user via Alliance Auth callback when authorized', function () {
    session(['allianceauth_state' => 'test-state']);

    Http::fake([
        'https://auth.r3v-w.space/o/token/' => Http::response([
            'access_token' => 'mocked-aa-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
        'https://auth.r3v-w.space/o/userinfo/' => Http::response([
            'sub' => '1001',
            'character_id' => 8888,
            'name' => 'Alliance Pilot',
            'email' => 'pilot@example.com',
            'groups' => ['Member', 'Wormhole Ops'],
        ]),
    ]);

    fakeAllianceAuthEsi(characterId: 8888, corporationId: 2000, allianceId: 3000);

    $response = $this->get(route('allianceauth.callback', [
        'code' => 'test-code',
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('home'));
    $this->assertAuthenticated();

    $user = auth()->user();
    expect($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('Alliance Pilot')
        ->and(Character::query()->find(8888))->not()->toBeNull();
});

it('blocks login if user lacks the required Alliance Auth group', function () {
    config()->set('services.allianceauth.required_groups', 'Wormhole Ops, Vanguard');
    session(['allianceauth_state' => 'test-state']);

    Http::fake([
        'https://auth.r3v-w.space/o/token/' => Http::response([
            'access_token' => 'mocked-aa-access-token',
            'token_type' => 'Bearer',
        ]),
        'https://auth.r3v-w.space/o/userinfo/' => Http::response([
            'sub' => '1001',
            'character_id' => 8888,
            'name' => 'Unauthorized Pilot',
            'groups' => ['Member', 'Miner'], // Missing Wormhole Ops or Vanguard
        ]),
    ]);

    fakeAllianceAuthEsi(characterId: 8888);

    $response = $this->get(route('allianceauth.callback', [
        'code' => 'test-code',
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('auth');
    $this->assertGuest();
});

it('permits login if user has one of the required Alliance Auth groups', function () {
    config()->set('services.allianceauth.required_groups', 'Wormhole Ops, Vanguard');
    session(['allianceauth_state' => 'test-state']);

    Http::fake([
        'https://auth.r3v-w.space/o/token/' => Http::response([
            'access_token' => 'mocked-aa-access-token',
            'token_type' => 'Bearer',
        ]),
        'https://auth.r3v-w.space/o/userinfo/' => Http::response([
            'sub' => '1001',
            'character_id' => 8888,
            'name' => 'Authorized Pilot',
            'groups' => ['Member', 'Vanguard'],
        ]),
    ]);

    fakeAllianceAuthEsi(characterId: 8888);

    $response = $this->get(route('allianceauth.callback', [
        'code' => 'test-code',
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('home'));
    $this->assertAuthenticated();
});
