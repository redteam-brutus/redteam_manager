<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_log_entries', function (Blueprint $table) {
            $table->string('browser_name')->nullable()->after('user_agent');
            $table->string('browser_version')->nullable()->after('browser_name');
            $table->string('os_name')->nullable()->after('browser_version');
            $table->string('os_version')->nullable()->after('os_name');
            $table->string('device_type', 32)->nullable()->after('os_version');
            $table->boolean('is_bot')->default(false)->after('device_type');

            $table->index('browser_name');
            $table->index('os_name');
            $table->index('device_type');
            $table->index('is_bot');
        });
    }

    public function down(): void
    {
        Schema::table('site_log_entries', function (Blueprint $table) {
            $table->dropIndex(['browser_name']);
            $table->dropIndex(['os_name']);
            $table->dropIndex(['device_type']);
            $table->dropIndex(['is_bot']);

            $table->dropColumn([
                'browser_name',
                'browser_version',
                'os_name',
                'os_version',
                'device_type',
                'is_bot',
            ]);
        });
    }
};
