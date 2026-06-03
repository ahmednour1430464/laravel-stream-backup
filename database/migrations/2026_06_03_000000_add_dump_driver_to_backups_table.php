<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('dump_driver')
                ->nullable()
                ->after('compression_driver')
                ->comment('CLI tool that produced the dump (mysqldump, pg_dump, sqlite3)');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('dump_driver');
        });
    }
};
