<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/database.md §3.8 — journal d'audit, en ajout seul (append-only).
// En production, le rôle SQL applicatif n'aura que INSERT et SELECT sur cette table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // SET NULL : la trace survit à la suppression du compte (pseudonymisée).
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 16)->default('user');
            // Liste des actions : enum PHP App\Services\Audit\AuditAction (pas de CHECK : liste évolutive).
            $table->string('action', 64);
            // Polymorphe sans clé étrangère, volontairement (la trace survit au sujet).
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->uuid('request_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
        SchemaConstraints::in('audit_logs', 'actor_type', ['user', 'system', 'admin']);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
