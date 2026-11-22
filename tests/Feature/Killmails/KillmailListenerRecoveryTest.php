<?php

declare(strict_types=1);

use App\Enums\MapAlertType;
use App\Enums\zKillboardCacheKey;
use App\Jobs\MapAlerts\EvaluateKillmailAlertsJob;
use App\Models\Killmail;
use App\Models\MapAlert;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

function killmailListenerConfig(): void
{
    config([
        'services.zkillboard.poll_delay_ms' => 0,
        'services.zkillboard.catchup_delay_ms' => 0,
        'services.zkillboard.retry_attempts' => 1,
        'services.zkillboard.retry_delay_ms' => 0,
        'services.zkillboard.max_age_days' => 3,
        'services.zkillboard.r2z2_base_url' => 'https://r2z2.example.com',
    ]);
}

it('exits non-zero when the latest sequence cannot be fetched and nothing is cached', function () {
    killmailListenerConfig();
    Cache::put(zKillboardCacheKey::Restart->value, 'start');
    Http::fake([
        'https://r2z2.example.com/*' => Http::response('down', 500),
    ]);

    artisan('app:listen-for-killmails')->assertFailed();

    expect(Cache::get(zKillboardCacheKey::Sequence->value))->toBeNull();
});

it('resumes from the cached sequence instead of asking for the latest id', function () {
    killmailListenerConfig();
    Cache::put(zKillboardCacheKey::Restart->value, 'start');
    Cache::forever(zKillboardCacheKey::Sequence->value, 42);
    Http::fake(function () {
        Cache::put(zKillboardCacheKey::Restart->value, 'stop');

        return Http::response([], 404);
    });

    artisan('app:listen-for-killmails')->assertSuccessful();

    expect(Cache::get(zKillboardCacheKey::Sequence->value))->toBe(42);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sequence.json'));
});

it('keeps the same sequence when a fetch fails', function () {
    killmailListenerConfig();
    Cache::put(zKillboardCacheKey::Restart->value, 'start');
    Cache::forever(zKillboardCacheKey::Sequence->value, 77);
    Http::fake(function () {
        Cache::put(zKillboardCacheKey::Restart->value, 'stop');

        return Http::response('timeout', 500);
    });

    artisan('app:listen-for-killmails')->assertSuccessful();

    expect(Cache::get(zKillboardCacheKey::Sequence->value))->toBe(77);
});

it('does not queue an alert job when no killmail alert exists', function () {
    killmailListenerConfig();
    Bus::fake();
    $systemId = makeSolarsystem(30000111);
    Cache::put(zKillboardCacheKey::Restart->value, 'start');
    Cache::forever(zKillboardCacheKey::Sequence->value, 9);
    Http::fake(function () use ($systemId) {
        Cache::put(zKillboardCacheKey::Restart->value, 'stop');

        return Http::response([
            'killmail_id' => 9001,
            'hash' => 'hash',
            'sequence_id' => 9,
            'esi' => [
                'solar_system_id' => $systemId,
                'killmail_time' => now()->toIso8601String(),
                'victim' => [],
            ],
            'zkb' => [],
        ], 200);
    });

    artisan('app:listen-for-killmails')->assertSuccessful();

    expect(Killmail::query()->whereKey(9001)->exists())->toBeTrue()
        ->and(Cache::get(zKillboardCacheKey::Sequence->value))->toBe(10);

    Bus::assertNotDispatched(EvaluateKillmailAlertsJob::class);
});

it('queues an alert job when a killmail alert is active', function () {
    killmailListenerConfig();
    Bus::fake();
    $systemId = makeSolarsystem(30000112);
    MapAlert::factory()->create([
        'type' => MapAlertType::Killmail,
        'is_active' => true,
        'target_solarsystem_id' => $systemId,
    ]);
    Cache::put(zKillboardCacheKey::Restart->value, 'start');
    Cache::forever(zKillboardCacheKey::Sequence->value, 11);
    Http::fake(function () use ($systemId) {
        Cache::put(zKillboardCacheKey::Restart->value, 'stop');

        return Http::response([
            'killmail_id' => 9002,
            'hash' => 'hash',
            'sequence_id' => 11,
            'esi' => [
                'solar_system_id' => $systemId,
                'killmail_time' => now()->toIso8601String(),
                'victim' => [],
            ],
            'zkb' => [],
        ], 200);
    });

    artisan('app:listen-for-killmails')->assertSuccessful();

    Bus::assertDispatched(EvaluateKillmailAlertsJob::class);
});

it('deletes expired killmails in batches', function () {
    config(['services.zkillboard.max_age_days' => 3]);
    $systemId = makeSolarsystem(30000113);

    Killmail::query()->create([
        'id' => 1,
        'hash' => 'old',
        'solarsystem_id' => $systemId,
        'time' => now()->subDays(10),
        'data' => [],
        'zkb' => [],
    ]);
    Killmail::query()->create([
        'id' => 2,
        'hash' => 'new',
        'solarsystem_id' => $systemId,
        'time' => now()->subDay(),
        'data' => [],
        'zkb' => [],
    ]);

    artisan('app:purge-old-killmails')->assertSuccessful();

    expect(Killmail::query()->pluck('id')->all())->toBe([2]);
});
