<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('tether-client.table', 'tether_mutation_logs');
        $connection = config('tether-client.connection');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->string('rejection_reason')->nullable()->after('synced_at');
            $table->json('rejection_data')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        $table = config('tether-client.table', 'tether_mutation_logs');
        $connection = config('tether-client.connection');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->dropColumn(['rejection_reason', 'rejection_data']);
        });
    }
};
