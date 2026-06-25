<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `encryption_driver` to the backups table.
 *
 * Nullable — null means no encryption was used (pre-feature rows and
 * backups where driver = 'none'). A non-null value records the driver
 * name (e.g. 'openssl-aes-256-gcm', 'sodium') so a future restore
 * command knows which cipher to use for decryption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->string('encryption_driver')->nullable()->after('compression_driver');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('encryption_driver');
        });
    }
};
