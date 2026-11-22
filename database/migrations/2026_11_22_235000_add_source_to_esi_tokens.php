<?php

declare(strict_types=1);

use App\Services\Esi\RepairAllianceAuthEsiTokens;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esi_tokens', function (Blueprint $table): void {
            $table->string('source')->default('eve_sso')->after('token_type');
            $table->string('oauth_client_id')->nullable()->after('source');
            $table->boolean('reauth_required')->default(false)->after('oauth_client_id');
        });

        RepairAllianceAuthEsiTokens::markCharacterTypedTokens();
    }

    public function down(): void
    {
        Schema::table('esi_tokens', function (Blueprint $table): void {
            $table->dropColumn(['source', 'oauth_client_id', 'reauth_required']);
        });
    }
};
