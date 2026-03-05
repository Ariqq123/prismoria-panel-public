<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subdomain_manager_subdomains', function (Blueprint $table) {
            if (!Schema::hasColumn('subdomain_manager_subdomains', 'dns_record_name')) {
                $table->string('dns_record_name', 191)->nullable()->after('record_type');
            }

            if (!Schema::hasColumn('subdomain_manager_subdomains', 'srv_service')) {
                $table->string('srv_service', 63)->nullable()->after('dns_record_name');
            }

            if (!Schema::hasColumn('subdomain_manager_subdomains', 'srv_protocol_type')) {
                $table->string('srv_protocol_type', 16)->nullable()->after('srv_service');
            }

            if (!Schema::hasColumn('subdomain_manager_subdomains', 'srv_priority')) {
                $table->unsignedInteger('srv_priority')->nullable()->after('srv_protocol_type');
            }

            if (!Schema::hasColumn('subdomain_manager_subdomains', 'srv_weight')) {
                $table->unsignedInteger('srv_weight')->nullable()->after('srv_priority');
            }

            if (!Schema::hasColumn('subdomain_manager_subdomains', 'srv_port')) {
                $table->unsignedInteger('srv_port')->nullable()->after('srv_weight');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subdomain_manager_subdomains', function (Blueprint $table) {
            if (Schema::hasColumn('subdomain_manager_subdomains', 'srv_port')) {
                $table->dropColumn('srv_port');
            }

            if (Schema::hasColumn('subdomain_manager_subdomains', 'srv_weight')) {
                $table->dropColumn('srv_weight');
            }

            if (Schema::hasColumn('subdomain_manager_subdomains', 'srv_priority')) {
                $table->dropColumn('srv_priority');
            }

            if (Schema::hasColumn('subdomain_manager_subdomains', 'srv_protocol_type')) {
                $table->dropColumn('srv_protocol_type');
            }

            if (Schema::hasColumn('subdomain_manager_subdomains', 'srv_service')) {
                $table->dropColumn('srv_service');
            }

            if (Schema::hasColumn('subdomain_manager_subdomains', 'dns_record_name')) {
                $table->dropColumn('dns_record_name');
            }
        });
    }
};

