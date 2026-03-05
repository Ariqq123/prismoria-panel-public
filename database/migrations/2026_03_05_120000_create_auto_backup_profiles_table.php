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
        Schema::create('auto_backup_profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('server_identifier');
            $table->string('name')->nullable();
            $table->string('destination_type', 32);
            $table->longText('destination_config_encrypted');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('interval_minutes')->default(360);
            $table->unsignedInteger('keep_remote')->default(10);
            $table->boolean('is_locked')->default(false);
            $table->text('ignored_files')->nullable();
            $table->string('pending_backup_uuid')->nullable();
            $table->string('last_backup_uuid')->nullable();
            $table->json('uploaded_objects_json')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'server_identifier'], 'auto_backup_profiles_user_server_idx');
            $table->index(['is_enabled', 'next_run_at'], 'auto_backup_profiles_enabled_next_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_backup_profiles');
    }
};

