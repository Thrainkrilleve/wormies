<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EveScoutFilter;
use App\Enums\EveScoutHub;
use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Container\Attributes\RouteParameter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEveScoutSyncRequest extends FormRequest
{
    /**
     * Importing public holes changes the map for every viewer.
     */
    public function authorize(#[RouteParameter('map')] Map $map, #[CurrentUser] User $user): bool
    {
        return $user->can('updateSettings', $map);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'eve_scout_sync_enabled' => ['required', 'boolean'],
            'eve_scout_hubs' => ['required', Rule::enum(EveScoutHub::class)],
            'eve_scout_filter' => ['required', Rule::enum(EveScoutFilter::class)],
            'eve_scout_max_jumps' => ['required', 'integer', 'min:0', 'max:50'],
            'eve_scout_region_ids' => ['present', 'array'],
            'eve_scout_region_ids.*' => ['integer', 'exists:regions,id'],
        ];
    }
}
