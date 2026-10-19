document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.getElementById('sidebar');
  const shade = document.querySelector('[data-menu-close]');
  const menuButton = document.querySelector('[data-menu-open]');
  const accountMenu = document.querySelector('.account-menu');
  const mainShell = document.querySelector('.main-shell');
  let previousFocus;
  const closeMenu = () => {
    const wasOpen = sidebar?.classList.contains('open');
    sidebar?.classList.remove('open');
    document.body.classList.remove('menu-open');
    if (shade) shade.hidden = true;
    if (mainShell) mainShell.inert = false;
    menuButton?.setAttribute('aria-expanded', 'false');
    if (wasOpen && previousFocus?.isConnected) previousFocus.focus();
  };
  menuButton?.addEventListener('click', () => {
    previousFocus = menuButton;
    sidebar?.classList.add('open');
    document.body.classList.add('menu-open');
    if (shade) shade.hidden = false;
    if (mainShell) mainShell.inert = true;
    menuButton.setAttribute('aria-expanded', 'true');
    sidebar?.querySelector('[data-sidebar-close]')?.focus();
  });
  document.querySelector('[data-sidebar-close]')?.addEventListener('click', closeMenu);
  shade?.addEventListener('click', closeMenu);
  document.addEventListener('click', e => {
    if (accountMenu?.open && !accountMenu.contains(e.target)) accountMenu.open = false;
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      closeMenu();
      if (accountMenu?.open) {
        accountMenu.open = false;
        accountMenu.querySelector('summary')?.focus();
      }
    }
    if (e.key === 'Tab' && sidebar?.classList.contains('open')) {
      const items = Array.from(sidebar.querySelectorAll('a[href],button:not([disabled])')).filter(item => item.getClientRects().length);
      const first = items[0], last = items.at(-1);
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
    }
  });
  window.matchMedia('(min-width: 981px)').addEventListener('change', e => { if (e.matches) closeMenu(); });
  // Keep table headers on desktop; use the same labels on record cards on phones.
  document.querySelectorAll('.table-wrap table').forEach(table => {
    const headers = Array.from(table.querySelectorAll('thead th'));
    if (!headers.length) return;
    table.classList.add('mobile-cards');
    table.setAttribute('role', 'table');
    table.querySelectorAll('thead,tbody').forEach(group => group.setAttribute('role', 'rowgroup'));
    table.querySelectorAll('tr').forEach(row => row.setAttribute('role', 'row'));
    headers.forEach(header => { header.setAttribute('scope', 'col'); header.setAttribute('role', 'columnheader'); });
    table.querySelectorAll('tbody tr').forEach(row => {
      Array.from(row.cells).forEach((cell, index) => {
        cell.setAttribute('role', 'cell');
        if (cell.colSpan > 1) return;
        const label = headers[index]?.textContent.trim() ?? '';
        cell.dataset.label = label;
        if (label === 'ACTIONS' || (index === row.cells.length - 1 && !label)) cell.classList.add('mobile-record-actions');
      });
    });
  });
  const activate = name => {
    document.querySelectorAll('[data-tab]').forEach(b => { b.classList.toggle('active', b.dataset.tab === name); b.setAttribute('aria-selected', String(b.dataset.tab === name)); });
    document.querySelectorAll('[data-tab-panel]').forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
  };
  document.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => activate(b.dataset.tab)));
  const invalidPanel = document.querySelector('[data-tab-panel] [data-validation-error]')?.closest('[data-tab-panel]');
  if (invalidPanel) activate(invalidPanel.dataset.tabPanel);
  document.addEventListener('invalid', e => { const panel = e.target.closest('[data-tab-panel]'); if (panel?.hidden) activate(panel.dataset.tabPanel); }, true);
  document.querySelectorAll('input[type="file"]').forEach(input => input.addEventListener('change', () => {
    const oversized = Array.from(input.files).some(file => file.size > 5 * 1024 * 1024);
    input.setCustomValidity(oversized ? 'Choose a file of 5 MB or less.' : '');
    if (oversized) input.reportValidity();
  }));
  document.querySelectorAll('.file-drop input').forEach(input => input.addEventListener('change', () => { input.closest('.file-drop').querySelector('[data-file-name]').textContent = input.files[0]?.name ?? 'Choose a file'; }));
  document.querySelectorAll('[data-document-upload]').forEach(upload => {
    const input = upload.querySelector('input[type="file"]');
    const selected = upload.querySelector('[data-selected-preview]');
    const existing = upload.querySelector('[data-existing-preview]');
    const media = upload.querySelector('[data-preview-media]');
    const link = upload.querySelector('[data-preview-link]');
    let objectUrl;
    const updatePreview = () => {
      media.replaceChildren();
      link.removeAttribute('href');
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = null;
      selected.hidden = true;
      existing.hidden = false;
      const file = input.files[0];
      if (!file || file.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type)) return;
      objectUrl = URL.createObjectURL(file);
      const preview = document.createElement(file.type === 'application/pdf' ? 'object' : 'img');
      if (file.type === 'application/pdf') {
        preview.type = 'application/pdf';
        preview.data = objectUrl;
        preview.setAttribute('aria-label', 'Preview of ' + file.name);
        preview.textContent = 'PDF preview unavailable. Use Open selected document below.';
      } else {
        preview.src = objectUrl;
        preview.alt = 'Preview of ' + file.name;
      }
      media.append(preview);
      link.href = objectUrl;
      upload.querySelector('[data-preview-name]').textContent = file.name + ' · ' + (file.size / 1024 / 1024).toFixed(2) + ' MB';
      selected.hidden = false;
      existing.hidden = true;
    };
    input.addEventListener('change', updatePreview);
    upload.querySelector('[data-clear-document]').addEventListener('click', () => {
      input.value = '';
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
  document.querySelectorAll('[data-auto-upload]').forEach(input => input.addEventListener('change', () => { if (input.files.length) input.form.requestSubmit(); }));
  document.querySelectorAll('[data-open-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
  document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
  const passwordDialog = document.getElementById('client-password-reveal');
  if (passwordDialog) {
    const form = passwordDialog.querySelector('[data-client-password-form]');
    const confirmation = passwordDialog.querySelector('[data-password-confirmation]');
    const result = passwordDialog.querySelector('[data-password-result]');
    const error = passwordDialog.querySelector('[data-password-error]');
    const reset = passwordDialog.querySelector('[data-password-reset]');
    const resetLink = passwordDialog.querySelector('[data-password-reset-link]');
    const value = passwordDialog.querySelector('[data-client-password-value]');
    const submit = passwordDialog.querySelector('[data-password-reveal-submit]');
    const adminPassword = form.elements.current_password;
    const adminToggle = passwordDialog.querySelector('[data-admin-password-toggle]');
    const setAdminPasswordVisible = visible => {
      adminPassword.type = visible ? 'text' : 'password';
      adminToggle.textContent = visible ? 'Hide' : 'Show';
      adminToggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
      adminToggle.setAttribute('aria-pressed', String(visible));
    };
    adminToggle.hidden = false;
    adminToggle.addEventListener('click', () => setAdminPasswordVisible(adminPassword.type === 'password'));
    let timer, pendingRequest;
    const clearPassword = () => {
      clearTimeout(timer);
      pendingRequest?.abort();
      value.value = '';
      adminPassword.value = '';
      setAdminPasswordVisible(false);
      result.hidden = true;
      confirmation.hidden = false;
      error.hidden = true;
      error.textContent = '';
      reset.hidden = true;
      resetLink.removeAttribute('href');
      submit.hidden = false;
      submit.disabled = false;
      adminPassword.required = true;
    };
    document.querySelectorAll('[data-reveal-client-password]').forEach(button => button.addEventListener('click', () => {
      clearPassword();
      form.action = button.dataset.revealClientPassword;
      passwordDialog.querySelector('[data-password-client-name]').textContent = button.dataset.clientName;
      passwordDialog.showModal();
      adminPassword.focus();
    }));
    form.addEventListener('submit', async event => {
      event.preventDefault();
      error.hidden = true;
      submit.disabled = true;
      pendingRequest = new AbortController();
      try {
        const response = await fetch(form.action, {
          method: 'POST', cache: 'no-store', signal: pendingRequest.signal,
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
          body: JSON.stringify({ current_password: adminPassword.value }),
        });
        const data = await response.json();
        if (!passwordDialog.open) return;
        if (response.status === 409) {
          confirmation.hidden = true;
          adminPassword.required = false;
          submit.hidden = true;
          error.textContent = data.message;
          error.hidden = false;
          if (data.reset_url) {
            resetLink.href = data.reset_url;
            reset.hidden = false;
          }
          return;
        }
        if (!response.ok) throw new Error(data.message ?? 'The password could not be revealed. Try again.');
        if (!passwordDialog.open) return;
        value.value = data.password;
        confirmation.hidden = true;
        adminPassword.required = false;
        result.hidden = false;
        submit.hidden = true;
        value.focus();
        timer = setTimeout(() => passwordDialog.close(), 30000);
      } catch (failure) {
        if (failure.name !== 'AbortError' && passwordDialog.open) {
          error.textContent = failure.message;
          error.hidden = false;
        }
      } finally {
        adminPassword.value = '';
        setAdminPasswordVisible(false);
        submit.disabled = false;
      }
    });
    passwordDialog.addEventListener('close', clearPassword);
    document.addEventListener('visibilitychange', () => { if (document.hidden && passwordDialog.open) passwordDialog.close(); });
  }
  document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('click', e => { if (e.target === dialog) { const box = dialog.getBoundingClientRect(); if (e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom) dialog.close(); } }));
  const confirmDialog = document.getElementById('confirm-dialog');
  document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', e => {
    if (form.dataset.confirmed === 'true') return;
    e.preventDefault();
    document.getElementById('confirm-description').textContent = form.dataset.confirm;
    confirmDialog.returnValue = 'cancel';
    confirmDialog.showModal();
    confirmDialog.addEventListener('close', () => { if (confirmDialog.returnValue === 'confirm') { form.dataset.confirmed = 'true'; form.requestSubmit(); } }, { once: true });
  }));
  document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(button.dataset.copy); const previous = button.textContent; button.textContent = 'UPI ID copied'; setTimeout(() => { button.textContent = previous; }, 2200); } catch { button.textContent = button.dataset.copy + ' — select and copy'; }
  }));
  const position = document.querySelector('[data-position-form]');
  if (position) {
    const category = position.elements.category, section = position.elements.section, state = position.elements.state;
    const expiryField = position.querySelector('[data-expiry-field]');
    const update = () => {
      Array.from(section.options).forEach(o => { o.disabled = category.value === 'Forex & Currency' && ['Orders', 'Total value of trade'].includes(o.value); });
      if (section.selectedOptions[0]?.disabled) section.value = 'Current positions';
      const completed = section.value === 'Last trades';
      Array.from(state.options).forEach(option => {
        option.disabled = completed ? option.value !== 'Closed' :
          section.value !== 'Current positions' && option.value === 'Closed';
      });
      if (completed) state.value = 'Closed';
      else if (state.selectedOptions[0]?.disabled) state.value = 'Active';
      position.elements.exit_price.required = completed || state.value === 'Closed';
      if (expiryField) {
        expiryField.hidden = !['Futures', 'Options', 'Futures & Options'].includes(category.value);
        if (expiryField.hidden) position.elements.expiry_date.value = '';
      }
    };
    category.addEventListener('change', update); section.addEventListener('change', update); state.addEventListener('change', update); update();
    document.querySelector('[data-calculate-pnl]')?.addEventListener('click', () => {
      const p = position.elements, price = p.section.value === 'Last trades' || p.state.value === 'Closed' ? p.exit_price : p.ltp;
      if (!price.value || !p.entry_price.value || !p.quantity.value) return;
      p.pnl.value = ((Number(price.value) - Number(p.entry_price.value)) * Number(p.quantity.value) * (p.side.value === 'Sell' ? -1 : 1)).toFixed(2);
    });
  }
  const toast = document.querySelector('.toast');
  document.querySelector('[data-dismiss-toast]')?.addEventListener('click', () => toast?.remove());
  if (toast) setTimeout(() => toast.remove(), 5500);
  if (document.querySelector('.error-box') && document.querySelector('[name="current_password"]')?.closest('dialog')) {
    const hasPasswordError = document.querySelector('[name="current_password"]').closest('form').querySelector('.negative');
    if (hasPasswordError) document.getElementById('password-dialog').showModal();
  }
});
