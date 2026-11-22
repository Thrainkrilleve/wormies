<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Vite;

it('puts the csp nonce on every inline script vite and the layout emit', function () {
    $this->withVite();
    Vite::clearResolvedInstance();

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
});
