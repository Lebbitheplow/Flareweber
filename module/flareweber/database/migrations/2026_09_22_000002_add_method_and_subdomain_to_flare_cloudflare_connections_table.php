<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('flare_cloudflare_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('flare_cloudflare_connections', 'method')) {
                $table->string('method', 16)->default('oauth');
            }

            if (!Schema::hasColumn('flare_cloudflare_connections', 'workers_subdomain')) {
                $table->string('workers_subdomain')->nullable();
            }
        });

        // Disconnecting nulls the stored tokens, so the column must allow it.
        Schema::table('flare_cloudflare_connections', function (Blueprint $table) {
            $table->text('access_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('flare_cloudflare_connections', function (Blueprint $table) {
            if (Schema::hasColumn('flare_cloudflare_connections', 'method')) {
                $table->dropColumn('method');
            }

            if (Schema::hasColumn('flare_cloudflare_connections', 'workers_subdomain')) {
                $table->dropColumn('workers_subdomain');
            }
        });
    }
};
