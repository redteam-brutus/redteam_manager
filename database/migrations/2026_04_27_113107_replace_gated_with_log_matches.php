<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHUNK = 1000;

    public function up(): void
    {
        Schema::create('site_log_entry_log_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_log_entry_id')->constrained('site_log_entries')->cascadeOnDelete();
            $table->string('log_slug', 32);
            $table->timestamps();

            $table->unique(['site_log_entry_id', 'log_slug'], 'sle_log_matches_entry_slug_unique');
            $table->index(['log_slug', 'site_log_entry_id'], 'sle_log_matches_slug_entry_index');
        });

        $now = now();

        DB::table('site_log_entries')
            ->select(['id', 'gated'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use ($now): void {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        'site_log_entry_id' => $row->id,
                        'log_slug' => 'access',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ((bool) $row->gated) {
                        $payload[] = [
                            'site_log_entry_id' => $row->id,
                            'log_slug' => 'gate',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($payload !== []) {
                    DB::table('site_log_entry_log_matches')->upsert(
                        $payload,
                        ['site_log_entry_id', 'log_slug'],
                        ['updated_at']
                    );
                }
            });

        Schema::table('site_log_entries', function (Blueprint $table): void {
            $table->dropColumn('gated');
        });
    }

    public function down(): void
    {
        Schema::table('site_log_entries', function (Blueprint $table): void {
            $table->boolean('gated')->default(false);
        });

        DB::statement('UPDATE site_log_entries SET gated = TRUE WHERE id IN (SELECT site_log_entry_id FROM site_log_entry_log_matches WHERE log_slug = ?)', ['gate']);

        Schema::dropIfExists('site_log_entry_log_matches');
    }
};
