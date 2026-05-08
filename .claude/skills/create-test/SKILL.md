---
name: create-test
description: Use when adding tests for new or refactored Laravel 13 code. Picks the right level (unit, feature, browser/E2E), respects the existing test framework (PHPUnit vs Pest), enforces behavior-not-implementation testing and readable factories/fixtures. Trigger on tasks like "add test for X", "cover this controller", "write feature test".
---

# Create Test

Per nuovo codice Laravel:

- baseline target: Laravel 13.x, PHP 8.3+

- unit test per logica pura o service
- feature test per HTTP, auth, validation e persistence
- browser/E2E solo per journey critici

## Framework test

- se il repo usa gia' PHPUnit, continua con PHPUnit
- se il repo e' nato con Pest, continua con Pest
- non convertire un codebase da PHPUnit a Pest dentro un task funzionale

## Regole

- testare comportamento, non implementazione interna
- factory e fixture leggibili
- un test deve spiegare il caso che protegge
