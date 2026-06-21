# Componenta CQRS App

`componenta/cqrs-app` adds application-level discovery and build-time map compilation for `componenta/cqrs`.

It does not contain runtime middleware, transports, or console workers. Install the relevant optional CQRS packages for those concerns.

## Installation

```bash
composer require componenta/cqrs-app
```

## Dependencies

| Dependency | Purpose |
|---|---|
| PHP `^8.4` | Modern language features and strict types. |
| `componenta/app` | Compile cache contributor integration. |
| `componenta/arrayable` | Shared array conversion contract. |
| `componenta/class-finder` | Class discovery and listener compiler integration. |
| `componenta/config` | Config provider integration. |
| `componenta/cqrs` | Core CQRS runtime contracts and config keys. |
| `componenta/tokenizer` | Source inspection for handler map compilation. |
| `psr/container` | Service lookup. |

## What It Registers

| Config section | Entries |
|---|---|
| `factories` | Attribute and compiled-map locators for command handlers, command listeners, and query handlers. |
| `invokables` | Command/query handler map compilers, command listener map compiler, and command attribute map compiler/contributor. |
| `ClassFinderConfigKey::LISTENERS` | Attribute locators used during discovery. |
| `CompileConfigKey::LISTENER_COMPILERS` | Map compilers used during build. |
| `AppConfigKey::COMPILE_CACHE_CONTRIBUTORS` | Command attribute map contributor. |

## Usage

Register the provider together with `componenta/cqrs`:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\App\ConfigProvider(),
];
```

Use discovery attributes in application code:

```php
use Componenta\CQRS\Command\Attribute\AsCommandHandler;

#[AsCommandHandler]
final readonly class PublishPostHandler
{
    public function __invoke(PublishPostCommand $command): void
    {
        // handle command
    }
}
```

In production, build-time maps should be generated and restored from cache. The app provider switches to plain runtime locators when the environment is production and compiled maps are available.

## Optional Runtime Packages

Install separate packages for runtime concerns:

| Package | Adds |
|---|---|
| `componenta/cqrs-policy` | Command/query policy middleware. |
| `componenta/cqrs-retry` | Retry middleware. |
| `componenta/cqrs-lock` | Resource lock middleware. |
| `componenta/cqrs-transaction-cycle` | Cycle Database transaction middleware. |
| `componenta/cqrs-transport` | Async transport middleware, contracts, serializer, and worker. |
| `componenta/cqrs-transport-cycle` | Cycle Database transport implementation. |
| `componenta/cqrs-transport-console` | `cqrs:worker` Symfony Console command. |
