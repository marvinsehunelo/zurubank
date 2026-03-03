<?php
function velocity_check($pdo, $wallet_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM wallet_locks
        WHERE wallet_id=? AND created_at > NOW() - INTERVAL '1 hour'
    ");
    $stmt->execute([$wallet_id]);
    $count = $stmt->fetchColumn();

    if ($count > 5) {
        return false;
    }
    return true;
}
?>
