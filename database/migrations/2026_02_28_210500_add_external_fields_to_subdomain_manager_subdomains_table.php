<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subdomain_manager_subdomains', function (Blueprint $table) {
            if (!Schema::hasColumn('subdomain_manager_subdomains', 'server_source')) {
                $table->string('server_source', 16)->default('local')->after('server_id');
                $table->index('server_source', 'subdomain_manager_subdomains_server_source_idx');
            }

            if (!Schema::hasColumn('subdomain_manager_subdomains', 'server_identifier')) {
                $table->string('server_identifier', 191)->nullable()->after('server_source');
                $table->index('server_identifier', 'subdomain_manager_subdomains_server_identifier_idx');
            }
        });

        DB::table('subdomain_manager_subdomains')
            ->whereNull('server_source')
            ->update(['server_source' => 'local']);
    }

    public function down(): void
    {
        Schema::table('subdomain_manager_subdomains', function (Blueprint $table) {
            if (Schema::hasColumn('subdomain_manager_subdomains', 'server_identifier')) {
                $table->dropIndex('subdomain_manager_subdomains_server_identifier_idx');
                $table->dropColumn('server_identifier');
            }

            if (Schema::hasColumn('subdomain_manager_subdomains', 'server_source')) {
                $table->dropIndex('subdomain_manager_subdomains_server_source_idx');
                $table->dropColumn('server_source');
            }
        });
    }
};

