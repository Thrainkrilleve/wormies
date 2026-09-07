<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

final readonly class AuthController
{
    public function __construct(
        #[CurrentUser]
        private ?User $user = null
    ) {}

    public function show(Request $request): RedirectResponse
    {
        if ($request->boolean('add_to_account') && $this->user) {
            Session::put('add_to_account', $this->user->id);
        }

        return to_route('allianceauth.redirect');
    }

    public function destroy(): RedirectResponse
    {
        Auth::logout();
        Session::invalidate();
        Session::regenerateToken();

        // Not route('login'): that redirects straight to Alliance Auth when
        // ALLIANCEAUTH_ONLY is set, which this request (fired via an Inertia
        // Link, i.e. an XHR) can't follow cross-origin - the browser blocks it
        // as a CORS violation rather than actually redirecting. The landing
        // page is same-origin and never redirects further.
        return to_route('landing')->notify(
            'Logged out successfully.',
            'You have been logged out of your account.'
        );
    }
}
