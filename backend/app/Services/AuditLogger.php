<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Registra en audit_logs las decisiones administrativas: quién (admin_id),
 * sobre quién (user_id), qué acción, el motivo y los datos de contexto.
 */
class AuditLogger
{
    public const PAYMENT_VALIDATED = 'payment_validated';

    public const PAYMENT_REJECTED = 'payment_rejected';

    public const ENROLLMENT_APPROVED = 'enrollment_approved';

    public const ENROLLMENT_REJECTED = 'enrollment_rejected';

    public const ENROLLMENT_REOPENED = 'enrollment_reopened';

    public function record(User $actor, int $subjectUserId, string $action, ?string $reason = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'admin_id' => $actor->id,
            'user_id' => $subjectUserId,
            'action' => $action,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
