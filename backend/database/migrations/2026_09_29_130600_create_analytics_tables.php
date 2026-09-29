<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/database.md §3.7 — ANALYTICS (brut + normalisé ; métrique NULL = non fournie, jamais 0).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 16);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('api_version', 32)->nullable();
            $table->json('payload');
            $table->dateTime('fetched_at');
            $table->timestamps();
            $table->index(['social_account_id', 'fetched_at']);
        });
        SchemaConstraints::in('analytics_snapshots', 'scope', ['account', 'post']);

        Schema::create('analytics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('snapshot_id')->nullable()->constrained('analytics_snapshots')->nullOnDelete();
            $table->date('metric_date');
            $table->string('granularity', 16)->default('day');
            foreach (['impressions', 'reach', 'views', 'engagements', 'clicks', 'likes', 'comments', 'shares', 'saves', 'followers'] as $metric) {
                $table->unsignedBigInteger($metric)->nullable();
            }
            $table->integer('followers_delta')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
            $table->char('post_variant_key', 36)->virtualAs("COALESCE(`post_variant_id`, '')");
            $table->unique(['social_account_id', 'post_variant_key', 'metric_date', 'granularity'], 'analytics_point_unique');
            $table->index(['social_account_id', 'metric_date']);
        });
        SchemaConstraints::in('analytics', 'granularity', ['day', 'lifetime']);
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics');
        Schema::dropIfExists('analytics_snapshots');
    }
};
