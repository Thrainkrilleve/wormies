<?php

declare(strict_types=1);

it('single-sources the alliance auth package version and matches a v tag', function () {
    $root = dirname(__DIR__, 2);
    $init = file_get_contents($root.'/allianceauth-wormholesystems/allianceauth_wormholesystems/__init__.py');
    preg_match('/^__version__ = "([^"]+)"/m', $init, $match);

    expect($match[1] ?? null)->toBe('0.0.15');

    foreach (['setup.py', 'allianceauth-wormholesystems/setup.py'] as $setup) {
        $contents = file_get_contents($root.'/'.$setup);
        expect($contents)->toContain('version=read_version()')
            ->and($contents)->not->toMatch('/version\s*=\s*[\'"]\d/');
    }

    $workflow = file_get_contents($root.'/.github/workflows/docker.yml');
    expect($workflow)->toContain("sh docker/require-package-version.sh")
        ->and($workflow)->toContain("startsWith(github.ref, 'refs/tags/v')");

    $script = escapeshellarg($root.'/docker/require-package-version.sh');
    exec('sh '.$script.' '.escapeshellarg('v'.$match[1]).' >/dev/null 2>&1', $good, $ok);
    exec('sh '.$script.' v0.0.0 >/dev/null 2>&1', $bad, $fail);

    expect($ok)->toBe(0)
        ->and($fail)->not->toBe(0);
});
