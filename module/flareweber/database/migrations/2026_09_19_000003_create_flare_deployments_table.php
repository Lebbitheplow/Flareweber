<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flare_deployments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedInteger('version');
            $table->string('environment')->default('production');
            $table->string('status')->default('pending');
            $table->string('artifact_hash')->nullable();
            $table->string('worker_version_id')->nullable();
            $table->string('url')->nullable();
            $table->longText('log')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flare_deployments');
    }
};
