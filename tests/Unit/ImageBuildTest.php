<?php

declare(strict_types=1);

it('creates the view cache directory before build-time artisan commands', function () {
    $root = dirname(__DIR__, 2);
    $dockerfile = file_get_contents($root.'/docker/Dockerfile');
    $ignore = file_get_contents($root.'/.dockerignore');

    $mkdir = strpos($dockerfile, 'storage/framework/views');
    $wayfinder = strpos($dockerfile, 'php artisan wayfinder:generate');

    expect($ignore)->toContain("\nstorage\n")
        ->and($mkdir)->toBeInt()
        ->and($wayfinder)->toBeInt()
        ->and($mkdir)->toBeLessThan($wayfinder);
});
