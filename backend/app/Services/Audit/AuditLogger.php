<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Logging\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Seul point d'écriture du journal d'audit. Les métadonnées sont expurgées, et une panne
 * d'écriture ne doit jamais faire échouer l'action métier (elle est journalisée).
 */
final class AuditLogger
{
    public function __construct(
        private readonly Request $request,
        private readonly SecretRedactor $redactor,
    ) {}

    public function record(
        AuditAction $action,
        ?User $user = null,
        ?Model $subject = null,
        array $metadata = [],
        string $actorType = 'user',
    ): void {
        try {
            AuditLog::create([
                'user_id' => $user?->getKey(),
                'actor_type' => $actorType,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'ip_address' => $this->request->ip(),
                'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
                'request_id' => Context::get('request_id'),
                'metadata' => $metadata ? $this->redactor->redact($metadata) : null,
            ]);
        } catch (Throwable $e) {
            Log::error('audit.write_failed', ['action' => $action->value, 'exception' => $e->getMessage()]);
        }
    }
}
