---
name: admin-interface-component-audit
description: Use BEFORE creating or refactoring any admin interface to audit existing UI components, services, and helpers and decide REUSE / EXTEND / CREATE-DOMAIN / CREATE-GLOBAL for each piece. Trigger when starting a new admin page, when asked to "check existing components first", or before any "create-admin-interface" run.
---

# Component Audit

Prima di creare una nuova interfaccia admin:

1. elenca componenti UI gia' presenti
2. elenca servizi o helper gia' esistenti
3. per ogni elemento decidi:
   - REUSE
   - EXTEND
   - CREATE-DOMAIN
   - CREATE-GLOBAL

## Regola principale

Default a REUSE. Creare nuovo codice solo se il riuso peggiora chiarezza o correttezza.

## Output

Tabella con elemento, decisione, path esistente e motivo.
