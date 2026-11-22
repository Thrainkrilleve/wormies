<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite as ViteBuilder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

it('puts the csp nonce on every inline script vite and the layout emit', function () {
    $this->withVite();
    Vite::clearResolvedInstance();

    $buildDirectory = 'build-csp-test';
    $directory = public_path($buildDirectory);
    File::ensureDirectoryExists($directory);
    File::put($directory.'/manifest.json', json_encode([
        'resources/js/app.ts' => [
            'file' => 'assets/app.js',
            'name' => 'app',
            'src' => 'resources/js/app.ts',
            'isEntry' => true,
            'dynamicImports' => ['resources/js/extra.js'],
        ],
        'resources/js/extra.js' => [
            'file' => 'assets/extra.js',
            'name' => 'extra',
            'src' => 'resources/js/extra.js',
            'isEntry' => false,
        ],
        'resources/js/pages/Landing.vue' => [
            'file' => 'assets/Landing.js',
            'name' => 'Landing',
            'src' => 'resources/js/pages/Landing.vue',
            'isEntry' => true,
        ],
        'resources/css/app.css' => [
            'file' => 'assets/app.css',
            'name' => 'app',
            'src' => 'resources/css/app.css',
            'isEntry' => true,
        ],
    ], JSON_THROW_ON_ERROR));

    $vite = app(ViteBuilder::class);
    $vite->useHotFile(storage_path('framework/csp-test-no-hot'));
    $vite->useBuildDirectory($buildDirectory);

    try {
        $response = $this->get(route('landing'));

        $response->assertSuccessful();

        $policy = (string) $response->headers->get('Content-Security-Policy');
        preg_match('/script-src ([^;]+)/', $policy, $scriptSrc);
        preg_match("/'nonce-([^']+)'/", $scriptSrc[1] ?? '', $nonce);

        expect($nonce[1] ?? null)->not->toBeNull()
            ->and($scriptSrc[1])->not->toContain('unsafe-inline')
            ->and($policy)->toContain("style-src 'self' 'unsafe-inline'");

        $html = $response->getContent();
        expect($html)->toContain('const makeLink');

        preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', (string) $html, $scripts, PREG_SET_ORDER);

        $inline = 0;

        foreach ($scripts as $script) {
            $attributes = $script[1];

            if (preg_match('/\bsrc\s*=/i', $attributes) === 1) {
                continue;
            }

            if (preg_match('/\btype\s*=\s*([\'"])application\/json\1/i', $attributes) === 1) {
                continue;
            }

            $inline++;
            expect($attributes)->toMatch('/\bnonce\s*=\s*([\'"])'.preg_quote((string) $nonce[1], '/').'\1/');
        }

        expect($inline)->toBeGreaterThan(0);
    } finally {
        File::deleteDirectory($directory);
    }
});
