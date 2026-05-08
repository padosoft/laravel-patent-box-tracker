---
name: domain-service
description: Use when designing a clean Laravel 13 application workflow — Request/CLI input → DTO → Service/Action → optional Job → Response/Resource. Keeps the controller as orchestrator and the service HTTP-free and unit-testable; splits oversized services into smaller actions. Trigger on tasks like "design the workflow for X", "add use case", "split this service".
---

# Domain Service

Usa questo pattern per un workflow applicativo chiaro e testabile.

Target: Laravel 13.x, PHP 8.3+.

## Pipeline

1. Request/CLI input
2. DTO o value object validato
3. Service/Action con una responsabilita' chiara
4. Eventuale Job se l'operazione e' lunga
5. Response/Resource/Result

## Regole

- il service non deve dipendere dal controller
- il controller orchestra, non contiene business logic
- il service deve essere facile da testare senza HTTP
- se il service cresce troppo, spezzalo in action piu' piccole
- se l'input ha piu' di pochi campi o serve validazione/coerenza, introdurre un DTO esplicito
- se l'ingresso e' HTTP, preferire `FormRequest` come boundary di validazione
