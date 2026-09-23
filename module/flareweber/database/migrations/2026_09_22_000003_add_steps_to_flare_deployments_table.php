<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('flare_deployments', function (Blueprint $table) {
            if (!Schema::hasColumn('flare_deployments', 'steps')) {
                $table->longText('steps')->nullable();
            }

            if (!Schema::hasColumn('flare_deployments', 'rollback_to')) {
                $table->unsignedBigInteger('rollback_to')->nullable();
            }
        });

        // Versions are numbered per environment, so the unique key must
        // include the environment.
        Schema::table('flare_deployments', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'version']);
        });

        Schema::table('flare_deployments', function (Blueprint $table) {
            $table->unique(['site_id', 'environment', 'version'], 'flare_deployments_site_env_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flare_deployments', function (Blueprint $table) {
            $table->dropUnique('flare_deployments_site_env_version_unique');
        });

        Schema::table('flare_deployments', function (Blueprint $table) {
            $table->unique(['site_id', 'version']);
        });

        Schema::table('flare_deployments', function (Blueprint $table) {
            if (Schema::hasColumn('flare_deployments', 'steps')) {
                $table->dropColumn('steps');
            }

            if (Schema::hasColumn('flare_deployments', 'rollback_to')) {
                $table->dropColumn('rollback_to');
            }
        });
    }
};
