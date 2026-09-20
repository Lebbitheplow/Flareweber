<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flare_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('domain')->nullable()->index();
            $table->string('worker_name')->nullable()->unique();
            $table->string('preview_worker_name')->nullable()->unique();
            $table->string('d1_database_name')->nullable();
            $table->string('r2_bucket_name')->nullable();
            $table->unsignedBigInteger('cloudflare_connection_id')->nullable()->index();
            $table->json('settings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flare_sites');
    }
};
