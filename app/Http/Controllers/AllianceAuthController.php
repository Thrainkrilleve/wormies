<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exception\CharacterNotAuthorizedException;
use App\Services\AllianceAuthService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

final class AllianceAuthController extends Controller
{
    public function redirect(Request $request, AllianceAuthService $service): RedirectResponse
    {
        if ($request->query('add_to_account')) {
            $request->session()->put('add_to_account', auth()->id());
        }

        $state = Str::random(40);
        $request->session()->put('allianceauth_state', $state);

        return redirect()->away($service->getAuthorizationUrl($state));
    }

    public function callback(Request $request, AllianceAuthService $service): RedirectResponse
    {
        $state = (string) $request->query('state');
        $sessionState = (string) $request->session()->pull('allianceauth_state');

        if ($state === '' || $sessionState === '' || ! hash_equals($sessionState, $state)) {
            return to_route('login')->withErrors(['auth' => 'Invalid state verification from Alliance Auth. Please try again.']);
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            $errorDesc = (string) $request->query('error_description', 'Authorization was denied by Alliance Auth.');

            return to_route('login')->withErrors(['auth' => $errorDesc]);
        }

        $accountId = Session::get('add_to_account');
        $accessDeniedMessage = 'Your character or group is not authorized to access this Wormhole Systems instance.';

        try {
            [$user, $character] = $service->handleCallback($code, $accountId);
        } catch (CharacterNotAuthorizedException $e) {
            $message = $e->getMessage() ?: $accessDeniedMessage;
            if ($accountId) {
                return to_route('home')->notify('Access denied', message: $message, type: 'error');
            }

            return to_route('login')->withErrors(['auth' => $message]);
        } catch (Exception $e) {
            Log::error('Alliance Auth login failed: '.$e->getMessage(), ['exception' => $e]);

            if ($accountId) {
                return to_route('home')->notify('Login Error', message: 'Unable to authenticate with Alliance Auth. Please try again later.', type: 'error');
            }

            return to_route('login')->withErrors(['auth' => 'Unable to authenticate with Alliance Auth: '.$e->getMessage()]);
        }

        Auth::login($user, remember: true);
        $user->active_character = $character;

        if ($accountId) {
            if ($redirect = Session::pull('redirect_to')) {
                return redirect($redirect)->notify('Account Updated', message: 'Your character has been added to your account.');
            }

            return to_route('home')->notify('Account Updated', message: 'Your character has been added to your account.');
        }

        $redirect = redirect()->intended(route('home'))->getTargetUrl();

        if (Str::contains($redirect, '/static/')) {
            $redirect = route('home');
        }

        if (Session::has('redirect_to')) {
            $redirect = Session::pull('redirect_to');
        }

        return redirect($redirect)->notify('Welcome back!', message: sprintf('Logged in via Alliance Auth as %s.', $character->name));
    }
}
