<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (auth()->check()) {
            return to_route('home');
        }

        // Prevent redirect loop if redirected with errors
        if ($request->session()->has('errors')) {
            return Inertia::render('auth/Login', [
                'allianceAuthEnabled' => (bool) config('services.allianceauth.enabled', true),
            ]);
        }

        if (config('services.allianceauth.only')) {
            return to_route('allianceauth.redirect');
        }

        return Inertia::render('auth/Login', [
            'allianceAuthEnabled' => (bool) config('services.allianceauth.enabled', true),
        ]);
    }
}
