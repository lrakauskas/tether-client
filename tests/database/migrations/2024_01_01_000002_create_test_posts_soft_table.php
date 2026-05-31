<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('test_posts_soft', function (Blueprint $table) {
            $table->id();
            $table->char('tether_id', 26)->unique()->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_posts_soft');
    }
};
