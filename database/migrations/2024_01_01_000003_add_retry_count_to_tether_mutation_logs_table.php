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
            $table->unsignedInteger('retry_count')->default(0)->after('rejection_data');
        });
    }

    public function down(): void
    {
        $table = config('tether-client.table', 'tether_mutation_logs');
        $connection = config('tether-client.connection');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->dropColumn('retry_count');
        });
    }
};
