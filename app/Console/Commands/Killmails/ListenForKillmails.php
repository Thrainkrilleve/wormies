<?php

declare(strict_types=1);

namespace App\Console\Commands\Killmails;

use App\Actions\EnsureOrganisationExistsAction;
use App\Console\Commands\AppCommand;
use App\Enums\MapAlertType;
use App\Enums\zKillboardCacheKey;
use App\Events\Killmails\KillmailReceivedEvent;
use App\Http\Integrations\zKillboard\DTO\R2Z2Killmail;
use App\Http\Integrations\zKillboard\zKillboard;
use App\Jobs\MapAlerts\EvaluateKillmailAlertsJob;
use App\Models\Killmail;
use App\Models\Map;
use App\Models\MapAlert;
use App\Models\Solarsystem;
use App\Models\Type;
use Date;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

use function sprintf;
use function usleep;

final class ListenForKillmails extends AppCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:listen-for-killmails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to zKillboard R2Z2 stream for killmails and process them.';

    private bool $should_keep_running = true;

    private int $failure_streak = 0;

    public function __construct(
        private readonly zKillboard $zKillboard,
        private readonly EnsureOrganisationExistsAction $ensureOrganisationExistsAction,
        #[Config('services.zkillboard.max_age_days')] private readonly int $max_age_days,
        #[Config('services.zkillboard.poll_delay_ms')] private readonly int $poll_delay_ms,
        #[Config('services.zkillboard.catchup_delay_ms')] private readonly int $catchup_delay_ms,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->trap([SIGTERM, SIGQUIT], $this->handleStop(...));

        $restartSignal = Cache::get(zKillboardCacheKey::Restart->value);

        try {
            $sequence = $this->resolveStartingSequence();
        } catch (Throwable $exception) {
            $this->error(sprintf('Could not determine a starting R2Z2 sequence: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf('Starting R2Z2 polling from sequence %d', $sequence));

        while ($this->should_keep_running) {
            if ($this->shouldRestart($restartSignal)) {
                $this->info('Restart signal detected, exiting.');

                return self::SUCCESS;
            }

            $this->rememberSequence($sequence);

            try {
                $killmail = $this->zKillboard->getKillmailBySequence($sequence);
            } catch (Throwable $exception) {
                $this->failure_streak++;
                $this->error(sprintf('Error fetching sequence %d: %s', $sequence, $exception->getMessage()));
                $this->pause(failed: true);

                continue;
            }

            if (! $killmail instanceof R2Z2Killmail) {
                $this->failure_streak = 0;
                $this->pause(failed: false, caughtUp: true);

                continue;
            }

            $this->failure_streak = 0;
            $this->processKillmail($killmail);
            $sequence = $killmail->sequence_id + 1;
            $this->rememberSequence($sequence);
            $this->pause(failed: false);
        }

        $this->info('Stopped listening for killmails.');

        return self::SUCCESS;
    }

    private function shouldRestart(mixed $restartSignal): bool
    {
        return Cache::get(zKillboardCacheKey::Restart->value) !== $restartSignal;
    }

    /**
     * @throws Throwable
     */
    private function resolveStartingSequence(): int
    {
        $cached = Cache::get(zKillboardCacheKey::Sequence->value);

        if (is_numeric($cached) && (int) $cached > 0) {
            $this->info(sprintf('Resuming R2Z2 from cached sequence %d', (int) $cached));

            return (int) $cached;
        }

        $latest = $this->fetchLatestSequence();

        if ($latest < 1) {
            throw new RuntimeException('Latest R2Z2 sequence was empty. Refusing to start from 0.');
        }

        $this->info(sprintf('No cached sequence found, starting from latest: %d', $latest));
        $this->rememberSequence($latest);

        return $latest;
    }

    /**
     * @throws Throwable
     */
    private function fetchLatestSequence(): int
    {
        $attempts = 0;
        $last = new RuntimeException('Could not fetch the latest R2Z2 sequence.');

        while ($attempts < 3) {
            $attempts++;

            try {
                return $this->zKillboard->getLatestSequence();
            } catch (Throwable $exception) {
                $last = $exception;
                $this->error(sprintf('Could not fetch latest sequence (attempt %d): %s', $attempts, $exception->getMessage()));
                usleep($this->catchup_delay_ms * 1_000);
            }
        }

        throw $last;
    }

    private function rememberSequence(int $sequence): void
    {
        if ($sequence < 1) {
            return;
        }

        Cache::forever(zKillboardCacheKey::Sequence->value, $sequence);
    }

    private function pause(bool $failed, bool $caughtUp = false): void
    {
        if ($failed) {
            $multiplier = 2 ** min($this->failure_streak - 1, 4);
            $delay = min(60_000, $this->catchup_delay_ms * max(1, $multiplier));
            usleep($delay * 1_000);

            return;
        }

        usleep(($caughtUp ? $this->catchup_delay_ms : $this->poll_delay_ms) * 1_000);
    }

    private function processKillmail(R2Z2Killmail $killmail): void
    {
        $this->logKillmailDetails($killmail);

        if (! $this->shouldStoreKillmail($killmail)) {
            $this->info(sprintf('Killmail ID %d is older than %d days, skipping.', $killmail->killmail_id, $this->max_age_days));

            return;
        }

        if (! Solarsystem::query()->where('id', $killmail->getSolarSystemId())->exists()) {
            $this->warn(sprintf('Solar system %d not found in database, skipping killmail %d.', $killmail->getSolarSystemId(), $killmail->killmail_id));

            return;
        }

        $stored = Killmail::query()->updateOrCreate(
            ['id' => $killmail->killmail_id],
            [
                'hash' => $killmail->hash,
                'solarsystem_id' => $killmail->getSolarSystemId(),
                'time' => $killmail->getKillmailTime(),
                'data' => $killmail->esi,
                'zkb' => (array) $killmail->zkb,
            ]
        );

        $this->ensureOrganisationExistsAction->ensureCorporationExists($killmail->getVictimCorporationId());
        $this->ensureOrganisationExistsAction->ensureAllianceExists($killmail->getVictimAllianceId());

        // zKillboard replays killmails on reconnect; alerts may only fire on first ingest.
        if (! $stored->wasRecentlyCreated) {
            return;
        }

        $this->notifyMaps($stored);

        if ($this->hasActiveKillmailAlerts()) {
            EvaluateKillmailAlertsJob::dispatch($stored->id);
        }
    }

    private function notifyMaps(Killmail $killmail): void
    {
        $maps = Map::query()->whereRelation('mapSolarsystems', 'solarsystem_id', $killmail->solarsystem_id)->get();

        $maps->each(fn (Map $map) => KillmailReceivedEvent::dispatch($map));
    }

    private function shouldStoreKillmail(R2Z2Killmail $killmail): bool
    {
        $time = $killmail->getKillmailTime();

        if (! $time) {
            return false;
        }

        return Date::parse($time)->addDays($this->max_age_days)->isFuture();
    }

    private function logKillmailDetails(R2Z2Killmail $killmail): void
    {
        $solarsystem = Cache::memo()->remember(
            key: sprintf('solarsystem_%d', $killmail->getSolarSystemId()),
            ttl: 3600,
            callback: fn () => Solarsystem::query()->find($killmail->getSolarSystemId())
        );

        $ship = Cache::memo()->remember(
            key: sprintf('type_%d', $killmail->getVictimShipTypeId()),
            ttl: 3600,
            callback: fn () => Type::query()->find($killmail->getVictimShipTypeId())
        );

        $message = sprintf(
            '[seq:%d] Killmail %d: %s destroyed in %s at %s. https://zkillboard.com/kill/%d/',
            $killmail->sequence_id,
            $killmail->killmail_id,
            $ship->name ?? 'Unknown Ship',
            $solarsystem->name ?? 'Unknown System',
            $killmail->getKillmailTime() ?? 'unknown time',
            $killmail->killmail_id
        );

        $systemId = $killmail->getSolarSystemId();
        if ($systemId >= 31_000_000 && $systemId < 32_000_000) {
            $this->info($message);

            return;
        }

        Log::debug($message);
    }

    private function hasActiveKillmailAlerts(): bool
    {
        return (bool) Cache::remember(
            'killmail-alerts:active',
            30,
            fn (): bool => MapAlert::query()
                ->where('type', MapAlertType::Killmail)
                ->where('is_active', true)
                ->exists(),
        );
    }

    private function handleStop(int $signal): void
    {
        match ($signal) {
            SIGTERM, SIGQUIT => $this->should_keep_running = false,
            default => null,
        };
    }
}
