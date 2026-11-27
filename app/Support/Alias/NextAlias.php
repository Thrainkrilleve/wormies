<?php

declare(strict_types=1);

namespace App\Support\Alias;

/**
 * Server-side copy of resources/js/lib/alias.ts guessNextAlias.
 * The chain is numeric by default (A, A1, A2) and fills the lowest free index.
 */
final class NextAlias
{
    private const string WORMHOLE_LETTERS = 'ABCDEFGIJKMOQRSTUVWXYZ';

    /**
     * @param  list<string>  $aliases
     */
    public static function guess(
        ?string $parentAlias,
        array $aliases,
        string $scheme = 'numeric',
        ?string $ignoredAlias = null,
        string $targetKind = 'wormhole',
    ): string {
        $prefix = mb_strtoupper(mb_trim($parentAlias ?? ''));

        if (self::isIgnored($prefix, $ignoredAlias)) {
            $prefix = '';
        }

        $known = array_map(fn (string $alias): string => mb_strtoupper(mb_trim($alias)), $aliases);

        if ($scheme === 'alphabetical') {
            return self::alphabetical($prefix, $known, $targetKind);
        }

        $children = array_values(array_filter(
            $known,
            fn (string $alias): bool => mb_strlen($alias) > mb_strlen($prefix)
                && str_starts_with($alias, $prefix)
                && preg_match('/^\d+$/', mb_substr($alias, mb_strlen($prefix))) === 1,
        ));

        $direct = array_values(array_filter(
            $children,
            fn (string $alias): bool => array_all(
                $children,
                fn (string $other): bool => $other === $alias
                    || mb_strlen($other) >= mb_strlen($alias)
                    || ! str_starts_with($alias, $other),
            ),
        ));

        $used = [];
        foreach ($direct as $alias) {
            $used[] = (int) mb_substr($alias, mb_strlen($prefix));
        }

        return $prefix.self::lowestFree($used);
    }

    /**
     * @param  list<int>  $used
     */
    private static function lowestFree(array $used): int
    {
        $taken = array_fill_keys($used, true);
        $index = 1;
        while (isset($taken[$index])) {
            $index++;
        }

        return $index;
    }

    private static function isIgnored(string $alias, ?string $ignored): bool
    {
        $ignored = mb_strtoupper(mb_trim($ignored ?? ''));

        return $ignored !== '' && $alias === $ignored;
    }

    /**
     * @param  list<string>  $aliases
     */
    private static function alphabetical(string $prefix, array $aliases, string $targetKind): string
    {
        if ($targetKind !== 'wormhole' && $targetKind !== '') {
            $letter = mb_strtoupper($targetKind);

            return $prefix.$letter.self::lowestFree(self::kspaceIndexes($prefix, $letter, $aliases));
        }

        $used = [];
        foreach ($aliases as $alias) {
            if (mb_strlen($alias) !== mb_strlen($prefix) + 1 || ! str_starts_with($alias, $prefix)) {
                continue;
            }

            $index = mb_strpos(self::WORMHOLE_LETTERS, mb_substr($alias, mb_strlen($prefix)));
            if ($index !== false) {
                $used[] = $index;
            }
        }

        $index = 0;
        $taken = array_fill_keys($used, true);
        while (isset($taken[$index])) {
            $index++;
        }

        $letters = self::WORMHOLE_LETTERS;
        $capped = min($index, mb_strlen($letters) - 1);

        return $prefix.mb_substr($letters, $capped, 1);
    }

    /**
     * @param  list<string>  $aliases
     * @return list<int>
     */
    private static function kspaceIndexes(string $prefix, string $letter, array $aliases): array
    {
        $marker = $prefix.$letter;
        $used = [];
        foreach ($aliases as $alias) {
            if (! str_starts_with($alias, $marker)) {
                continue;
            }

            $tail = mb_substr($alias, mb_strlen($marker));
            if (preg_match('/^\d+$/', $tail) === 1) {
                $used[] = (int) $tail;
            }
        }

        return $used;
    }
}
