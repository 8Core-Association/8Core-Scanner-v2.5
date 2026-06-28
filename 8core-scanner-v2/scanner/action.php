<?php
/**
 * 8Core Scanner v2.0 — Akcije na nalazima
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/includes/auth.php';
require_login();

$action = isset($_POST['action']) ? $_POST['action'] : '';
$note   = isset($_POST['note'])   ? trim($_POST['note']) : '';

$allowed = ['ignore', 'checked', 'quarantine_requested', 'delete_requested', 'new'];

if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    $ids = array_filter($ids, fn($v) => $v > 0);
} elseif (!empty($_POST['id'])) {
    $ids = [(int)$_POST['id']];
} else {
    $ids = [];
}

if (empty($ids)) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

$user = current_user();

foreach ($ids as $id) {
    if (!can_access_finding($pdo, $id)) continue;

    $pdo->prepare("
        UPDATE findings
        SET action_status = ?, action_note = ?, action_at = NOW(), action_by = ?
        WHERE id = ?
    ")->execute([$action, $note, $user['username'], $id]);

    $pdo->prepare("
        INSERT INTO scanner_actions (finding_id, action, note, created_at, created_by)
        VALUES (?, ?, ?, NOW(), ?)
    ")->execute([$id, $action, $note, $user['username']]);
}

$back = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
header('Location: ' . $back);
exit;
