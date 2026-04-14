<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('site_id');
            $table->string('request_id');
            $table->timestampTz('occurred_at');
            $table->boolean('gated')->default(false);
            $table->string('host')->nullable();
            $table->string('remote_addr')->nullable();
            $table->text('uri')->nullable();
            $table->text('request_uri')->nullable();
            $table->string('fbclid')->nullable();
            $table->text('user_agent')->nullable();
            $table->char('iso_country', 2)->nullable();
            $table->string('prefetch')->nullable();
            $table->string('turbolink')->nullable();
            $table->text('sec_ch_ua')->nullable();
            $table->string('sec_ch_ua_platform')->nullable();
            $table->string('sec_ch_ua_mobile')->nullable();
            $table->text('raw_line');
            $table->timestampTz('imported_at')->useCurrent();
            $table->timestamps();

            $table->unique('request_id');
            $table->index(['server_id', 'site_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_log_entries');
    }
};
