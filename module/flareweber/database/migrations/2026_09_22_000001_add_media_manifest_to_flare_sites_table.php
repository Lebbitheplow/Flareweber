<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('flare_sites', function (Blueprint $table) {
            if (!Schema::hasColumn('flare_sites', 'media_manifest')) {
                $table->longText('media_manifest')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('flare_sites', function (Blueprint $table) {
            if (Schema::hasColumn('flare_sites', 'media_manifest')) {
                $table->dropColumn('media_manifest');
            }
        });
    }
};
