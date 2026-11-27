# Componenta CQRS App

`componenta/cqrs-app` discovers handlers and listeners from attributes and prepares application artifacts containing their registrations. The CQRS buses and locators are provided by [componenta/cqrs](https://github.com/componenta/cqrs).

## Installation

```bash
composer require componenta/cqrs-app
```

CQRS App 6 requires PHP 8.5.13+, App 6, Config 4, DI 6 and CQRS 5. Class Finder supplies the shared class iterator; Tokenizer supplies class reflection. Install App Console 6 to run `app:build`.

Register providers in this order:

```php
return [
    new \Componenta\App\ConfigProvider(),
    new \Componenta\App\Console\ConfigProvider(),
    new \Componenta\CQRS\ConfigProvider(),
    new \Componenta\CQRS\App\ConfigProvider(),
    new ApplicationConfigProvider(),
];
```

The existing Composer provider list can register them automatically. Configure application discovery paths through App as usual. App exposes the lazy original class iterator as `Componenta\App\ConfigKey::DISCOVERY_SOURCE` (`app.discovery.source`).

## Handlers and listeners

```php
use Componenta\CQRS\Command\Attribute\AsCommandHandler;

final readonly class PublishPost
{
    public function __construct(public int $id) {}
}

#[AsCommandHandler]
final class PublishPostHandler
{
    public function __invoke(PublishPost $command): string
    {
        return 'published:' . $command->id;
    }
}
```

Use `AsQueryHandler` for query handlers. A handler attribute can be declared on the class or on its public, non-static method. A class-level handler uses `__invoke()`, or `handle()` when `__invoke()` is absent. The first parameter identifies the message unless the attribute specifies its name. Required additional parameters are not injected when the handler is invoked.

Listeners implement `CommandListenerInterface`:

```php
use Componenta\CQRS\Command\Attribute\AsCommandListener;
use Componenta\CQRS\Command\Event\CommandFailedEvent;
use Componenta\CQRS\Command\Event\CommandListenerInterface;
use Componenta\CQRS\Command\Event\CommandProcessedEvent;
use Componenta\CQRS\Command\Event\CommandProcessEvent;

#[AsCommandListener(PublishPost::class, priority: 10, eventTypes: [CommandProcessedEvent::class])]
final class PublishPostListener implements CommandListenerInterface
{
    public function handleEvent(CommandProcessEvent|CommandProcessedEvent|CommandFailedEvent $event): void
    {
        // React to successful publication.
    }
}
```

Add `EventMiddleware::class` to `Componenta\CQRS\ConfigKey::COMMAND_MIDDLEWARES` to emit lifecycle events. Register handler/listener dependencies through normal DI factories or autowiring.

Explicit registrations use the core `cqrs.command_handlers`, `cqrs.query_handlers`, and `cqrs.command_listeners` lists. Identical explicit/discovered registrations collapse. Conflicting handlers fail. Identical attribute listener declarations collapse; duplicate explicit listener declarations fail. Listener order is priority descending, then service ID and canonical event list.

## Runtime and building

The provider registers three factories:

| Service | Purpose |
|---|---|
| `cqrs.maps` | Shared arrays used by the core locator factories. |
| `CqrsDiscoveryIndex` | Lazily extracts all three registration sections in one pass. Concurrent or reentrant calls during an active scan throw `LogicException` without changing that scan's state. |
| `CqrsBuilder` | Registered in `app.builders`; writes a complete artifact from original source. |

`CqrsBuilder` writes the artifact ID `cqrs.maps` with schema version 1. App owns the build directory, serialization, generation manifest and publication. There is no package-specific output path.

```bash
php bin/console.php app:build
```

The ordinary console command creates a fresh source composition for the build, independent of the running application’s selected snapshot. Listing commands or displaying help does not construct builders or start discovery for the build. Creating a builder only stores source dependencies.

`CqrsBuilder::build(BuildContext $build)` reads the original discovery index and explicit registrations and passes the complete normalized payload to `BuildContext::write()`. It never reads the currently selected runtime map. App publishes the complete generation only after every builder succeeds. A source, registration or storage error leaves the previous generation selected; a partial CQRS map is never published.

The payload contains exactly these sections:

```php
return [
    'command_handlers' => [],
    'query_handlers' => [],
    'command_listeners' => [],
];
```

On the first locator resolution, the shared `cqrs.maps` factory validates explicit configuration and reads its artifact from the `PreparedBuild` selected by App. It checks the optional `App\ConfigKey::PREPARED_BUILD` service ID, not a potentially autowired class. A missing, skipped, incompatible or malformed artifact uses the runtime discovery index and explicit registrations. `APP_DISCOVERY_SOURCE=1` bypasses the snapshot. Configuration and source errors propagate. Runtime does not create directories, write files or invoke a builder.

A valid prepared payload is the complete registration snapshot: current explicit registrations are validated but are not merged over it. Rebuild after changing registrations. Command and query service instances are still resolved through the current container.

These rules apply in every environment. A current artifact avoids discovery while preserving the same handler selection, listener ordering and errors as source registrations. Rebuild or run `app:clean` when source/configuration changes, deploy the generation with matching code and configuration, and restart long-running processes. App can bind a snapshot to an explicit `APP_BUILD_ID`; cleanup unpublishes it for new bootstraps while existing readers retain their selected generation. The runtime does not scan files to detect a structurally valid but outdated artifact.

## Metadata

Command metadata is supplied by the core `ReflectionCommandMetadataProvider`. It creates fresh attribute instances on demand and works for commands absent from handler maps. The builder stores only handler/listener registrations. Retry, lock and transport metadata remain runtime concerns.

## Migration

Replace versioned `cqrs.map`/compiled configuration with explicit registration lists. Remove `cqrs.map_file` and `Componenta\CQRS\App\ConfigKey`; this package no longer owns a filesystem path. Replace `CqrsMap` and its providers with plain arrays or the shared `cqrs.maps` service. Remove metadata attribute registration and calls to `isKnown()`. Update custom factories to the array locator constructors described in the core README.

Remove the previous independent CQRS artifact during deployment and run `app:build` for the matching code/configuration. `CqrsBuilder` no longer takes a filename and its `build()` method requires a shared `BuildContext`. Individual cleanup was removed; use the application build store or `app:clean`.

## Integration tests

The cross-package suite uses the working copies of App, Config, DI, CQRS and its extensions from sibling directories. It verifies Composer loading from those directories, the ordinary application build command, and transport, policy, retry, locking and transactions with source and built maps. SQLite runs in memory.

With the companion repositories checked out beside this package, run:

```bash
composer --working-dir=integration install
composer test:integration
```

PHP requires `pdo_sqlite` and `mbstring`, version 8.5.13 or later. The path repository version map describes the target releases; the corresponding refactor sources must be checked out until those releases are published. This matrix requires the compatibility releases CQRS Policy 4.1, CQRS Transport 5.1 and CQRS Transaction Cycle 3.1, plus the Retry 4, Lock 4 and Policy 4 refactor lines. Earlier released extensions whose bounds stop at Config 3 or CQRS 4 cannot satisfy it.
