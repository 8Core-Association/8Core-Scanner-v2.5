/**
 * Plaćena licenca — 8Core Scanner
 * rules.js — rules admin page helpers
 */

var patternHints = {
  filename:      'npr. filefuns.php ili webshell*.php',
  path:          'npr. /tmp/.php ili /uploads/',
  regex:         'npr. .sys-.* (PHP preg_match regex)',
  regex_content: 'npr. shell_exec|passthru|proc_open',
  sha256:        '64 hex znaka, npr. a1b2c3d4…',
  chmod:         'npr. 777 ili 775',
  extension:     'npr. php PHP pHP (razdvojene razmakom)',
  filesize:      'npr. >1048576 (bytes) ili 0 za prazne',
};

function updatePatternHint() {
  var sel = document.getElementById('rule-type');
  var hint = document.getElementById('pattern-hint');
  if (sel && hint) {
    hint.textContent = patternHints[sel.value] || '';
  }
}

document.addEventListener('DOMContentLoaded', function() {
  updatePatternHint();
});
