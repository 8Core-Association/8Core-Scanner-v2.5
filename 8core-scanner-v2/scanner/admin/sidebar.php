<?php
/**
 * 8Core Scanner v2.0 — Admin sidebar
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
$currentFile = basename($_SERVER['PHP_SELF']);
function sb_active($file) {
    global $currentFile;
    return $currentFile === $file ? ' active' : '';
}
?>
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <span class="logo-text">8Core Scanner</span>
    </div>
    <div class="logo-version">Admin Panel v2.0</div>
  </div>

  <nav class="sidebar-nav">
    <div class="sidebar-section-label">Admin</div>

    <a class="sidebar-link<?= sb_active('index.php') ?>" href="index.php">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>

    <div class="sidebar-section-label" style="margin-top:14px;">Korisnici &amp; Config</div>

    <a class="sidebar-link<?= sb_active('users.php') ?>" href="users.php">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Korisnici
    </a>

    <div class="sidebar-section-label" style="margin-top:14px;">Scanner</div>

    <a class="sidebar-link<?= sb_active('rules.php') ?>" href="rules.php">
      <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      Pravila i definicije
    </a>

    <a class="sidebar-link<?= sb_active('ignore.php') ?>" href="ignore.php">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
      Ignore lista
    </a>

    <div class="sidebar-section-label" style="margin-top:14px;">Podaci</div>

    <a class="sidebar-link" href="../index.php">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Nalazi
    </a>

    <a class="sidebar-link" href="../scan.php">
      <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      Skeniranja
    </a>

    <div class="sidebar-section-label" style="margin-top:14px;">Sustav</div>

    <a class="sidebar-link<?= sb_active('update.php') ?>" href="update.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
      Update
    </a>

    <a class="sidebar-link<?= sb_active('changelog.php') ?>" href="changelog.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Changelog
    </a>

    <a class="sidebar-link<?= sb_active('about.php') ?>" href="about.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      O scanneru
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= h(mb_strtoupper(mb_substr(current_user()['username'], 0, 1))) ?></div>
      <div class="user-info">
        <div class="user-name"><?= h(current_user()['username']) ?></div>
        <div class="user-role">admin</div>
      </div>
    </div>
  </div>
</aside>
