# HRassess Product Upgrade Blueprint

## Overview
This document outlines a proposed rebuild of HRassess into a modern, API-first, multi-tenant SaaS platform. It decomposes the transformation into phases that correspond to major architectural goals, aligning with the desired product-level upgrade.

## 1. Platform Foundations
### 1.1 Backend Framework Migration
- Adopt Laravel as the primary backend framework.
- Use Laravel routing, controllers, middleware, and service providers to replace ad-hoc PHP scripts.
- Introduce domain service classes to encapsulate business logic for assessments, questionnaires, reporting, and user management.
- Implement database migrations for all entities using Laravel's schema builder.
- Switch to Eloquent ORM models with explicit relationships and query scopes.

### 1.2 API-First Architecture
- Provide a versioned REST API (`/api/v1`) with authentication via OAuth 2.0 / OpenID Connect.
- Expose endpoints for users, auth sessions, work functions, questionnaires, responses, scoring, and reports.
- Document the API with OpenAPI/Swagger, generated automatically from PHP attributes.
- Use Laravel Sanctum or Passport for personal access tokens and third-party integrations.

### 1.3 Async Workloads
- Configure Laravel Queues backed by Redis or SQS for report generation, notifications, and heavy scoring routines.
- Set up mail, notification, and broadcast channels with queued dispatchers.

## 2. Modern Frontend Applications
### 2.1 SPA Strategy
- Split the interface into two SPAs built with React + TypeScript: **Staff Portal** and **Admin Portal**.
- Use Vite for tooling, React Router for routing, and Redux Toolkit or Zustand for state management.
- Integrate component library (e.g., MUI) with custom themes to support tenant branding.

### 2.2 UX Enhancements
- Implement autosaving questionnaire forms with optimistic UI feedback.
- Support drag-and-drop questionnaire editing, conditional logic builders, and preview modes.
- Provide offline-capable PWA shells with service workers and IndexedDB caching.

## 3. Questionnaire Engine Redesign
- Store questionnaire definitions as JSON schemas persisted through Laravel models.
- Introduce versioned questionnaire entities with immutable published versions.
- Build conditional logic evaluation service capable of skip patterns, branching, and dynamic section injection.
- Maintain a centralized item bank with reusable item templates and localized strings.
- Support rich item types (Likert, matrix, numeric, file upload) via pluggable React components linked to schema definitions.

## 4. Multi-Tenant SaaS Architecture
- Adopt a tenant resolver middleware that maps subdomain or headers to tenant context.
- Choose database-per-tenant using Laravel's multi-database connections or implement row-level tenant IDs with global scopes.
- Store tenant-specific configuration (branding, feature toggles, SSO settings) in dedicated tables.
- Provide admin tooling for provisioning, suspending, and configuring tenants.

## 5. Security & Compliance
- Integrate Laravel with third-party SSO providers using OpenID Connect and SAML bridges.
- Implement role-based access control using permissions tables and policies/guards.
- Record audit logs for CRUD operations, questionnaire edits, assignments, and submissions with immutable append-only storage.
- Encrypt sensitive fields using Laravel's encryption helpers and database field encryption.
- Manage secrets with environment-specific vaults and enforce HTTPS + modern TLS everywhere.

## 6. Quality & Delivery Pipeline
- Establish PHPUnit unit and feature tests for core services, plus Pest/Cypress for E2E flows.
- Add static analysis (PHPStan/Psalm) and coding standards (PHP-CS-Fixer).
- Configure GitHub Actions pipeline that runs tests, linting, builds SPA bundles, and triggers deployments.
- Implement automated deployments to staging followed by manual promotion to production.

## 7. Analytics & Reporting
- Design a reporting schema in a separate PostgreSQL or warehouse database populated via Laravel jobs.
- Provide dimensional models for tenants, questionnaires, responses, and time periods.
- Build embedded analytics dashboards using tools like Metabase or Superset with tenant scoping.
- Emit structured domain events for key actions to power event-based analytics.

## 8. Internationalization & White-Labeling
- Externalize all UI strings into translation files managed via i18next (frontend) and Laravel localization (backend).
- Support questionnaire content localization by storing translations in associated tables.
- Allow tenant-specific themes, logos, and domains loaded dynamically at runtime.

## 9. Offline & Mobile Strategy
- Enhance the SPA with full offline support using service workers, IndexedDB caching, and background sync.
- Queue responses locally with conflict resolution strategies on re-sync.
- Optimize UI for mobile and tablet with responsive layouts and accessible touch targets.

## 10. Extensibility & Plugins
- Introduce a modular plugin system via Laravel packages with defined hook points (`beforeResponseSaved`, `afterScoreCalculated`, `onReportExport`).
- Allow tenants to enable/disable modules through configuration UI, backed by feature flag service.

## Implementation Roadmap
1. **Scaffolding:** Initialize Laravel backend and React SPAs alongside existing app. Set up shared authentication service.
2. **Data Migration:** Design migration scripts to port existing schema into new relational structure.
3. **Feature Parity:** Incrementally port questionnaires, responses, reporting, and user management into new services.
4. **Tenant Enablement:** Introduce tenant-aware data handling and provisioning workflows.
5. **Enhancements:** Layer on analytics, offline capabilities, and plugin framework.
6. **Sunset Legacy:** Run both systems in parallel until parity is confirmed, then decommission legacy PHP pages.

## Risks & Mitigations
- **Complex migration:** Invest in automated data migration scripts and verification tooling.
- **Team ramp-up:** Provide training on Laravel, React, and DevOps pipeline.
- **Scope creep:** Phase deliveries with clear acceptance criteria per milestone.

## Next Steps
- Approve architectural direction and allocate resources for dedicated squads (Backend, Frontend, Platform).
- Establish detailed implementation backlog and timeline estimates per roadmap milestone.
- Begin scaffolding repositories with CI/CD foundations.

