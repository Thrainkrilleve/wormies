<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Map;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDiscordAccessPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $map = $this->route('map');

        return $map instanceof Map && $this->user()?->can('manageAccess', $map) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permission' => ['required', 'string', Rule::in([Permission::Viewer->value, Permission::Member->value])],
        ];
    }
}
