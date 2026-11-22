<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_discord_servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->unique()->constrained('maps')->cascadeOnDelete();
            $table->string('guild_id')->nullable()->unique();
            $table->string('guild_name')->nullable();
            $table->string('status');
            $table->timestamp('pending_expires_at')->nullable();
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->timestamps();
        });

        Schema::create('map_discord_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->string('guild_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('discord_user_id');
            $table->timestamps();

            $table->unique(['map_id', 'discord_user_id']);
            $table->unique(['map_id', 'user_id']);
        });

        Schema::create('map_discord_access_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->nullable()->constrained('maps')->nullOnDelete();
            $table->string('guild_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('discord_user_id');
            $table->string('action');
            $table->string('reason');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_discord_access_audits');
        Schema::dropIfExists('map_discord_grants');
        Schema::dropIfExists('map_discord_servers');
    }
};
