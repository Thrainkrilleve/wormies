<?php

declare(strict_types=1);

use App\DiscordAccess\CreateDiscordAccessLink;
use App\Models\Character;
use App\Models\DiscordAccount;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

it('shows the signed link page for the signed-in user', function () {
    $user = User::factory()->has(Character::factory()->state(['name' => 'Link Pilot']))->create(['name' => 'Link User'])->fresh();
    $url = app(CreateDiscordAccessLink::class)->url('123456789012345678', 'discord-pilot');

    $response = actingAs($user)->get($url);

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('discord/LinkAccount')
            ->where('state', 'confirm')
            ->where('account.name', 'Link User')
            ->where('account.character.name', 'Link Pilot')
            ->where('action', $url)
            ->where('cancelUrl', route('home'))
            ->etc());

    $policy = (string) $response->headers->get('Content-Security-Policy');
    expect($response->getContent())->not->toContain('123456789012345678')
        ->and($policy)->toContain("script-src 'self'")
        ->and($policy)->toContain('nonce-')
        ->and($policy)->not->toContain('unsafe-eval');
});

it('confirms the link and shows a success page', function () {
    $user = User::factory()->has(Character::factory())->create(['name' => 'Linked User'])->fresh();
    $url = app(CreateDiscordAccessLink::class)->url('123456789012345679', 'discord-pilot');

    actingAs($user)->post($url)->assertRedirect(route('discord-access.linked'));

    actingAs($user)->get(route('discord-access.linked'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('discord/LinkAccount')
            ->where('state', 'success')
            ->where('account.name', 'Linked User')
            ->where('action', null)
            ->etc());

    expect(DiscordAccount::query()->where('user_id', $user->id)->value('discord_user_id'))->toBe('123456789012345679');
});

it('shows a styled page when the link is expired, invalid, or already used', function () {
    $user = User::factory()->has(Character::factory())->create()->fresh();
    $url = app(CreateDiscordAccessLink::class)->url('123456789012345680', 'discord-pilot');

    $expired = URL::temporarySignedRoute('discord-access.link', now()->subMinute(), ['nonce' => 'gone']);
    actingAs($user)->get($expired)
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('discord/LinkAccount')
            ->where('state', 'expired')
            ->etc());

    actingAs($user)->get(route('discord-access.link', ['nonce' => 'not-signed']))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'invalid')
            ->etc());

    actingAs($user)->post($url)->assertRedirect(route('discord-access.linked'));
    actingAs($user)->post($url)
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'invalid')
            ->etc());
});

it('shows a styled conflict when the discord account belongs to someone else', function () {
    $owner = User::factory()->has(Character::factory())->create()->fresh();
    DiscordAccount::factory()->for($owner)->create(['discord_user_id' => '223456789012345678']);
    $user = User::factory()->has(Character::factory())->create()->fresh();
    $url = app(CreateDiscordAccessLink::class)->url('223456789012345678', 'pilot');

    actingAs($user)->post($url)
        ->assertStatus(409)
        ->assertInertia(fn (Assert $page) => $page
            ->component('discord/LinkAccount')
            ->where('state', 'taken')
            ->where('account.name', $user->name)
            ->etc());

    expect($user->fresh()->discordAccount)->toBeNull();
});
