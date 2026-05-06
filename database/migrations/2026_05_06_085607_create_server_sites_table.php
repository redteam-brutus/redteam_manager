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
        Schema::create('server_sites', function (Blueprint $table) {
            $table->foreignId('server_id')
                ->constrained('servers')
                ->cascadeOnDelete();
            $table->string('site_id');
            $table->json('domains')->default('[]');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->primary(['server_id', 'site_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_sites');
    }
};
