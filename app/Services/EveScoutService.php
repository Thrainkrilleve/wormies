<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\EveScoutConnectionData;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class EveScoutService
{
    private const string API_BASE_URL = 'https://api.eve-scout.com/v2/public';

    private const int CACHE_EXPIRATION_SECONDS = 60 * 5; // 5 minutes

    private const string CACHE_KEY = 'eve_scout_signatures';

    private const string PUBLIC_CACHE_KEY = 'eve_scout_public_signatures';

    /**
     * Get current wormhole signatures from EVE Scout API
     *
     * @param  bool  $allow_eol  Whether to include EOL connections (< 4 hours remaining)
     * @return Collection<int, EveScoutConnectionData>
     */
    public function getWormholeConnections(bool $allow_eol = true): Collection
    {
        $cache_key = self::CACHE_KEY.'_eol_'.($allow_eol ? '1' : '0');

        return Cache::remember(
            $cache_key,
            self::CACHE_EXPIRATION_SECONDS,
            fn (): Collection => $this->fetchWormholeConnections($allow_eol)
        );
    }

    /**
     * Convert EVE Scout connections to route service format
     *
     * @param  bool  $allow_eol  Whether to include EOL connections (< 4 hours remaining)
     * @return array<int, int[]>
     */
    public function getConnectionsForRouting(bool $allow_eol = true): array
    {
        $connections = $this->getWormholeConnections($allow_eol);
        $routingConnections = [];

        foreach ($connections as $connection) {
            $from = $connection->in_system_id;
            $to = $connection->out_system_id;

            // Add bidirectional connections
            if (! isset($routingConnections[$from])) {
                $routingConnections[$from] = [];
            }
            if (! isset($routingConnections[$to])) {
                $routingConnections[$to] = [];
            }

            $routingConnections[$from][] = $to;
            $routingConnections[$to][] = $from;
        }

        return $routingConnections;
    }

    /**
     * One successful public snapshot, shared by every map in a sync run.
     * A failed request is not cached and is not an empty list, so callers can
     * leave the map untouched instead of deleting its holes.
     *
     * @return Collection<int, EveScoutConnectionData>|null
     */
    public function publicSignatures(): ?Collection
    {
        $cached = Cache::get(self::PUBLIC_CACHE_KEY);
        if ($cached instanceof Collection) {
            return $cached;
        }

        $signatures = $this->requestSignatures();
        if ($signatures === null) {
            return null;
        }

        $connections = $this->mapSignatures($signatures, true);
        Cache::put(self::PUBLIC_CACHE_KEY, $connections, self::CACHE_EXPIRATION_SECONDS);

        return $connections;
    }

    /**
     * Fetch wormhole connections from EVE Scout API
     *
     * @param  bool  $allow_eol  Whether to include EOL connections (< 4 hours remaining)
     */
    private function fetchWormholeConnections(bool $allow_eol = true): Collection
    {
        $signatures = $this->requestSignatures();

        return $signatures === null ? collect() : $this->mapSignatures($signatures, $allow_eol);
    }

    /**
     * @return list<mixed>|null
     */
    private function requestSignatures(): ?array
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => (string) config('esi.user_agent'),
            ])
                ->connectTimeout(5)
                ->timeout(10)
                ->get(self::API_BASE_URL.'/signatures');

            if (! $response->successful()) {
                Log::warning('EVE Scout API request failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();
            if (! is_array($data) || ! array_is_list($data)) {
                Log::warning('EVE Scout API response was not a signature list', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            /** @var list<array<string, mixed>> $data */
            return $data;
        } catch (Exception $e) {
            Log::error('EVE Scout API error', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  list<mixed>  $signatures
     * @return Collection<int, EveScoutConnectionData>
     */
    private function mapSignatures(array $signatures, bool $allow_eol): Collection
    {
        return collect($signatures)
            ->filter(fn (mixed $signature): bool => is_array($signature) && isset($signature['out_system_id']) &&
                ($signature['completed'] ?? false) === true &&
                ($signature['signature_type'] ?? '') === 'wormhole'
            )
            ->when(! $allow_eol, fn ($collection) => $collection->filter(function (array $signature): bool {
                $remaining_hours = $signature['remaining_hours'] ?? null;

                if ($remaining_hours === null) {
                    Log::warning('Missing remaining_hours in EVE Scout data', [
                        'signature_id' => $signature['id'] ?? 'unknown',
                    ]);

                    return false;
                }

                return (float) $remaining_hours >= 4.0;
            }))
            ->map(fn (array $signature): EveScoutConnectionData => EveScoutConnectionData::from([
                'in_system_id' => $signature['in_system_id'],
                'out_system_id' => $signature['out_system_id'],
                'in_signature' => $signature['in_signature'] ?? '',
                'out_signature' => $signature['out_signature'] ?? '',
                'wormhole_type' => $signature['wh_type'] ?? 'unknown',
                'life' => $signature['life'] ?? 'unknown',
                'mass' => $signature['mass'] ?? 'unknown',
                'remaining_hours' => $signature['remaining_hours'] ?? null,
                'created_at' => $signature['created_at'] ?? null,
            ]))
            ->values();
    }
}
