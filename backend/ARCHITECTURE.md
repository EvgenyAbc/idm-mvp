# Backend Architecture Rules

This backend uses one folder organization style under `backend/src`:

- `Presentation/Http`
- `Application`
- `Domain`
- `Infrastructure`
- `Shared`

## Layer responsibilities

- `Presentation/Http`: transport concerns only (routing, request parsing, response mapping, auth header parsing).
- `Application`: use-case orchestration and workflow coordination.
- `Domain`: business rules, policies, and contracts.
- `Infrastructure`: external adapters (LDAP, persistence, system integrations).
- `Shared`: cross-cutting helpers and configuration.

## Dependency direction

Allowed direction is:

`Presentation -> Application -> Domain`

`Application -> Infrastructure` is allowed for adapter usage.

`Infrastructure -> Domain` is allowed only for implementing domain contracts or policy interfaces.

`Shared` can be used by all layers.

Current implementation note:

- Presentation handlers depend on `IDM\Application\AppContext`.
- `AppContext` currently exposes application services and infrastructure adapters.
- This means some workflow orchestration still lives in route handlers.
- Preferred direction is to keep moving orchestration into `Application` services and keep routes thin.

Disallowed:

- Domain depending on Presentation details.
- Domain depending on concrete Infrastructure adapters.
- New code in Presentation directly instantiating Infrastructure components.

## Namespace convention

Use PSR-4 namespaces that mirror paths from `src/`:

- `IDM\Presentation\...`
- `IDM\Application\...`
- `IDM\Domain\...`
- `IDM\Infrastructure\...`
- `IDM\Shared\...`

## Legacy roots policy

The following legacy roots are forbidden and must not be reintroduced:

- `src/Http`
- `src/Auth`
- `src/Ldap`
- `src/Approval`
- `src/Audit`
- `src/Source`
- `src/Support`
- `src/Config`
- `src/Provisioning`
- `src/Reconciliation`
