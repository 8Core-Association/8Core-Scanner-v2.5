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
  if (sel && hint) hint.textContent = patternHints[sel.value] || '';
}

/* ── Bulk selection ── */

function _getChecked() {
  return Array.from(document.querySelectorAll('.row-check:checked'));
}

function _updateBulkBar() {
  var checked  = _getChecked();
  var all      = document.querySelectorAll('.row-check');
  var bar      = document.getElementById('bulk-bar');
  var countEl  = document.getElementById('bulk-count');
  var checkAll = document.getElementById('check-all');

  if (!bar) return;

  if (checked.length > 0) {
    bar.style.display = 'flex';
    countEl.textContent = checked.length + ' odabrano';
  } else {
    bar.style.display = 'none';
  }

  if (checkAll) {
    checkAll.checked       = all.length > 0 && checked.length === all.length;
    checkAll.indeterminate = checked.length > 0 && checked.length < all.length;
  }

  // Highlight selected rows
  document.querySelectorAll('#rules-table tbody tr[data-id]').forEach(function (tr) {
    var cb = tr.querySelector('.row-check');
    if (cb && cb.checked) tr.classList.add('row-selected');
    else                  tr.classList.remove('row-selected');
  });
}

function clearBulkSelection() {
  document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = false; });
  var ca = document.getElementById('check-all');
  if (ca) { ca.checked = false; ca.indeterminate = false; }
  _updateBulkBar();
}

function bulkSubmit(action) {
  var checked = _getChecked();
  if (!checked.length) return;

  if (action === 'delete' &&
      !confirm('Obrisati ' + checked.length + ' odabranih pravila?\nOva akcija je nepovratna.')) return;

  var form       = document.getElementById('bulk-form');
  var actionInp  = document.getElementById('bulk-action-input');
  var idsWrapper = document.getElementById('bulk-ids');

  if (!form || !actionInp || !idsWrapper) return;

  actionInp.value = action;
  idsWrapper.innerHTML = '';
  checked.forEach(function (cb) {
    var inp = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = 'ids[]';
    inp.value = cb.dataset.id;
    idsWrapper.appendChild(inp);
  });

  form.submit();
}

document.addEventListener('DOMContentLoaded', function () {
  updatePatternHint();

  var checkAll = document.getElementById('check-all');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      document.querySelectorAll('.row-check').forEach(function (cb) {
        cb.checked = checkAll.checked;
      });
      _updateBulkBar();
    });
  }

  document.querySelectorAll('.row-check').forEach(function (cb) {
    cb.addEventListener('change', _updateBulkBar);
  });
});
