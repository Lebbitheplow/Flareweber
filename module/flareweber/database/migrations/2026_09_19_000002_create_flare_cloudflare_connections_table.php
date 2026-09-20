<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flare_cloudflare_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('account_id');
            $table->string('account_name')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->string('status')->default('connected');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flare_cloudflare_connections');
    }
};
