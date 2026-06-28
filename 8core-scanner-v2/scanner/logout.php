<?php
/**
 * 8Core Scanner v2.0 — Odjava
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
require __DIR__ . '/includes/auth.php';
logout_user();
header('Location: login.php');
exit;
