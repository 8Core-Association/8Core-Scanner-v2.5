<?php
/**
 * 8Core Scanner v2.0 — Autentikacija i ovlasti
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function current_user() {
    return isset($_SESSION['scanner_user']) ? $_SESSION['scanner_user'] : null;
}

function is_logged_in() {
    return current_user() !== null;
}

function is_admin() {
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function user_accounts() {
    $u = current_user();
    if (!$u) return [];
    return isset($u['accounts']) ? $u['accounts'] : [];
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . str_repeat('../', substr_count(ltrim($_SERVER['PHP_SELF'], '/'), '/') - substr_count(ltrim(dirname($_SERVER['SCRIPT_NAME']), '/'), '/')) . 'login.php');
        exit;
    }
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function login_user(PDO $pdo, $username, $password) {
    $stmt = $pdo->prepare("SELECT * FROM scanner_users WHERE username = ? AND active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $acctStmt = $pdo->prepare("SELECT account_name FROM scanner_user_accounts WHERE user_id = ? ORDER BY account_name");
    $acctStmt->execute([$user['id']]);
    $accounts = $acctStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($accounts) && !empty($user['account_name'])) {
        $accounts = [$user['account_name']];
    }

    $_SESSION['scanner_user'] = [
        'id'           => (int)$user['id'],
        'username'     => $user['username'],
        'email'        => $user['email'] ?? '',
        'role'         => $user['role'],
        'account_name' => $user['account_name'],
        'accounts'     => $accounts,
    ];

    $upd = $pdo->prepare("UPDATE scanner_users SET last_login = NOW() WHERE id = ?");
    $upd->execute([$user['id']]);

    return true;
}

function logout_user() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
}

function can_access_finding(PDO $pdo, $findingId) {
    if (is_admin()) return true;

    $accounts = user_accounts();
    if (empty($accounts)) return false;

    $stmt = $pdo->prepare("SELECT account_name, owner_name FROM findings WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$findingId]);
    $f = $stmt->fetch();

    if (!$f) return false;

    $acc = $f['account_name'] ?: $f['owner_name'];
    return in_array($acc, $accounts, true);
}
