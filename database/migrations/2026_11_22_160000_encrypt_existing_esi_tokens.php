<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('esi_tokens')->orderBy('id')->lazyById()->each(function (object $row): void {
            $updates = [];

            if (is_string($row->access_token) && ! $this->isEncrypted($row->access_token)) {
                $updates['access_token'] = Crypt::encryptString($row->access_token);
            }

            if (is_string($row->refresh_token) && $row->refresh_token !== '' && ! $this->isEncrypted($row->refresh_token)) {
                $updates['refresh_token'] = Crypt::encryptString($row->refresh_token);
            }

            if ($updates !== []) {
                DB::table('esi_tokens')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        DB::table('esi_tokens')->orderBy('id')->lazyById()->each(function (object $row): void {
            $updates = [];

            if (is_string($row->access_token) && $this->isEncrypted($row->access_token)) {
                $updates['access_token'] = Crypt::decryptString($row->access_token);
            }

            if (is_string($row->refresh_token) && $this->isEncrypted($row->refresh_token)) {
                $updates['refresh_token'] = Crypt::decryptString($row->refresh_token);
            }

            if ($updates !== []) {
                DB::table('esi_tokens')->where('id', $row->id)->update($updates);
            }
        });
    }

    private function isEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
};
