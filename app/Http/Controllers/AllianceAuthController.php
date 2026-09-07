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
        if (auth()->check() && ! $request->query('add_to_account')) {
            return to_route('home');
        }

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
            Log::warning('Alliance Auth state verification failed', [
                'has_query_state' => $state !== '',
                'has_session_state' => $sessionState !== '',
            ]);

            return to_route('login')->withErrors(['auth' => 'Invalid state verification from Alliance Auth. Please try again.']);
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            $error = (string) $request->query('error', 'access_denied');
            $errorDesc = (string) $request->query('error_description', '');

            Log::warning('Alliance Auth OAuth callback denied or returned error', [
                'error' => $error,
                'error_description' => $errorDesc,
                'query' => $request->all(),
            ]);

            $message = $errorDesc !== ''
                ? $errorDesc
                : ($error === 'invalid_scope'
                    ? "Authorization failed: Invalid OAuth scope requested ({$error})."
                    : "Authorization was denied by Alliance Auth ({$error}).");

            return to_route('login')->withErrors(['auth' => $message]);
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
