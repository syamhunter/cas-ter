<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class AuditLogger
{
    public static function record(
        PDO $pdo,
        int $tenantId,
        int $branchId,
        int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        array $details = []
    ): void {
        $json = $details === []
            ? null
            : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO audit_logs
                (tenant_id, branch_id, user_id, action_code, entity_type, entity_id, details)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$tenantId, $branchId, $userId, $action, $entityType, $entityId, $json]);
    }
}
