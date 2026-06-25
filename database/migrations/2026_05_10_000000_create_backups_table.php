<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id')->nullable()->index();
            $table->string('database_name');
            $table->string('disk');
            $table->string('path')->nullable();

            $table->string('status')->default('pending');
            $table->string('retention_tier')->nullable();

            // S3 multipart tracking — persisted before any part upload so
            // stale uploads can always be aborted even after a worker crash.
            $table->string('upload_id')->nullable();
            $table->unsignedInteger('parts_uploaded')->default(0);

            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum')->nullable();

            $table->string('compression_driver')->nullable();
            $table->float('compression_ratio')->nullable();
            $table->float('upload_speed_mbps')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'started_at']);
            $table->index(['retention_tier', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
