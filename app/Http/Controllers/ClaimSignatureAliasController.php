<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Signatures\ClaimSignatureAliasAction;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ClaimSignatureAliasController extends Controller
{
    public function __invoke(
        Signature $signature,
        ClaimSignatureAliasAction $action,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $map = $signature->mapSolarsystem->map;
        abort_unless($user->can('view', $map), 403);

        return response()->json($action->handle($signature, $user->can('update', $map)));
    }
}
