<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('tether-client.connection');

        Schema::connection($connection)->create('tether_sync_state', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('tether-client.connection');

        Schema::connection($connection)->dropIfExists('tether_sync_state');
    }
};
