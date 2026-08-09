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
| `componenta/class-finder` | Class discovery and listener compiler integration. |
| `componenta/config` | Config provider integration. |
| `componenta/cqrs` | Core CQRS runtime contracts and config keys. |
| `componenta/tokenizer` | Supplies `ClassInfo` and its existing reflector to discovery. |
| `psr/container` | Service lookup. |

## What It Registers

| Config section | Entries |
|---|---|
| `factories` | One `CqrsDiscoveryIndex`, one application map provider, and application-aware factories for the three locator interfaces. |
| `invokables` | One `CqrsMapCompiler`. |
| `ClassFinderConfigKey::LISTENERS` | The single `CqrsDiscoveryIndex` listener. |
| `CompileConfigKey::LISTENER_COMPILERS` | The single compiler that emits `ConfigKey::CQRS_MAP`. |

## Usage

Register the core provider first and the application provider second:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\App\ConfigProvider(),
];
```

Both packages bind the locator interfaces directly through factories. Provider order is therefore the explicit implementation-selection mechanism: `cqrs-app` replaces the three core locator factories with application-aware ones. It does not call core factories manually. A later application provider may deliberately select another implementation, and delegators registered for the same requested id still wrap that result.

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

## Discovery And Metadata

`CqrsDiscoveryIndex` reads `ClassInfo::$reflector` once per class and collects command handlers, query handlers, listeners, known command names, and configured command metadata attributes. It validates public non-static handler methods, rejects conflicting handlers, deduplicates identical listeners, and performs sorting once in `finalize()`.

Optional packages add metadata without changing the compiler by appending an attribute class to `ConfigKey::COMMAND_METADATA_ATTRIBUTES`. The factory validates that every entry exists and is declared with `#[Attribute]`.

`ConfigKey::DISCOVERY_ENABLED` may explicitly enable or disable the live overlay. When omitted, discovery is enabled in every environment except exact `APP_ENV=production`; `test` and `staging` therefore remain non-production.

## Production Build

With `componenta/app-console`, build the artifact before starting production:

```bash
APP_ENV=development php bin/console.php app:build
```

The compiler writes one deterministic CQRS map v2 into the application config cache. Production locators read that map without scanning application classes. Metadata for a known compiled command never falls back to reflection; an unknown command may still use the reflection provider.

An old CQRS key, unsupported map version, or missing production map fails with an instruction to clear caches and rebuild. After upgrading from v1, remove the config, discovery, old CQRS, and legacy container caches before running `app:build`.

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
