---
name: create-service
description: Use when extracting business logic into a Laravel 13 service or action object — explicit input (params or DTO), single responsibility, injected dependencies, deterministic output. Also covers when to introduce a DTO and when to split a service. Trigger on tasks like "create service for X", "extract action", "refactor controller logic into service".
---

# Create Service

Pattern per creare un service o action object in Laravel.

Target: Laravel 13.x, PHP 8.3+.

## Struttura minima

- input esplicito tramite parametri o DTO
- una responsabilita' chiara
- dipendenze iniettate
- output deterministico o result object semplice

## Quando preferire un DTO

- input con molti campi
- validazione/coerenza tra campi
- uso del medesimo payload in piu' layer
- code path sync e async che condividono lo stesso contratto

## Quando spezzarlo

- se tocca piu' aggregate indipendenti
- se contiene piu' di un ramo di business maggiore
- se ha codice asincrono e sincrono mescolati
