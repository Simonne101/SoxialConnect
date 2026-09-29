<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/database.md §3.2 — SOCIAL (option B de l'ADR-019 : pas de table social_connections).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_platforms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 32)->unique();
            $table->string('name', 64);
            $table->string('availability', 32)->default('available');
            $table->json('capabilities')->nullable();
            $table->string('api_version', 32)->nullable();
            $table->timestamps();
        });
        SchemaConstraints::in('social_platforms', 'availability', ['available', 'coming_soon', 'disabled']);

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_platform_id')->constrained()->restrictOnDelete();
            $table->string('external_account_id', 191);
            $table->string('account_type', 32)->nullable();
            $table->string('display_name')->nullable();
            $table->string('username')->nullable();
            $table->string('avatar_media_path', 512)->nullable();
            $table->string('status', 32)->default('connected');
            $table->string('status_reason')->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['user_id', 'social_platform_id', 'external_account_id'], 'social_accounts_owner_external_unique');
            $table->index(['user_id', 'status']);
        });
        SchemaConstraints::in('social_accounts', 'status', ['connected', 'expired', 'revoked', 'error']);
        SchemaConstraints::check('social_accounts', 'social_accounts_account_type_check',
            "`account_type` IS NULL OR `account_type` IN ('profile','page','business','creator','channel','organization','board_owner')");

        // Donnée critique : valeurs chiffrées côté application (clé dédiée, ADR-008).
        Schema::create('social_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('token_kind', 16)->default('user');
            $table->text('access_token_encrypted');
            $table->text('refresh_token_encrypted')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('refresh_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('token_type', 32)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('encryption_key_version')->default(1);
            $table->dateTime('last_refreshed_at')->nullable();
            $table->timestamps();
            // [MySQL] unicité partielle : un seul token actif par compte et par type (§1.1).
            $table->tinyInteger('active_marker')->nullable()->storedAs("IF(`status` = 'active', 1, NULL)");
            $table->unique(['social_account_id', 'token_kind', 'active_marker'], 'social_tokens_one_active_unique');
            $table->index(['status', 'expires_at']);
        });
        SchemaConstraints::in('social_tokens', 'token_kind', ['user', 'page', 'app']);
        SchemaConstraints::in('social_tokens', 'status', ['active', 'expired', 'revoked', 'invalid']);

        Schema::create('social_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 128);
            $table->string('status', 16);
            $table->boolean('is_required')->default(true);
            $table->dateTime('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'scope']);
        });
        SchemaConstraints::in('social_permissions', 'status', ['granted', 'declined', 'missing']);

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_platform_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_event_id', 191)->nullable();
            $table->char('idempotency_key', 64)->unique();
            $table->string('event_type', 128);
            $table->boolean('signature_valid')->default(true);
            $table->json('payload')->nullable();
            $table->dateTime('received_at');
            $table->string('status', 16)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('processed_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            // NULL non contraint par MySQL : seuls les événements porteurs d'un identifiant sont dédoublonnés ici.
            $table->unique(['social_platform_id', 'external_event_id'], 'webhook_events_platform_external_unique');
            $table->index(['status', 'received_at']);
        });
        SchemaConstraints::in('webhook_events', 'status', ['received', 'queued', 'processing', 'processed', 'failed', 'ignored']);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('social_permissions');
        Schema::dropIfExists('social_tokens');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('social_platforms');
    }
};
