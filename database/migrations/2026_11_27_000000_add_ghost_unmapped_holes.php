<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ghost placements have a null solar system. A UNIQUE (map_id, solarsystem_id)
 * cannot stay once solarsystem_id is nullable: MariaDB and MySQL treat each
 * NULL as distinct, so two real copies of the same system would both be
 * allowed. A generated column is rejected here (error 1215) while the solar
 * system and self-referential foreign keys exist, so real_system_key is a
 * normal nullable column. It is "map:system" for a real placement and NULL
 * for a ghost, and it is unique. Existing rows are backfilled, so the old
 * uniqueness is preserved and no row is rewritten beyond that key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->boolean('ghost_unlinked_wormholes')->default(false);
        });

        DB::statement('ALTER TABLE map_solarsystems MODIFY solarsystem_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE map_solarsystems MODIFY map_solarsystem_details_id BIGINT UNSIGNED NULL');

        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->foreignId('raised_by_signature_id')
                ->nullable()
                ->unique()
                ->constrained('signatures')
                ->cascadeOnDelete();
            $table->foreignId('hangs_off_id')
                ->nullable()
                ->constrained('map_solarsystems')
                ->cascadeOnDelete();
        });

        Schema::table('map_solarsystems', function (Blueprint $table): void {
            // The composite unique index is the only index on map_id, and the
            // map foreign key uses it. Add a plain index before dropping it.
            $table->index('map_id');
            $table->dropUnique(['map_id', 'solarsystem_id']);
        });

        DB::statement('ALTER TABLE map_solarsystems ADD COLUMN real_system_key VARCHAR(64) NULL');
        DB::statement("UPDATE map_solarsystems SET real_system_key = CONCAT(map_id, ':', solarsystem_id) WHERE solarsystem_id IS NOT NULL");
        DB::statement('CREATE UNIQUE INDEX map_solarsystems_real_system_key_unique ON map_solarsystems (real_system_key)');

        // MySQL 8.4 rejects a CHECK that mentions a column used by a foreign key
        // with ON DELETE/UPDATE (error 3823). The placement model enforces the
        // ghost shape and the unpinned rule instead.
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE map_solarsystems DROP INDEX map_solarsystems_real_system_key_unique');
        DB::statement('ALTER TABLE map_solarsystems DROP COLUMN real_system_key');

        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hangs_off_id');
            $table->dropConstrainedForeignId('raised_by_signature_id');
            $table->unique(['map_id', 'solarsystem_id']);
        });

        DB::statement('ALTER TABLE map_solarsystems MODIFY solarsystem_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE map_solarsystems MODIFY map_solarsystem_details_id BIGINT UNSIGNED NOT NULL');

        Schema::table('maps', function (Blueprint $table): void {
            $table->dropColumn('ghost_unlinked_wormholes');
        });
    }
};
