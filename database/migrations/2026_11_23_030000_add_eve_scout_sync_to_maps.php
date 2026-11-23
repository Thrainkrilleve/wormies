<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auto-import is off. When a manager enables it, both hubs are imported and
     * only destinations within 5 stargate jumps of the home or a pinned system
     * are kept. A map with neither anchor receives nothing.
     */
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->boolean('eve_scout_sync_enabled')->default(false);
            $table->string('eve_scout_hubs')->default('both');
            $table->string('eve_scout_filter')->default('jumps');
            $table->unsignedTinyInteger('eve_scout_max_jumps')->default(5);
            $table->json('eve_scout_region_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->dropColumn([
                'eve_scout_sync_enabled',
                'eve_scout_hubs',
                'eve_scout_filter',
                'eve_scout_max_jumps',
                'eve_scout_region_ids',
            ]);
        });
    }
};
