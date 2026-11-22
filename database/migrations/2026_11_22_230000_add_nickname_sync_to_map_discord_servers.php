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
            $table->boolean('nickname_sync')->default(true)->after('permission');
        });
    }

    public function down(): void
    {
        Schema::table('map_discord_servers', function (Blueprint $table): void {
            $table->dropColumn('nickname_sync');
        });
    }
};
