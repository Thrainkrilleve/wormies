<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks connections created by the EVE-Scout sync so a later run can update
     * or remove them without touching holes a pilot added.
     */
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->boolean('eve_scout_managed')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn('eve_scout_managed');
        });
    }
};
