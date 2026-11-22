<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_discord_servers', function (Blueprint $table): void {
            $table->string('pending_state_hash', 64)->nullable()->unique()->after('pending_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('map_discord_servers', function (Blueprint $table): void {
            $table->dropUnique(['pending_state_hash']);
            $table->dropColumn('pending_state_hash');
        });
    }
};
