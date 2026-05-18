# Laravel full-stack cleanup plan

This project should use Laravel web sessions, CSRF protection, roles and permissions for internal flows.

Internal admin, orders, users, settings and logs should live behind web routes. Legacy API routes should remain only for health checks, tracking, push/service-worker flows and old JSON clients.
