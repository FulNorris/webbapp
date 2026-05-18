(() => {
  'use strict';

  const core = window.StuckbemaCore || {};
  const lifecycle = core.createEventScope?.() || {
    on(target, type, handler, options) {
      target?.addEventListener?.(type, handler, options);
      return () => target?.removeEventListener?.(type, handler, options);
    },
    timeout(handler, delay, ...args) {
      return window.setTimeout(handler, delay, ...args);
    },
    cleanup() {}
  };
  const byId = core.byId || ((id) => document.getElementById(id));
  const ready = core.ready || ((callback) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
      window.queueMicrotask(callback);
    }
  });

// DOM Elements
const loginForm = byId('loginForm');
const loginEmail = byId('loginEmail');
const loginPassword = byId('loginPassword');
const loginError = byId('loginError');
const forgotPasswordBtn = byId('forgotPasswordBtn');

// Forgot Password Modal
const forgotPasswordModal = byId('forgotPasswordModal');
const closeForgotModal = byId('closeForgotModal');
const forgotPasswordForm = byId('forgotPasswordForm');
const resetPasswordForm = byId('resetPasswordForm');
const resetEmail = byId('resetEmail');
const forgotPasswordError = byId('forgotPasswordError');
const forgotPasswordSuccess = byId('forgotPasswordSuccess');
const resetToken = byId('resetToken');
const resetPasswordError = byId('resetPasswordError');
const newResetPassword = byId('newResetPassword');
const newResetPasswordConfirm = byId('newResetPasswordConfirm');

// Change Password Modal
const changePasswordModal = byId('changePasswordModal');
const changePasswordForm = byId('changePasswordForm');
const currentPassword = byId('currentPassword');
const newPassword = byId('newPassword');
const newPasswordConfirm = byId('newPasswordConfirm');
const changePasswordError = byId('changePasswordError');
const appConfig = window.STUCKBEMA_APP_CONFIG || {};

// Bind Events
ready(async () => {
  await prepareRuntimeEnvironment();
  registerServiceWorker();

  lifecycle.on(loginForm, 'submit', handleLogin);
  lifecycle.on(forgotPasswordBtn, 'click', openForgotPasswordModal);
  lifecycle.on(closeForgotModal, 'click', closeForgotPasswordModalHandler);
  lifecycle.on(forgotPasswordForm, 'submit', handleForgotPassword);
  lifecycle.on(resetPasswordForm, 'submit', handleResetPassword);
  lifecycle.on(changePasswordForm, 'submit', handleChangePassword);

  // Stäng modal när man klickar utanför
  lifecycle.on(forgotPasswordModal, 'click', (e) => {
    if (e.target === forgotPasswordModal) {
      closeForgotPasswordModalHandler();
    }
  });

  lifecycle.on(changePasswordModal, 'click', (e) => {
    if (e.target === changePasswordModal) {
      closeChangePasswordModal();
    }
  });

  if (auth.isLoggedIn()) {
    redirectToApp();
  }
});

lifecycle.on(window, 'pagehide', () => lifecycle.cleanup(), { once: true });

async function prepareRuntimeEnvironment() {
  if (!('serviceWorker' in navigator) || !shouldDisableServiceWorker()) {
    return;
  }

  try {
    await core.resetLocalServiceWorkerState?.();
  } catch (error) {
    console.error('Kunde inte rensa lokal service worker-state:', error);
  }
}

function shouldDisableServiceWorker() {
  if (!appConfig.serviceWorker?.enabled) {
    return true;
  }

  if (!appConfig.serviceWorker?.disableOnLocalOrigins) {
    return false;
  }

  const host = window.location.hostname;
  return window.location.protocol === 'file:' || host === 'localhost' || host === '127.0.0.1';
}

function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || shouldDisableServiceWorker()) {
    return;
  }

  const version = String(appConfig.assetVersion || Date.now());
  navigator.serviceWorker
    .register(`./sw.js?v=${encodeURIComponent(version)}`, { scope: './' })
    .catch((error) => console.error('Kunde inte registrera service worker:', error));
}

/**
 * Hantera login
 */
async function handleLogin(e) {
  e.preventDefault();
  hideError(loginError);

  const email = loginEmail.value.trim();
  const password = loginPassword.value;

  if (!email || !password) {
    showError(loginError, 'Fyll i alla fält');
    return;
  }

  try {
    setFormLoading(loginForm, true);
    await auth.login(email, password);

    if (auth.isFirstLogin()) {
      // Visa modal för att byta lösenord
      openChangePasswordModal();
    } else {
      // Gå till app
      redirectToApp();
    }
  } catch (error) {
    showError(loginError, error.message);
  } finally {
    setFormLoading(loginForm, false);
  }
}

/**
 * Hantera glömt lösenord
 */
async function handleForgotPassword(e) {
  e.preventDefault();
  hideError(forgotPasswordError);
  hideSuccess(forgotPasswordSuccess);

  const email = resetEmail.value.trim();

  if (!email) {
    showError(forgotPasswordError, 'Fyll i din e-postadress');
    return;
  }

  try {
    setFormLoading(forgotPasswordForm, true);
    const result = await auth.forgotPassword(email);

    if (result?.delivery === 'server-email-link') {
      showSuccess(forgotPasswordSuccess, 'En återställningslänk har skickats till din e-postadress. Öppna länken i mailet för att byta lösenord.');
      return;
    }

    showSuccess(forgotPasswordSuccess, 'Reset-token har skickats till din e-postadress.');

    // Visa reset-formulär efter 2 sekunder
    lifecycle.timeout(() => {
      forgotPasswordForm.style.display = 'none';
      resetPasswordForm.style.display = 'flex';
    }, 2000);
  } catch (error) {
    showError(forgotPasswordError, error.message);
  } finally {
    setFormLoading(forgotPasswordForm, false);
  }
}

/**
 * Hantera lösenordsåterställning
 */
async function handleResetPassword(e) {
  e.preventDefault();
  hideError(resetPasswordError);

  const token = resetToken.value.trim();
  const newPass = newResetPassword.value;
  const newPassConfirm = newResetPasswordConfirm.value;

  if (!token || !newPass || !newPassConfirm) {
    showError(resetPasswordError, 'Fyll i alla fält');
    return;
  }

  if (newPass.length < 8) {
    showError(resetPasswordError, 'Lösenordet måste vara minst 8 tecken långt');
    return;
  }

  if (newPass !== newPassConfirm) {
    showError(resetPasswordError, 'Lösenorden matchar inte');
    return;
  }

  try {
    setFormLoading(resetPasswordForm, true);
    await auth.resetPassword(token, newPass);

    showSuccess(forgotPasswordSuccess, '✓ Lösenord återställt! Logga in med ditt nya lösenord.');

    lifecycle.timeout(() => {
      closeForgotPasswordModalHandler();
      resetPasswordForm.style.display = 'none';
      forgotPasswordForm.style.display = 'flex';
      resetPasswordForm.reset();
      forgotPasswordForm.reset();
    }, 2000);
  } catch (error) {
    showError(resetPasswordError, error.message);
  } finally {
    setFormLoading(resetPasswordForm, false);
  }
}

/**
 * Hantera lösenordsändring vid första inloggning
 */
async function handleChangePassword(e) {
  e.preventDefault();
  hideError(changePasswordError);

  const currentPass = currentPassword.value;
  const newPass = newPassword.value;
  const newPassConfirm = newPasswordConfirm.value;

  if (!currentPass || !newPass || !newPassConfirm) {
    showError(changePasswordError, 'Fyll i alla fält');
    return;
  }

  if (newPass.length < 8) {
    showError(changePasswordError, 'Lösenordet måste vara minst 8 tecken långt');
    return;
  }

  if (newPass !== newPassConfirm) {
    showError(changePasswordError, 'Lösenorden matchar inte');
    return;
  }

  try {
    setFormLoading(changePasswordForm, true);
    await auth.changePassword(currentPass, newPass);

    closeChangePasswordModal();
    redirectToApp();
  } catch (error) {
    showError(changePasswordError, error.message);
  } finally {
    setFormLoading(changePasswordForm, false);
  }
}

/**
 * Öppna modal för glömt lösenord
 */
function openForgotPasswordModal() {
  forgotPasswordModal.style.display = 'flex';
  forgotPasswordForm.style.display = 'flex';
  resetPasswordForm.style.display = 'none';
  forgotPasswordForm.reset();
  resetPasswordForm.reset();
  hideError(forgotPasswordError);
  hideSuccess(forgotPasswordSuccess);
  hideError(resetPasswordError);
}

/**
 * Stäng modal för glömt lösenord
 */
function closeForgotPasswordModalHandler() {
  forgotPasswordModal.style.display = 'none';
  forgotPasswordForm.reset();
  resetPasswordForm.reset();
  forgotPasswordForm.style.display = 'flex';
  resetPasswordForm.style.display = 'none';
  hideError(forgotPasswordError);
  hideSuccess(forgotPasswordSuccess);
  hideError(resetPasswordError);
}

/**
 * Öppna modal för att byta lösenord vid första inloggning
 */
function openChangePasswordModal() {
  changePasswordModal.style.display = 'flex';
  changePasswordForm.reset();
  hideError(changePasswordError);
}

/**
 * Stäng modal för lösenordsändring
 */
function closeChangePasswordModal() {
  changePasswordModal.style.display = 'none';
  changePasswordForm.reset();
  hideError(changePasswordError);
}

/**
 * Visa felmeddelande
 */
function showError(element, message) {
  if (element) {
    element.textContent = message;
    element.style.display = 'block';
  }
}

/**
 * Dölj felmeddelande
 */
function hideError(element) {
  if (element) {
    element.style.display = 'none';
    element.textContent = '';
  }
}

/**
 * Visa framgångsmeddelande
 */
function showSuccess(element, message) {
  if (element) {
    element.textContent = message;
    element.style.display = 'block';
  }
}

/**
 * Dölj framgångsmeddelande
 */
function hideSuccess(element) {
  if (element) {
    element.style.display = 'none';
    element.textContent = '';
  }
}

/**
 * Sätt formulär i inladdningstillstånd
 */
function setFormLoading(form, isLoading) {
  const buttons = form.querySelectorAll('button[type="submit"]');
  buttons.forEach((btn) => {
    btn.disabled = isLoading;
    btn.classList.toggle('loading', isLoading);
  });

  const inputs = form.querySelectorAll('input');
  inputs.forEach((input) => {
    input.disabled = isLoading;
  });
}

/**
 * Omdirigera till app
 */
function redirectToApp() {
  window.location.href = 'index.html';
}
})();
