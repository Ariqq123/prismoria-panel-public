<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subdomain_manager_domains', function (Blueprint $table) {
            if (!Schema::hasColumn('subdomain_manager_domains', 'provider')) {
                $table->string('provider', 32)->default('cloudflare')->after('domain');
                $table->index('provider', 'subdomain_manager_domains_provider_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subdomain_manager_domains', function (Blueprint $table) {
            if (Schema::hasColumn('subdomain_manager_domains', 'provider')) {
                $table->dropIndex('subdomain_manager_domains_provider_idx');
                $table->dropColumn('provider');
            }
        });
    }
};

