<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('tether-client.table', 'tether_mutation_logs');
        $connection = config('tether-client.connection');

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->id();
            $table->char('mutation_id', 26)->unique();
            $table->char('entity_id', 26)->index();
            $table->string('model');
            $table->string('operation');
            $table->json('payload');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('timestamp');
            $table->string('sync_status')->default('pending')->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $table = config('tether-client.table', 'tether_mutation_logs');
        $connection = config('tether-client.connection');

        Schema::connection($connection)->dropIfExists($table);
    }
};
