# Builder Conventions (Shared)

## Template and Naming
- Use `EntityNameBuilder` naming for builders.
- Use the shared template as a baseline.

## Required Methods
- `build()` is mandatory and returns the entity instance.
- Only `build()` is allowed (no `persist/create`).

## Immutability
- Fluent API must be immutable: every `withX()` returns a cloned builder instance.

## Defaults
- Builders must create a valid entity by default.
- Keep default fields minimal and only include meaningful fields.
