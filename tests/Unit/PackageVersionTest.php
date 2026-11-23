<?php

declare(strict_types=1);

it('derives the alliance auth package version from a git tag and stamps the fallback', function () {
    $root = dirname(__DIR__, 2);
    $init = file_get_contents($root.'/allianceauth-wormholesystems/allianceauth_wormholesystems/__init__.py');
    preg_match('/^_FALLBACK_VERSION = "([^"]+)"/m', $init, $match);
    $version = $match[1] ?? null;

    expect($version)->toMatch('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/')
        ->and($init)->toContain('from importlib.metadata import PackageNotFoundError, version')
        ->and($init)->toContain('version("allianceauth-wormholesystems")');

    foreach (['pyproject.toml', 'allianceauth-wormholesystems/pyproject.toml'] as $project) {
        $contents = file_get_contents($root.'/'.$project);
        expect($contents)->toContain('dynamic = ["version"]')
            ->and($contents)->toContain('setuptools-scm')
            ->and($contents)->toContain('fallback_version = "'.$version.'"');
    }

    foreach (['setup.py', 'allianceauth-wormholesystems/setup.py'] as $setup) {
        $contents = file_get_contents($root.'/'.$setup);
        expect($contents)->not->toMatch('/version\s*=\s*[\'"]\d/');
    }

    $workflow = file_get_contents($root.'/.github/workflows/docker.yml');
    expect($workflow)->toContain('sh docker/require-package-version.sh')
        ->and($workflow)->toContain("startsWith(github.ref, 'refs/tags/v')")
        ->and($workflow)->toContain('group: publish-${{ github.ref }}')
        ->and($workflow)->toContain("cancel-in-progress: \${{ github.ref_type != 'tag' }}")
        ->and($workflow)->toContain('git fetch --no-tags origin main:refs/remotes/origin/main');

    $script = $root.'/docker/require-package-version.sh';

    $plain = packageVersionFixture();
    runPackageVersionScript($script, 'v1.2', $plain, 1);
    expect(file_get_contents(packageVersionInit($plain)))->toContain('_FALLBACK_VERSION = "0.0.0"');

    runPackageVersionScript($script, 'v1.2.3', $plain, 0);
    expect(file_get_contents(packageVersionInit($plain)))->toContain('_FALLBACK_VERSION = "1.2.3"');

    $repo = packageVersionFixture(git: true);
    runPackageVersionScript($script, 'v9.9.9', $repo, 1);
    expect(file_get_contents(packageVersionInit($repo)))->toContain('_FALLBACK_VERSION = "0.0.1"');

    gitIn($repo, 'checkout main');
    runPackageVersionScript($script, 'v9.9.9', $repo, 0);
    expect(file_get_contents(packageVersionInit($repo)))->toContain('_FALLBACK_VERSION = "9.9.9"');
});

function packageVersionInit(string $root): string
{
    return $root.'/allianceauth-wormholesystems/allianceauth_wormholesystems/__init__.py';
}

function packageVersionFixture(bool $git = false): string
{
    $root = sys_get_temp_dir().'/pkg-version-'.bin2hex(random_bytes(4));
    $init = packageVersionInit($root);
    mkdir(dirname($init), 0777, true);
    file_put_contents($init, "_FALLBACK_VERSION = \"0.0.0\"\n");

    if (! $git) {
        return $root;
    }

    gitIn($root, 'init -b main');
    gitIn($root, 'config user.email test@example.com');
    gitIn($root, 'config user.name test');
    gitIn($root, 'add .');
    gitIn($root, 'commit -m init');
    file_put_contents($init, "_FALLBACK_VERSION = \"0.0.1\"\n");
    gitIn($root, 'checkout -b side');
    gitIn($root, 'add .');
    gitIn($root, 'commit -m side');
    gitIn($root, 'update-ref refs/remotes/origin/main main');

    return $root;
}

function gitIn(string $root, string $args): void
{
    exec('git -C '.escapeshellarg($root).' '.$args.' 2>&1', $output, $code);
    expect($code)->toBe(0);
}

function runPackageVersionScript(string $script, string $tag, string $root, int $expected): void
{
    exec(
        'sh '.escapeshellarg($script).' '.escapeshellarg($tag).' '.escapeshellarg($root).' >/dev/null 2>&1',
        $output,
        $code,
    );
    expect($code)->toBe($expected);
}
