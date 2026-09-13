# Componenta CQRS App

`componenta/cqrs-app` discovers handlers and listeners from attributes and builds PHP files containing their registrations. The CQRS buses and locators are provided by [componenta/cqrs](https://github.com/componenta/cqrs).

## Installation

```bash
composer require componenta/cqrs-app
```

This API uses PHP 8.4+, App 4, Config 3, DI 5 and CQRS 4. Class Finder supplies the shared class iterator; Tokenizer supplies class reflection; Path Resolver resolves the artifact path. Install App Console 4 to run `app:build`.

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
| `CqrsDiscoveryIndex` | Lazily extracts all three registration sections in one pass. |
| `CqrsBuilder` | Registered in `app.builders`; writes a complete artifact from original source. |

Configure the output path through `Componenta\CQRS\App\ConfigKey::MAP_FILE` (`cqrs.map_file`). The default is `var/cache/build/cqrs.php`, resolved through the existing `PathResolverInterface`.

```bash
php bin/console.php app:build
```

The ordinary console command uses the existing container in its current environment. Listing commands or displaying help does not construct builders or start discovery for the build. Creating a builder only stores dependencies.

`CqrsBuilder::build()` reads the original discovery index and explicit registrations, creates directories, writes a complete temporary file beside the destination and publishes it with `rename()`. It does not read the currently loaded runtime map. A source failure leaves the previous artifact intact; a failed publication cleans up its temporary file. Atomicity applies to this artifact, not to all application builders together.

The PHP file returns exactly these sections:

```php
return [
    'command_handlers' => [],
    'query_handlers' => [],
    'command_listeners' => [],
];
```

On the first locator resolution, the shared `cqrs.maps` factory validates explicit configuration and loads the file. A missing, unreadable or malformed file falls back to the original discovery index. Configuration and source errors propagate. Runtime does not create directories, write files or invoke a builder.

These rules apply in every environment. A current artifact avoids discovery while preserving the same handler selection, listener ordering and errors as source registrations. Rebuild or remove the file when source/configuration changes, deploy it with the matching code, and restart long-running processes. The runtime does not scan files to detect a structurally valid but outdated artifact.

## Metadata

Command metadata is supplied by the core `ReflectionCommandMetadataProvider`. It creates fresh attribute instances on demand and works for commands absent from handler maps. The builder stores only handler/listener registrations. Retry, lock and transport metadata remain runtime concerns.

## Migration

Replace versioned `cqrs.map`/compiled configuration with explicit registration lists and `cqrs.map_file`. Replace `CqrsMap` and its providers with plain arrays or the shared `cqrs.maps` service. Remove metadata attribute registration and calls to `isKnown()`. Update custom factories to the array locator constructors described in the core README.

Remove the previous CQRS artifact during deployment and run `app:build` for the matching code/configuration. Existing App and DI cache formats have their own lifecycle.

## Integration tests

The cross-package suite uses the working copies of App, Config, DI, CQRS and its extensions from sibling directories. It verifies Composer loading from those directories, the ordinary application build command, and transport, policy, retry, locking and transactions with source and built maps. SQLite runs in memory.

With the companion repositories checked out beside this package, run:

```bash
composer --working-dir=integration install
composer test:integration
```

PHP requires `pdo_sqlite` and `mbstring`. The integration workflow checks out companion `main` branches and runs the suite on PHP 8.4 and 8.5.
