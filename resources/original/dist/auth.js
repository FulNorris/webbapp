'use strict';

// Auth Service - Hanterar autentisering och API-kommunikation

const core = window.StuckbemaCore || {};

const LEGACY_API_BASE_STORAGE_KEY = 'stuckbema_api_base_url';

function normalizeBaseUrl(value) {
  return core.normalizeBaseUrl?.(value) || String(value || '').trim().replace(/\/+$/, '');
}

function getAppApiConfig() {
  return window.STUCKBEMA_APP_CONFIG?.api || {};
}

function resolveApiBaseUrl() {
  const runtime = window.StuckbemaEnvironment?.getRuntime?.();
  if (runtime) {
    return normalizeBaseUrl(runtime.apiBaseUrl || '');
  }

  const apiConfig = getAppApiConfig();
  localStorage.removeItem(LEGACY_API_BASE_STORAGE_KEY);

  if (window.location.hostname && !window.StuckbemaEnvironment?.isLocalHostname?.(window.location.hostname)) {
    return normalizeBaseUrl(`${window.location.protocol}//${window.location.hostname}:3001`);
  }

  return normalizeBaseUrl(apiConfig.defaultBaseUrl || 'http://localhost:3001');
}

function createConnectionError(error) {
  if (error?.name === 'AbortError') {
    return new Error(`Kunde inte ansluta till servern (${AUTH_CONFIG.apiBaseUrl}) inom ${AUTH_CONFIG.requestTimeoutMs / 1000} sekunder.`);
  }

  if (error instanceof TypeError) {
    return new Error(`Kunde inte ansluta till servern (${AUTH_CONFIG.apiBaseUrl}). Kontrollera att backend körs och att webbläsaren kan nå servern.`);
  }

  return error;
}

async function apiRequest(endpoint, options = {}) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), AUTH_CONFIG.requestTimeoutMs);

  try {
    return await fetch(`${AUTH_CONFIG.apiBaseUrl}${endpoint}`, {
      ...options,
      signal: options.signal || controller.signal
    });
  } catch (error) {
    throw createConnectionError(error);
  } finally {
    clearTimeout(timeoutId);
  }
}

const AUTH_CONFIG = {
  runtime: window.StuckbemaEnvironment?.getRuntime?.() || null,
  apiBaseUrl: resolveApiBaseUrl(),
  requestTimeoutMs: getAppApiConfig().requestTimeoutMs || 10000,
  accessTokenKey: 'auth_access_token',
  refreshTokenKey: 'auth_refresh_token',
  userKey: 'auth_user',
  tokenRefreshInterval: 55 * 60 * 1000 // Uppdatera token efter 55 minuter
};

class AuthService {
  constructor() {
    this.accessToken = localStorage.getItem(AUTH_CONFIG.accessTokenKey);
    this.refreshToken = localStorage.getItem(AUTH_CONFIG.refreshTokenKey);
    this.user = this.loadUser();
    this.isAuthenticated = !!(this.accessToken && this.user);
    this.startTokenRefreshTimer();
  }

  /**
   * Logga in med e-post och lösenord
   */
  async login(email, password) {
    try {
      const response = await apiRequest('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password })
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.error || 'Inloggning misslyckades');
      }

      const data = await response.json();
      this.setTokens(data.accessToken, data.refreshToken);
      this.setUser(data.user);
      this.startTokenRefreshTimer();
      return data.user;
    } catch (error) {
      console.error('Login-fel:', error);
      throw error;
    }
  }

  /**
   * Logga ut
   */
  async logout() {
    try {
      await apiRequest('/api/auth/logout', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${this.accessToken}`
        },
        body: JSON.stringify({ refreshToken: this.refreshToken })
      }).catch(() => {}); // Ignorera fel vid logout
    } catch (error) {
      console.error('Logout-fel:', error);
    } finally {
      this.clearTokens();
      this.clearUser();
      this.isAuthenticated = false;
      this.stopTokenRefreshTimer();
    }
  }

  /**
   * Uppdatera lösenord vid första inloggning eller senare
   */
  async changePassword(currentPassword, newPassword) {
    try {
      const response = await apiRequest('/api/auth/change-password', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${this.accessToken}`
        },
        body: JSON.stringify({ currentPassword, newPassword })
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.error || 'Lösenordsändring misslyckades');
      }

      return await response.json();
    } catch (error) {
      console.error('Lösenordsändringsfel:', error);
      throw error;
    }
  }

  /**
   * Begär återställning av lösenord
   */
  async forgotPassword(email) {
    try {
      const response = await apiRequest('/api/auth/forgot-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email })
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.error || 'Kunde inte begära lösenordsåterställning');
      }

      return await response.json();
    } catch (error) {
      console.error('Glömt lösenord-fel:', error);
      throw error;
    }
  }

  /**
   * Återställ lösenord med token
   */
  async resetPassword(resetToken, newPassword) {
    try {
      const response = await apiRequest('/api/auth/reset-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ resetToken, newPassword })
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.error || 'Lösenordsåterställning misslyckades');
      }

      return await response.json();
    } catch (error) {
      console.error('Lösenordsåterställningsfel:', error);
      throw error;
    }
  }

  /**
   * Uppdatera accessToken med refreshToken
   */
  async refreshAccessToken() {
    if (!this.refreshToken) {
      throw new Error('Ingen refresh token tillgänglig');
    }

    try {
      const response = await apiRequest('/api/auth/refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refreshToken: this.refreshToken })
      });

      if (!response.ok) {
        throw new Error('Token-uppdatering misslyckades');
      }

      const data = await response.json();
      this.accessToken = data.accessToken;
      localStorage.setItem(AUTH_CONFIG.accessTokenKey, this.accessToken);
      return this.accessToken;
    } catch (error) {
      console.error('Token-uppdateringsfel:', error);
      this.clearTokens();
      this.isAuthenticated = false;
      throw error;
    }
  }

  /**
   * Gör API-anrop med autentisering
   */
  async fetch(endpoint, options = {}) {
    try {
      const headers = {
        'Content-Type': 'application/json',
        ...options.headers
      };

      if (this.accessToken) {
        headers['Authorization'] = `Bearer ${this.accessToken}`;
      }

      const response = await apiRequest(endpoint, {
        ...options,
        headers
      });

      // Om token är utgången, försök uppdatera och försök igen
      if (response.status === 401 && this.refreshToken) {
        try {
          await this.refreshAccessToken();
          headers['Authorization'] = `Bearer ${this.accessToken}`;
          return apiRequest(endpoint, {
            ...options,
            headers
          });
        } catch (error) {
          this.logout();
          throw error;
        }
      }

      return response;
    } catch (error) {
      console.error('API-fel:', error);
      throw error;
    }
  }

  /**
   * Lagra tokens
   */
  setTokens(accessToken, refreshToken) {
    this.accessToken = accessToken;
    this.refreshToken = refreshToken;
    localStorage.setItem(AUTH_CONFIG.accessTokenKey, accessToken);
    localStorage.setItem(AUTH_CONFIG.refreshTokenKey, refreshToken);
    this.isAuthenticated = true;
  }

  /**
   * Radera tokens
   */
  clearTokens() {
    this.accessToken = null;
    this.refreshToken = null;
    localStorage.removeItem(AUTH_CONFIG.accessTokenKey);
    localStorage.removeItem(AUTH_CONFIG.refreshTokenKey);
  }

  /**
   * Lagra användardata
   */
  setUser(user) {
    this.user = user;
    localStorage.setItem(AUTH_CONFIG.userKey, JSON.stringify(user));
  }

  /**
   * Ladda användardata från lagring
   */
  loadUser() {
    try {
      const stored = localStorage.getItem(AUTH_CONFIG.userKey);
      return stored ? JSON.parse(stored) : null;
    } catch (error) {
      console.error('Fel vid inladdning av användardata:', error);
      return null;
    }
  }

  /**
   * Radera användardata
   */
  clearUser() {
    this.user = null;
    localStorage.removeItem(AUTH_CONFIG.userKey);
  }

  /**
   * Starta timer för automatisk token-uppdatering
   */
  startTokenRefreshTimer() {
    if (this.tokenRefreshTimer) {
      clearInterval(this.tokenRefreshTimer);
    }

    this.tokenRefreshTimer = setInterval(async () => {
      if (this.isAuthenticated && this.refreshToken) {
        try {
          await this.refreshAccessToken();
        } catch (error) {
          console.error('Automatisk token-uppdatering misslyckades:', error);
        }
      }
    }, AUTH_CONFIG.tokenRefreshInterval);
  }

  /**
   * Stoppa timer för token-uppdatering
   */
  stopTokenRefreshTimer() {
    if (this.tokenRefreshTimer) {
      clearInterval(this.tokenRefreshTimer);
      this.tokenRefreshTimer = null;
    }
  }

  /**
   * Hämta nuvarande användare
   */
  getCurrentUser() {
    return this.user;
  }

  /**
   * Hämta användarens roll
   */
  getUserRole() {
    return this.user?.role || null;
  }

  getPermissions() {
    const role = this.getUserRole();
    const defaults = getClientPermissionsForRole(role);

    return {
      ...defaults,
      ...(this.user?.permissions || {})
    };
  }

  /**
   * Kontrollera om användaren har en specifik roll
   */
  hasRole(...roles) {
    return this.user && roles.includes(this.user.role);
  }

  /**
   * Kontrollera om användaren är autentiserad
   */
  isLoggedIn() {
    return this.isAuthenticated;
  }

  /**
   * Kontrollera om det är första inloggning
   */
  isFirstLogin() {
    return this.user?.isFirstLogin === true;
  }

  /**
   * Hämta den automatiskt valda API-servern.
   */
  getApiBaseUrl() {
    return AUTH_CONFIG.apiBaseUrl;
  }

  getRuntimeEnvironment() {
    return AUTH_CONFIG.runtime;
  }
}

function getClientPermissionsForRole(role) {
  const hasRole = (...roles) => roles.includes(role);

  return {
    canReadOrders: hasRole('owner', 'manager', 'admin', 'supervisor', 'driver', 'worker', 'viewer'),
    canCreateOrders: hasRole('owner', 'manager', 'admin', 'supervisor'),
    canUpdateOrders: hasRole('owner', 'manager', 'admin', 'supervisor'),
    canMarkOrdersDelivered: hasRole('owner', 'manager', 'admin', 'supervisor', 'driver'),
    canDeleteOrders: hasRole('owner', 'manager'),
    canClearOrders: hasRole('owner', 'manager'),
    canExportOrders: hasRole('owner', 'manager', 'admin', 'supervisor', 'driver'),
    canManageUsers: hasRole('owner', 'manager'),
    canOpenAdminPanel: hasRole('owner', 'manager', 'admin', 'supervisor'),
    canManageSystemSettings: hasRole('owner', 'manager'),
    canReceiveNotifications: hasRole('owner', 'manager', 'admin', 'driver')
  };
}

// Skapa global auth-instans
const auth = new AuthService();
window.StuckbemaAuth = auth;
