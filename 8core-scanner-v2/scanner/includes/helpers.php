<?php
/**
 * 8Core Scanner v2.5.3 — Pomoćne funkcije
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function risk_class($risk) {
    if ($risk === 'CRITICAL') return 'risk-critical';
    if ($risk === 'HIGH')     return 'risk-high';
    if ($risk === 'MEDIUM')   return 'risk-medium';
    return 'risk-low';
}

function action_class($status) {
    if ($status === 'ignore')               return 'status-ignore';
    if ($status === 'quarantine_requested') return 'status-quarantine';
    if ($status === 'quarantined')          return 'status-quarantined';
    if ($status === 'delete_requested')     return 'status-delete';
    if ($status === 'checked')              return 'status-checked';
    if ($status === 'restore_requested')    return 'status-restore';
    if ($status === 'restored')             return 'status-restored';
    if ($status === 'restore_failed')       return 'status-failed';
    return 'status-new';
}

function has_column(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
}

function flash_message() {
    if (!empty($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $msg;
    }
    return '';
}

// ── CSRF zaštita ─────────────────────────────────────────────────────────────

function csrf_enabled(): bool {
    global $config;

    if (isset($config['csrf_enabled']) && $config['csrf_enabled'] === false) {
        return false;
    }

    return true;
}

function csrf_token(): string {
    if (!csrf_enabled()) {
        return '';
    }

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    if (!csrf_enabled()) {
        return '';
    }

    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void {
    if (!csrf_enabled()) {
        return;
    }

    $submitted = $_POST['csrf_token'] ?? '';

    if (!hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        echo 'Nevažeći CSRF token. Osvježi stranicu i pokušaj ponovo.';
        exit;
    }
}
