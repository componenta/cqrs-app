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
| `componenta/app` | Application discovery and production build integration. |
| `componenta/class-finder` | Class discovery and listener compiler contracts. |
| `componenta/config` | Configuration and factory integration. |
| `componenta/cqrs` | Core CQRS runtime, map contracts, and config keys. |
| `componenta/tokenizer` | Supplies `ClassInfo` and its existing reflector to discovery. |
| `psr/container` | Service lookup. |

## What It Registers

| Config section | Entries |
|---|---|
| `factories` | `CqrsDiscoveryIndex`, `CqrsMapCompiler`, and the application implementation of `CqrsMapProviderInterface`. |
| `ClassFinderConfigKey::LISTENERS` | The single `CqrsDiscoveryIndex` listener. |
| `CompileConfigKey::LISTENER_COMPILERS` | The compiler that emits `ConfigKey::CQRS_MAP`. |
| `AppConfigKey::AUTOWIRE_ENTRY_CONTRIBUTORS` | The discovery index that contributes discovered handlers and listeners to DI compilation. |

## Usage

Register the core provider first and the application provider second:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\App\ConfigProvider(),
];
```

`componenta/cqrs` provides the standalone `CqrsMapProviderInterface` binding backed by configured data. `componenta/cqrs-app` replaces that one binding with an application factory. In development the factory returns a composite of the configured and discovered maps; outside development it returns the configured provider reading the compiled map.

All core runtime consumers continue to depend on the same `CqrsMapProviderInterface`: command, query and listener locators, command metadata, and the compiler therefore observe one effective map.

Use discovery attributes in application code:

```php
use Componenta\CQRS\Command\Attribute\AsCommandHandler;

#[AsCommandHandler]
final readonly class PublishPostHandler
{
    public function __invoke(PublishPostCommand $command): void
    {
        // Handle command.
    }
}
```

## Discovery And Metadata

`CqrsDiscoveryIndex` reads `ClassInfo::$reflector` once per class and collects command handlers, query handlers, listeners, known command names, and configured command metadata attributes. It validates public non-static handler methods, rejects conflicting handlers, deduplicates identical listeners, and performs deterministic sorting in `finalize()`.

Optional packages add metadata without changing the compiler by appending an attribute class to `ConfigKey::COMMAND_METADATA_ATTRIBUTES`. The configuration boundary validates that every entry exists, is declared with `#[Attribute]`, and allows class targets before class discovery begins. The discovery index keeps the same invariant for direct construction while avoiding repeated declaration reflection for every discovered class.

`ConfigKey::DISCOVERY_ENABLED` may explicitly enable or disable the live overlay. When the flag is omitted, discovery follows the application environment default: a missing environment/`APP_ENV` is treated as development, and explicit `APP_ENV=development` is also development. Any explicit non-development environment such as `production`, `staging`, or `test` requires a compiled CQRS map and rejects an attempt to enable runtime discovery.

## Production Build

With `componenta/app-console`, build the artifact before starting production:

```bash
APP_ENV=development php bin/console.php app:build
```

The application provider first produces the same effective map used by development dispatch: configured map plus discovery map, merged through `CqrsMap::merge()`. `CqrsMapCompiler` serializes that effective map as one deterministic versioned artifact. The build merge replaces numeric descriptor positions instead of appending the configured portion a second time.

Production reads the resulting complete artifact through the same `CqrsMapProviderInterface`, without scanning application classes. `componenta/cqrs-app` itself never supplies a production reflection fallback. When paired with CQRS v4, the standard metadata provider is strictly map-backed in every environment, so metadata missing from the effective/compiled map remains absent at runtime. Applications that intentionally choose `ReflectionCommandMetadataProvider` are opting into a different core metadata contract explicitly.

An old CQRS key, unsupported map version, or missing non-development map fails with an instruction to clear caches and rebuild. After upgrading from v1, remove the config, discovery, old CQRS, and legacy container caches before running `app:build`.

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
