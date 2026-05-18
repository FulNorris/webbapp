# Stuckbema Leverans

Intern webbapp byggd som en PWA i `src/` och `public/`, byggd till `dist/`. Appen använder Node.js/Express, PostgreSQL och Debian/NGINX som produktionsmiljö.

## Arkitektur

- **Frontend**: PWA-källkod i `src/`, statiska assets i `public/` och byggoutput i `dist/`
- **Backend**: Node.js/Express i `server/`
- **Databas**: PostgreSQL via `pg`
- **Autentisering**: JWT och bcrypt
- **Drift**: NGINX serverar webbappen och proxar `/api` till Node

## Starta utvecklingsmiljön

### 1. Installera paket
