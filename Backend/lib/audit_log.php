<?php
/**
 * Backend/lib/audit_log.php — ZURUBANK
 *
 * One audit_logs write that can never undo the money movement it records.
 *
 * Callers on the hold, debit and release paths write their audit row INSIDE
 * the transaction that moves the money. A failed INSERT would abort that
 * transaction, so the write runs in a savepoint:
 *
 *   1. insert the row as given (performed_by = who did it, e.g. "VOUCHMORPH");
 *   2. if the column only takes a whole number (backup.sql has
 *      audit_logs.performed_by INTEGER NOT NULL), insert it again with
 *      performed_by = 0 and the name kept in new_value as "performed_by";
 *   3. if that is refused too, log one line and let the money movement stand.
 *
 * The row is: entity, entity_id, action, category, severity, old_value,
 * new_value (array or JSON text), performed_by, ip_address, user_agent.
 */

declare(strict_types=1);

const AUDIT_NUMERIC_ACTOR = 0; // what goes in an integer performed_by when the actor is a name

/** @return bool TRUE if a row was written */
function audit_log_write(PDO $pdo, array $row): bool
{
    $actor = (string)($row['performed_by'] ?? 'SYSTEM');
    $newValue = $row['new_value'] ?? null;
    if (is_array($newValue)) {
        $newValue = json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $oldValue = $row['old_value'] ?? null;
    if (is_array($oldValue)) {
        $oldValue = json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $insert = function ($performedBy, ?string $newValueText) use ($pdo, $row, $oldValue): void {
        $pdo->prepare("
            INSERT INTO audit_logs
                (entity, entity_id, action, category, severity,
                 old_value, new_value, performed_by, performed_at, ip_address, user_agent)
            VALUES
                (:entity, :entity_id, :action, :category, :severity,
                 :old_value, :new_value, :performed_by, NOW(), :ip_address, :user_agent)
        ")->execute([
            'entity'       => $row['entity'],
            'entity_id'    => $row['entity_id'],
            'action'       => $row['action'],
            'category'     => $row['category'] ?? 'financial',
            'severity'     => $row['severity'] ?? 'info',
            'old_value'    => $oldValue,
            'new_value'    => $newValueText,
            'performed_by' => $performedBy,
            'ip_address'   => isset($row['ip_address']) ? substr((string)$row['ip_address'], 0, 50) : null,
            'user_agent'   => $row['user_agent'] ?? null,
        ]);
    };

    $attempts = [[$actor, $newValue]];
    if (!ctype_digit($actor)) {
        // Second try for an integer column: keep who it was inside new_value.
        $decoded = is_string($newValue) ? json_decode($newValue, true) : null;
        $withActor = is_array($decoded) ? $decoded : ($newValue === null ? [] : ['value' => $newValue]);
        $withActor['performed_by'] = $actor;
        $attempts[] = [AUDIT_NUMERIC_ACTOR, json_encode($withActor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    $inTx = $pdo->inTransaction();
    $last = null;
    foreach ($attempts as $i => [$performedBy, $text]) {
        try {
            if ($inTx) {
                $pdo->exec('SAVEPOINT audit_log_write');
            }
            $insert($performedBy, $text);
            if ($inTx) {
                $pdo->exec('RELEASE SAVEPOINT audit_log_write');
            }
            return true;
        } catch (Throwable $e) {
            $last = $e;
            if ($inTx) {
                try { $pdo->exec('ROLLBACK TO SAVEPOINT audit_log_write'); } catch (Throwable $ignore) {}
            }
            // Only a refused performed_by value is worth a second try.
            $sqlState = $e instanceof PDOException ? (string)$e->getCode() : '';
            if ($i === 0 && $sqlState !== '22P02') {
                break;
            }
        }
    }

    error_log(sprintf('[audit_log] audit row not written for %s %s %s (the operation itself stands): %s',
        $row['entity'] ?? '?', $row['entity_id'] ?? '?', $row['action'] ?? '?',
        $last ? substr(preg_replace('/\s+/', ' ', $last->getMessage()), 0, 300) : 'unknown'));
    return false;
}
