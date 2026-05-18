(function () {
  'use strict';

  const core = window.StuckbemaCore || {};
  const lifecycle = core.createEventScope?.() || {
    on(target, type, handler, options) {
      target?.addEventListener?.(type, handler, options);
      return () => target?.removeEventListener?.(type, handler, options);
    },
    cleanup() {}
  };
  const byId = core.byId || ((id) => document.getElementById(id));
  const qs = core.qs || ((selector, root = document) => root?.querySelector?.(selector) || null);
  const ready = core.ready || ((callback) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
      window.queueMicrotask(callback);
    }
  });

  const ROLE_LABELS = Object.freeze({
    owner: 'Firmatecknare',
    manager: 'Chef',
    admin: 'Administration',
    supervisor: 'Arbetsledare',
    driver: 'Chaufför',
    worker: 'Arbetare',
    viewer: 'Läsbehörig'
  });

  const ROLE_ORDER = Object.keys(ROLE_LABELS);

  const elements = {};
  let adminUsers = [];

  ready(initAdminPanel);
  lifecycle.on(window, 'pagehide', () => lifecycle.cleanup(), { once: true });

  function initAdminPanel() {
    elements.panel = byId('adminPanel');
    elements.closeBtn = byId('adminPanelCloseBtn');
    elements.refreshBtn = byId('adminRefreshBtn');
    elements.message = byId('adminPanelMessage');
    elements.summary = byId('adminSummary');
    elements.settingsForm = byId('adminSettingsForm');
    elements.usersSection = byId('adminUsersSection');
    elements.userForm = byId('adminUserForm');
    elements.userId = byId('adminUserId');
    elements.firstName = byId('adminFirstName');
    elements.lastName = byId('adminLastName');
    elements.email = byId('adminEmail');
    elements.phone = byId('adminPhone');
    elements.role = byId('adminRole');
    elements.active = byId('adminActive');
    elements.password = byId('adminPassword');
    elements.userSaveBtn = byId('adminUserSaveBtn');
    elements.userCancelBtn = byId('adminUserCancelBtn');
    elements.tempPassword = byId('adminTempPassword');
    elements.usersList = byId('adminUsersList');

    populateRoleOptions();
    lifecycle.on(elements.closeBtn, 'click', closeAdminPanel);
    lifecycle.on(elements.refreshBtn, 'click', loadAdminPanel);
    lifecycle.on(elements.settingsForm, 'submit', handleSettingsSubmit);
    lifecycle.on(elements.userForm, 'submit', handleUserSubmit);
    lifecycle.on(elements.userCancelBtn, 'click', resetUserForm);
    lifecycle.on(elements.usersList, 'click', handleUserListClick);
    lifecycle.on(elements.panel, 'click', (event) => {
      if (event.target === elements.panel) {
        closeAdminPanel();
      }
    });
  }

  async function openAdminPanel() {
    const permissions = auth.getPermissions();
    if (!permissions.canOpenAdminPanel) {
      window.alert('Du saknar behörighet till Adminpanel.');
      return;
    }

    elements.panel.hidden = false;
    document.body.classList.add('modal-open');
    await loadAdminPanel();
  }

  function closeAdminPanel() {
    elements.panel.hidden = true;
    document.body.classList.remove('modal-open');
  }

  async function loadAdminPanel() {
    showAdminMessage('Hämtar systemstatus...', 'neutral');

    try {
      const permissions = auth.getPermissions();
      const summary = await apiJson('/api/admin/summary');
      renderSummary(summary);
      applySettingsToPage(summary.settings || {});
      populateSettingsForm(summary.settings || {});
      setSettingsAccess(Boolean(permissions.canManageSystemSettings));

      if (permissions.canManageUsers) {
        await loadUsers();
      } else {
        renderUsersAccessDenied();
      }

      showAdminMessage('Adminpanelen är uppdaterad.', 'success');
    } catch (error) {
      showAdminMessage(error.message || 'Kunde inte ladda Adminpanel.', 'error');
    }
  }

  async function loadUsers() {
    elements.usersSection.querySelector('.user-form').hidden = false;
    adminUsers = await apiJson('/api/users');
    renderUsers(adminUsers);
  }

  async function handleSettingsSubmit(event) {
    event.preventDefault();

    if (!auth.getPermissions().canManageSystemSettings) {
      showAdminMessage('Du kan se men inte ändra systeminställningar.', 'error');
      return;
    }

    const payload = {
      appTitle: byId('settingsAppTitle').value,
      companyName: byId('settingsCompanyName').value,
      deliveryTitle: byId('settingsDeliveryTitle').value,
      supportEmail: byId('settingsSupportEmail').value,
      supportPhone: byId('settingsSupportPhone').value,
      orderNumberPrefix: byId('settingsOrderPrefix').value,
      allowPushNotifications: true,
      adminMessage: byId('settingsAdminMessage').value
    };

    try {
      setFormLoading(elements.settingsForm, true);
      const result = await apiJson('/api/settings/system', {
        method: 'PUT',
        body: JSON.stringify(payload)
      });
      populateSettingsForm(result.settings);
      applySettingsToPage(result.settings);
      showAdminMessage('Systeminställningar sparade i Server.', 'success');
    } catch (error) {
      showAdminMessage(error.message || 'Kunde inte spara systeminställningar.', 'error');
    } finally {
      setFormLoading(elements.settingsForm, false);
    }
  }

  async function handleUserSubmit(event) {
    event.preventDefault();

    if (!auth.getPermissions().canManageUsers) {
      showAdminMessage('Du saknar behörighet att ändra användare.', 'error');
      return;
    }

    const payload = {
      firstName: elements.firstName.value,
      lastName: elements.lastName.value,
      email: elements.email.value,
      phone: elements.phone.value,
      role: elements.role.value,
      active: elements.active.checked
    };

    const editingUserId = elements.userId.value;
    const endpoint = editingUserId ? `/api/users/${encodeURIComponent(editingUserId)}` : '/api/users';
    const method = editingUserId ? 'PUT' : 'POST';

    try {
      setFormLoading(elements.userForm, true);
      const result = await apiJson(endpoint, {
        method,
        body: JSON.stringify(payload)
      });

      if (result.tempPassword) {
        showTempPassword(result.user.email, result.tempPassword);
      } else {
        hideTempPassword();
      }

      if (editingUserId && elements.password.value.trim()) {
        await updateUserPassword(editingUserId, elements.password.value);
      }

      resetUserForm();
      await loadUsers();
      showAdminMessage(result.message || 'Användare sparad.', 'success');
    } catch (error) {
      showAdminMessage(error.message || 'Kunde inte spara användare.', 'error');
    } finally {
      setFormLoading(elements.userForm, false);
      if (elements.userId.value) {
        elements.email.disabled = true;
      }
    }
  }

  async function handleUserListClick(event) {
    const button = event.target.closest('[data-admin-action]');
    if (!button) {
      return;
    }

    const userId = button.closest('.admin-user-card')?.dataset.userId;
    const user = adminUsers.find((item) => item.id === userId);
    if (!user) {
      return;
    }

    if (button.dataset.adminAction === 'edit') {
      fillUserForm(user);
      return;
    }

    if (button.dataset.adminAction === 'delete') {
      await deleteUser(user);
      return;
    }

    if (button.dataset.adminAction === 'password') {
      await promptPasswordChange(user);
    }
  }

  async function promptPasswordChange(user) {
    const password = window.prompt(`Nytt lösenord för ${user.email} (minst 8 tecken):`);
    if (password === null) {
      return;
    }
    await updateUserPassword(user.id, password);
  }

  async function updateUserPassword(userId, password) {
    const nextPassword = String(password || '').trim();
    if (nextPassword.length < 8) {
      showAdminMessage('Lösenordet är för kort.', 'error');
      return;
    }

    try {
      await apiJson(`/api/admin/users/${encodeURIComponent(userId)}/password`, {
        method: 'PATCH',
        body: JSON.stringify({ password: nextPassword })
      });
      elements.password.value = '';
      showAdminMessage('Lösenord uppdaterat.', 'success');
    } catch (error) {
      showAdminMessage(error.message || 'Kunde inte uppdatera lösenord.', 'error');
    }
  }

  async function deleteUser(user) {
    if (user.id === auth.getCurrentUser()?.id) {
      showAdminMessage('Du kan inte ta bort ditt eget konto.', 'error');
      return;
    }

    if (!window.confirm(`Ta bort användaren ${user.email}?`)) {
      return;
    }

    try {
      await apiJson(`/api/users/${encodeURIComponent(user.id)}`, { method: 'DELETE' });
      await loadUsers();
      showAdminMessage('Användaren togs bort.', 'success');
    } catch (error) {
      showAdminMessage(error.message || 'Kunde inte ta bort användaren.', 'error');
    }
  }

  function renderSummary(summary) {
    const database = summary.database || {};
    const counts = summary.counts || {};
    const roleCounts = summary.roleCounts || {};
    const items = [
      ['Server', database.connected ? 'Ansluten' : 'Ej ansluten'],
      ['Databas', database.provider || 'Okänd'],
      ['URL', database.url || '-'],
      ['Schema', database.schemaVersion || '-'],
      ['Användare', `${counts.activeUsers || 0} aktiva / ${counts.users || 0} totalt`],
      ['Leveranser', `${counts.pendingOrders || 0} pågående / ${counts.orders || 0} totalt`],
      ['Roller', ROLE_ORDER.map((role) => `${ROLE_LABELS[role]}: ${roleCounts[role] || 0}`).join(' | ')],
      ['Serverstatus', summary.serverTime ? formatDateTime(summary.serverTime) : '-']
    ];

    elements.summary.replaceChildren(...items.map(([label, value]) => {
      const item = document.createElement('div');
      item.className = 'admin-summary-item';
      item.innerHTML = `<span></span><strong></strong>`;
      item.querySelector('span').textContent = label;
      item.querySelector('strong').textContent = value;
      return item;
    }));
  }

  function populateSettingsForm(settings) {
    setInputValue('settingsAppTitle', settings.appTitle || 'Stuckbema Leveransdokument');
    setInputValue('settingsCompanyName', settings.companyName || 'Stuckbema');
    setInputValue('settingsDeliveryTitle', settings.deliveryTitle || 'Leveransdokument');
    setInputValue('settingsSupportEmail', settings.supportEmail || '');
    setInputValue('settingsSupportPhone', settings.supportPhone || '');
    setInputValue('settingsOrderPrefix', settings.orderNumberPrefix || 'LEV');
    setInputValue('settingsAdminMessage', settings.adminMessage || '');
    const allowPushInput = byId('settingsAllowPush');
    allowPushInput.checked = true;
    allowPushInput.disabled = true;
  }

  function applySettingsToPage(settings) {
    if (settings.appTitle) {
      document.title = settings.appTitle;
    }

    const title = qs('.brand-header h1');
    if (title && settings.deliveryTitle) {
      title.textContent = settings.deliveryTitle;
    }
  }

  function setSettingsAccess(canManageSettings) {
    Array.from(elements.settingsForm.elements).forEach((element) => {
      element.disabled = !canManageSettings;
    });

    if (!canManageSettings) {
      showAdminMessage('Du kan se status, men bara firmatecknare och chefer kan ändra systeminställningar.', 'neutral');
    }
  }

  function renderUsers(users) {
    if (!users.length) {
      elements.usersList.textContent = 'Inga användare hittades.';
      return;
    }

    const fragment = document.createDocumentFragment();
    users.forEach((user) => {
      const card = document.createElement('article');
      card.className = 'admin-user-card';
      card.dataset.userId = user.id;

      const info = document.createElement('div');
      const title = document.createElement('h4');
      const meta = document.createElement('p');
      const status = user.active === false ? 'Inaktiv' : 'Aktiv';
      title.textContent = user.name || `${user.firstName || ''} ${user.lastName || ''}`.trim() || user.email;
      meta.textContent = `${user.email} | ${ROLE_LABELS[user.role] || user.role} | ${status}`;
      info.append(title, meta);

      const actions = document.createElement('div');
      actions.className = 'admin-user-actions';
      actions.append(
        createActionButton('Ändra', 'edit'),
        createActionButton('Byt lösenord', 'password'),
        createActionButton('Ta bort', 'delete', 'danger-text')
      );

      card.append(info, actions);
      fragment.appendChild(card);
    });

    elements.usersList.replaceChildren(fragment);
  }

  function renderUsersAccessDenied() {
    elements.usersSection.querySelector('.user-form').hidden = true;
    elements.usersList.innerHTML = '<div class="empty-state">Du har åtkomst till Adminpanelens status, men inte till användarhantering.</div>';
  }

  function fillUserForm(user) {
    elements.userId.value = user.id;
    elements.firstName.value = user.firstName || '';
    elements.lastName.value = user.lastName || '';
    elements.email.value = user.email || '';
    elements.email.disabled = true;
    elements.phone.value = user.phone || '';
    elements.role.value = user.role || 'viewer';
    elements.active.checked = user.active !== false;
    elements.password.value = '';
    elements.userSaveBtn.textContent = 'Spara användare';
    elements.userCancelBtn.hidden = false;
    hideTempPassword();
    elements.firstName.focus();
  }

  function resetUserForm() {
    elements.userForm.reset();
    elements.userId.value = '';
    elements.email.disabled = false;
    elements.password.value = '';
    elements.active.checked = true;
    elements.role.value = 'worker';
    elements.userSaveBtn.textContent = 'Lägg till användare';
    elements.userCancelBtn.hidden = true;
  }

  function populateRoleOptions() {
    if (!elements.role) {
      return;
    }

    const currentRole = auth.getUserRole?.();
    elements.role.replaceChildren(...ROLE_ORDER.map((role) => {
      const option = document.createElement('option');
      option.value = role;
      option.textContent = ROLE_LABELS[role];
      option.disabled = currentRole === 'manager' && role === 'owner';
      return option;
    }));
    elements.role.value = 'worker';
  }

  function createActionButton(label, action, className = '') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `small-action ${className}`.trim();
    button.dataset.adminAction = action;
    button.textContent = label;
    return button;
  }

  function setInputValue(id, value) {
    const input = byId(id);
    if (input) {
      input.value = value;
    }
  }

  function showTempPassword(email, tempPassword) {
    elements.tempPassword.hidden = false;
    elements.tempPassword.textContent = `Temporärt lösenord för ${email}: ${tempPassword}. Spara det och be användaren byta lösenord vid första inloggning.`;
  }

  function hideTempPassword() {
    elements.tempPassword.hidden = true;
    elements.tempPassword.textContent = '';
  }

  function showAdminMessage(message, type = 'neutral') {
    elements.message.textContent = message;
    elements.message.dataset.type = type;
  }

  function setFormLoading(form, isLoading) {
    Array.from(form.elements).forEach((element) => {
      element.disabled = isLoading;
    });
  }

  async function apiJson(endpoint, options = {}) {
    const response = await auth.fetch(endpoint, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {})
      }
    });
    const text = await response.text();
    const data = text ? JSON.parse(text) : null;

    if (!response.ok) {
      throw new Error(data?.error || 'API-anropet misslyckades');
    }

    return data;
  }

  function formatDateTime(value) {
    return new Intl.DateTimeFormat('sv-SE', {
      dateStyle: 'short',
      timeStyle: 'short'
    }).format(new Date(value));
  }

  window.StuckbemaAdminPanel = {
    open: openAdminPanel,
    close: closeAdminPanel,
    loadAdminPanel
  };
})();
