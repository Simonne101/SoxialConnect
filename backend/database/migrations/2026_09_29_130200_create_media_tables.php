<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/database.md §3.4 — MEDIA.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('media_folders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
            // [MySQL] NULLS NOT DISTINCT émulé par colonne générée VIRTUAL (§1.1).
            $table->char('parent_key', 36)->virtualAs("COALESCE(`parent_id`, '')");
            $table->unique(['user_id', 'parent_key', 'name'], 'media_folders_name_unique');
        });

        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('type', 16);
            $table->string('status', 16)->default('uploading');
            $table->string('original_name');
            $table->string('disk', 32)->default('media');
            $table->string('path', 512);
            $table->string('mime_type', 127)->nullable();
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('scan_status', 16)->default('pending');
            $table->dateTime('scanned_at')->nullable();
            $table->json('derivatives')->nullable();
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'type', 'status']);
            $table->index(['user_id', 'folder_id']);
            $table->index(['user_id', 'checksum_sha256']);
        });
        SchemaConstraints::in('media', 'type', ['image', 'video', 'audio', 'document']);
        SchemaConstraints::in('media', 'status', ['uploading', 'processing', 'ready', 'failed', 'deleted']);
        SchemaConstraints::in('media', 'scan_status', ['pending', 'clean', 'infected', 'error']);
        SchemaConstraints::jsonArrayIndex('media', 'tags');
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `media` ADD FULLTEXT `media_original_name_fulltext` (`original_name`)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('media_folders');
    }
};
