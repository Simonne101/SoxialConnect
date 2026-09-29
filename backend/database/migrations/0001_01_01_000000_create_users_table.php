<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/database.md §3.1 — users (+ tables techniques d'authentification Laravel).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            // Collation utf8mb4_unicode_ci : unicité insensible à la casse ; e-mail normalisé en minuscules à l'écriture.
            $table->string('email', 254)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            // Profil affiché dans l'interface (ADR-028) : « Activité » et « Présentation ».
            $table->string('headline', 60)->nullable();
            $table->string('bio', 280)->nullable();
            $table->string('timezone', 64)->default('Europe/Paris');
            $table->string('locale', 10)->default('fr');
            $table->string('status', 32)->default('active');
            $table->dateTime('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        SchemaConstraints::in('users', 'status', ['active', 'suspended']);

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 254)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
