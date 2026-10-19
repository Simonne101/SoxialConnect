<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source vidéo du Clipper (ADR-049) : la référence YouTube (identifiant, propriété) est séparée du
 * fichier rendable (media_id), obtenu par une voie autorisée (export officiel, fournisseur sous
 * contrat, import de l'utilisateur). Une source par projet. Aucune donnée existante modifiée : les
 * projets antérieurs reçoivent leur source à la première acquisition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('video_project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_reference', 64);
            $table->string('source_type', 32);
            $table->string('ownership_status', 16)->default('unverified');
            $table->foreignUuid('ownership_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
            $table->timestamp('ownership_checked_at')->nullable();
            $table->string('acquisition_method', 32)->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignUuid('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('purge_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'purge_at']);
            $table->index(['provider', 'external_reference']);
        });
        SchemaConstraints::in('video_sources', 'provider', ['youtube']);
        SchemaConstraints::in('video_sources', 'source_type', ['youtube_video']);
        SchemaConstraints::in('video_sources', 'ownership_status', ['unverified', 'owned', 'not_owned']);
        SchemaConstraints::in('video_sources', 'status', ['pending', 'resolving', 'waiting_for_provider', 'ready', 'failed', 'expired', 'unavailable']);
    }

    public function down(): void
    {
        Schema::dropIfExists('video_sources');
    }
};
