<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * État de la collecte des statistiques (étape 7, P1 — ADR-063) : une ligne par compte (post_variant_id null)
 * ou par publication suivie. Distingue une synchronisation échouée (code, dernière tentative) d'une métrique
 * réellement à 0 ou non fournie (NULL), qui restent dans `analytics`. Purement additive : `analytics` et
 * `analytics_snapshots` sont inchangées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sync_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->timestamps();
            // Même mécanisme que `analytics` : unicité valable aussi pour l'état du compte (post_variant_id null).
            $table->char('post_variant_key', 36)->virtualAs("COALESCE(`post_variant_id`, '')");
            $table->unique(['social_account_id', 'post_variant_key'], 'analytics_sync_state_unique');
            $table->index(['status', 'next_attempt_at']);
        });
        SchemaConstraints::in('analytics_sync_states', 'status', ['pending', 'running', 'succeeded', 'failed', 'unsupported']);
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sync_states');
    }
};
