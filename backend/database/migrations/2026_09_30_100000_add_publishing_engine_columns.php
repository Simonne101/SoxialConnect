<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moteur de publication interne (docs/publishing.md) :
 * - post_variants : horodatage de mise en file et compteur de tentatives (jeton d'exécution
 *   des jobs : un job périmé ou dupliqué ne s'exécute jamais) ;
 * - publication_attempts : origine de la tentative, compte ciblé au moment de l'envoi,
 *   identifiant externe renvoyé par le réseau.
 * Aucune cascade ajoutée (ADR-034) : clés étrangères sans ON DELETE CASCADE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_variants', function (Blueprint $table) {
            $table->dateTime('queued_at')->nullable()->after('scheduled_at');
            $table->unsignedInteger('attempts_count')->default(0)->after('queued_at');
        });

        Schema::table('publication_attempts', function (Blueprint $table) {
            $table->foreignUuid('social_account_id')->nullable()->after('post_variant_id')->constrained()->nullOnDelete();
            $table->string('trigger', 16)->default('manual')->after('attempt_number');
            $table->foreignUuid('triggered_by')->nullable()->after('trigger')->constrained('users')->nullOnDelete();
            $table->string('external_post_id', 191)->nullable()->after('error_message');
            $table->index(['post_variant_id', 'status']);
        });
        SchemaConstraints::in('publication_attempts', 'trigger', ['schedule', 'manual', 'retry', 'auto_retry', 'recovery']);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `publication_attempts` DROP CHECK `publication_attempts_trigger_check`');
        }
        Schema::table('publication_attempts', function (Blueprint $table) {
            $table->dropIndex(['post_variant_id', 'status']);
            $table->dropConstrainedForeignId('triggered_by');
            $table->dropConstrainedForeignId('social_account_id');
            $table->dropColumn(['trigger', 'external_post_id']);
        });

        Schema::table('post_variants', function (Blueprint $table) {
            $table->dropColumn(['queued_at', 'attempts_count']);
        });
    }
};
