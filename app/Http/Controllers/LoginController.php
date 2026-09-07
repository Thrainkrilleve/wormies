<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        if (config('services.allianceauth.only')) {
            return to_route('allianceauth.redirect');
        }

        return Inertia::render('auth/Login', [
            'allianceAuthEnabled' => (bool) config('services.allianceauth.enabled', true),
        ]);
    }
}
