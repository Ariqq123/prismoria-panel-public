<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('external_panel_connections', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('name')->nullable();
            $table->string('panel_url');
            $table->text('api_key_encrypted');
            $table->boolean('default_connection')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'default_connection'], 'external_panel_connections_user_default_idx');
        });

        Schema::create('external_servers_cache', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('external_panel_connection_id');
            $table->string('external_server_identifier');
            $table->string('name');
            $table->string('node')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('external_panel_connection_id')->references('id')->on('external_panel_connections')->onDelete('cascade');
            $table->unique(['external_panel_connection_id', 'external_server_identifier'], 'external_servers_cache_conn_identifier_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_servers_cache');
        Schema::dropIfExists('external_panel_connections');
    }
};
