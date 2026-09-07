<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

use function back;

final class ScopeController extends Controller
{
    public function __construct(
        #[CurrentUser] private readonly User $user
    ) {}

    public function index(): RedirectResponse
    {
        return to_route('settings.show', ['section' => 'esi']);
    }

    public function show(Request $request): RedirectResponse
    {
        // Redirect to Alliance Auth's launch endpoint.
        // This uses @token_required(scopes=WORMHOLESYSTEMS_SCOPES) which triggers
        // EVE SSO for any missing ESI scopes, then redirects back to Wormhole Systems.
        // We cannot request ESI scopes via the OIDC /o/authorize/ flow because
        // Alliance Auth's OIDC provider only recognises its own registered scopes
        // (openid/profile/email/groups) and silently strips unknown ESI scope names.
        $baseUrl = rtrim((string) config('services.allianceauth.base_url', 'https://auth.r3v-w.space'), '/');
        $launchUrl = $baseUrl . '/wormholesystems/launch/';

        return redirect()->away($launchUrl);
    }

    public function destroy(Character $character): RedirectResponse
    {
        Gate::denyIf($character->user()->isNot($this->user));

        $character->esiTokens()->delete();

        return back()->notify('Scopes removed successfully.', message: 'We have removed all scopes for this character.');
    }
}
