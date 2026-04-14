<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('ssh_user');
            $table->foreignId('ssh_key_id')->nullable()->constrained('ssh_keys')->nullOnDelete();
            $table->string('host_fingerprint')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->string('last_connection_status')->nullable();
            $table->text('last_connection_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
