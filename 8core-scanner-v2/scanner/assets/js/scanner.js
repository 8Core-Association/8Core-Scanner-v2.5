
function toggleEl(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.classList.toggle('open');
}

function toggleRow(id) {
  var row = document.querySelector('.data-row[data-id="' + id + '"]');
  var detail = document.getElementById('detail-' + id);
  if (!row || !detail) return;

  var expanded = row.classList.contains('row-expanded');
  row.classList.toggle('row-expanded', !expanded);
  detail.classList.toggle('hidden', expanded);
}

function toggleDrop(id, e) {
  e.stopPropagation();
  var el = document.getElementById(id);
  if (!el) return;
  var isOpen = el.classList.contains('open');
  closeAllDrops();
  if (!isOpen) el.classList.add('open');
}

function closeAllDrops() {
  document.querySelectorAll('.action-drop.open').forEach(function(d) {
    d.classList.remove('open');
  });
}

document.addEventListener('click', function(e) {
  if (e.target.type === 'checkbox') return;
  closeAllDrops();
});

document.addEventListener('submit', function(e) {
  var btn = e.submitter;
  if (!btn) return;

  if (btn.classList.contains('act-delete')) {
    if (!confirm('Označiti za brisanje? Fizičko brisanje izvršava root worker kasnije.')) {
      e.preventDefault();
    }
    return;
  }

  if (btn.classList.contains('act-quarantine')) {
    if (!confirm('Označiti za karantenu? Fizičko micanje izvršava root worker kasnije.')) {
      e.preventDefault();
    }
  }
});

/* ── BULK SELECTION ── */
function updateBulkBar() {
  var checked = document.querySelectorAll('.row-chk:checked');
  var bar = document.getElementById('bulk-bar');
  var countEl = document.getElementById('bulk-count');
  var allChk = document.getElementById('chk-all');

  if (!bar) return;

  var total = document.querySelectorAll('.row-chk').length;

  if (checked.length > 0) {
    bar.classList.add('visible');
    countEl.textContent = checked.length + ' odabrano';
  } else {
    bar.classList.remove('visible');
  }

  if (allChk) {
    allChk.indeterminate = checked.length > 0 && checked.length < total;
    allChk.checked = checked.length === total && total > 0;
  }

  document.querySelectorAll('.data-row').forEach(function(row) {
    var chk = row.querySelector('.row-chk');
    row.classList.toggle('row-selected', chk && chk.checked);
  });
}

function toggleAll(master) {
  document.querySelectorAll('.row-chk').forEach(function(chk) {
    chk.checked = master.checked;
  });
  updateBulkBar();
}

function clearSelection() {
  document.querySelectorAll('.row-chk').forEach(function(chk) {
    chk.checked = false;
  });
  var allChk = document.getElementById('chk-all');
  if (allChk) { allChk.checked = false; allChk.indeterminate = false; }
  updateBulkBar();
}

function submitBulk() {
  var action = document.getElementById('bulk-action');
  var checked = document.querySelectorAll('.row-chk:checked');

  if (!action || action.value === '') {
    alert('Odaberi akciju prije primjene.');
    return;
  }
  if (checked.length === 0) {
    alert('Nijedan nalaz nije odabran.');
    return;
  }
  if (!confirm('Primijeniti "' + action.value + '" na ' + checked.length + ' nalaza?')) {
    return;
  }

  var form = document.createElement('form');
  form.method = 'post';
  form.action = 'action.php';

  var actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = action.value;
  form.appendChild(actionInput);

  checked.forEach(function(chk) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'ids[]';
    input.value = chk.value;
    form.appendChild(input);
  });

  document.body.appendChild(form);
  form.submit();
}
