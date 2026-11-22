<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MapSolarsystemController;
use App\Http\Controllers\Api\SovereigntyController;
use Illuminate\Support\Facades\Route;

Route::get('sovereignties', [SovereigntyController::class, 'index'])->name('api.sovereignties.index');

Route::middleware(['auth:sanctum', 'throttle:api'])->name('api.')->group(function () {
    Route::middleware('token.ability:maps:read')->group(function () {
        Route::get('maps', [MapController::class, 'index'])->name('maps.index');
        Route::get('maps/{map}', [MapController::class, 'show'])->name('maps.show');
        Route::get('map-solarsystems/{map_solarsystem}', [MapSolarsystemController::class, 'show'])->name('map-solarsystems.show');
    });

    Route::middleware('token.ability:maps:write')->group(function () {
        Route::match(['put', 'patch'], 'maps/{map}', [MapController::class, 'update'])->name('maps.update');
        Route::post('map-solarsystems', [MapSolarsystemController::class, 'store'])->name('map-solarsystems.store');
        Route::match(['put', 'patch'], 'map-solarsystems/{map_solarsystem}', [MapSolarsystemController::class, 'update'])->name('map-solarsystems.update');
        Route::delete('map-solarsystems/{map_solarsystem}', [MapSolarsystemController::class, 'destroy'])->name('map-solarsystems.destroy');
    });
});
