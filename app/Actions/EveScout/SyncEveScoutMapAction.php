<?php

declare(strict_types=1);

namespace App\Actions\EveScout;

use App\Actions\MapConnections\DeleteMapConnectionAction;
use App\Data\EveScoutConnectionData;
use App\Models\Map;
use App\Models\Solarsystem;
use App\Services\EveScout\EveScoutConnectionFilter;
use Illuminate\Support\Collection;
use Throwable;

final readonly class SyncEveScoutMapAction
{
    public function __construct(
        private AddEveScoutConnectionToMapAction $add,
        private DeleteMapConnectionAction $delete,
        private EveScoutConnectionFilter $filter,
    ) {}

    /**
     * @param  Collection<int, EveScoutConnectionData>  $signatures
     *
     * @throws Throwable
     */
    public function handle(Map $map, Collection $signatures): void
    {
        /** @var array<string, true> $desired */
        $desired = [];

        foreach ($map->eve_scout_hubs->systemNames() as $name) {
            $hub = Solarsystem::query()->where('name', $name)->first();
            if ($hub === null) {
                continue;
            }

            $forHub = $signatures
                ->filter(fn (EveScoutConnectionData $connection): bool => $connection->in_system_id === $hub->id
                    || $connection->out_system_id === $hub->id)
                ->filter(fn (EveScoutConnectionData $connection): bool => ! $this->expired($connection))
                ->filter(fn (EveScoutConnectionData $connection): bool => $this->filter->allows($map, $connection, $hub->id))
                ->values();

            foreach ($forHub as $connection) {
                $desired[$this->pairKey($connection->in_system_id, $connection->out_system_id)] = true;
            }

            if ($forHub->isEmpty()) {
                continue;
            }

            $this->add->handle($map, $hub, $forHub, true);
        }

        $this->removeAbsent($map, $desired);
    }

    /**
     * @param  array<string, true>  $desired
     *
     * @throws Throwable
     */
    private function removeAbsent(Map $map, array $desired): void
    {
        $managed = $map->mapConnections()
            ->where('eve_scout_managed', true)
            ->with(['fromMapSolarsystem:id,solarsystem_id', 'toMapSolarsystem:id,solarsystem_id'])
            ->get();

        foreach ($managed as $connection) {
            $key = $this->pairKey(
                $connection->fromMapSolarsystem->solarsystem_id,
                $connection->toMapSolarsystem->solarsystem_id,
            );
            if (! isset($desired[$key])) {
                $this->delete->handle($connection);
            }
        }
    }

    private function expired(EveScoutConnectionData $connection): bool
    {
        return $connection->remaining_hours !== null && $connection->remaining_hours <= 0;
    }

    private function pairKey(int $left, int $right): string
    {
        return min($left, $right).':'.max($left, $right);
    }
}
