<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('restores', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('backup_id');
            $table->string('tenant_id')->nullable()->index();
            $table->string('database_name');
            $table->string('connection_name');

            $table->json('tables_requested');
            $table->json('tables_restored')->nullable();

            $table->string('status')->default('pending');
            $table->unsignedBigInteger('rows_affected')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('backup_id');
            $table->index(['status', 'started_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restores');
    }
};
