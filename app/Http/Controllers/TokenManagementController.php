<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ApiAbility;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

final class TokenManagementController extends Controller
{
    public function __construct(
        #[CurrentUser] private readonly User $currentUser
    ) {}

    /**
     * Display the token management page.
     *
     * @throws Throwable
     */
    public function index(): RedirectResponse
    {
        Gate::authorize('viewAny', PersonalAccessToken::class);

        return to_route('settings.show', ['section' => 'tokens']);
    }

    /**
     * Create a new API token.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', PersonalAccessToken::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::enum(ApiAbility::class)],
        ]);

        $token = $this->currentUser->createToken(
            name: $validated['name'],
            abilities: $validated['abilities'],
        );

        return back()->notify('Token created successfully', message: 'Your new API token has been created and is now available for use.')->with('token', $token->plainTextToken);
    }

    /**
     * Remove the specified API token.
     */
    public function destroy(PersonalAccessToken $token): RedirectResponse
    {
        Gate::authorize('delete', $token);

        $token->delete();

        return back()->notify('Token deleted successfully', message: 'The API token has been removed and is no longer valid.');
    }
}
