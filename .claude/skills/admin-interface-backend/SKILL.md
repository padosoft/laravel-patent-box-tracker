---
name: admin-interface-backend
description: Use when implementing the backend of a complex Laravel admin page (filters, KPI, lists, exports). Guides Request/DTO/service/controller/route layout, output contract design, and the minimum tests required. Trigger on tasks like "build admin index", "add filtered admin page", "write backend for admin dashboard".
---

# Admin Interface Backend

Skill per implementare il backend di una pagina admin Laravel complessa.

## Obiettivo

Preparare:

- Request/validator
- DTO o filter object
- service di query o aggregation
- controller thin
- route
- eventuale export

## Sequenza

1. definire filtri e vincoli
2. progettare il contratto JSON o view data
3. creare service di lettura
4. aggiungere controller e route
5. scrivere test del service e feature test minimi

## Regole

- niente query complesse in controller
- struttura di output stabile per il frontend
- validazione esplicita per limiti, range date, filtri multipli
