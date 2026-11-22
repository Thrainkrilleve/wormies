<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('map_webhooks')->orderBy('id')->lazyById()->each(function (object $row): void {
            if (! is_string($row->discord_webhook_url) || $row->discord_webhook_url === '' || $this->isEncrypted($row->discord_webhook_url)) {
                return;
            }

            DB::table('map_webhooks')->where('id', $row->id)->update([
                'discord_webhook_url' => Crypt::encryptString($row->discord_webhook_url),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('map_webhooks')->orderBy('id')->lazyById()->each(function (object $row): void {
            if (! is_string($row->discord_webhook_url) || ! $this->isEncrypted($row->discord_webhook_url)) {
                return;
            }

            DB::table('map_webhooks')->where('id', $row->id)->update([
                'discord_webhook_url' => Crypt::decryptString($row->discord_webhook_url),
            ]);
        });
    }

    private function isEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
};
