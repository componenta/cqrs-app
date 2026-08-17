# Componenta CQRS App

`componenta/cqrs-app` добавляет discovery уровня приложения и компиляцию CQRS-карты для `componenta/cqrs`.

Пакет не содержит runtime middleware, транспорты или console workers. Для этих задач устанавливаются отдельные CQRS-пакеты.

## Установка

```bash
composer require componenta/cqrs-app
```

## Зависимости

| Зависимость | Назначение |
|---|---|
| PHP `^8.4` | Современные возможности языка и strict types. |
| `componenta/app` | Application discovery и production build. |
| `componenta/class-finder` | Контракты class discovery и listener compiler. |
| `componenta/config` | Конфигурация и фабрики. |
| `componenta/cqrs` | Core CQRS runtime, карта и config keys. |
| `componenta/tokenizer` | Передаёт `ClassInfo` с уже созданным reflector. |
| `psr/container` | Получение сервисов. |

## Что регистрирует пакет

| Раздел конфигурации | Entries |
|---|---|
| `factories` | `CqrsDiscoveryIndex`, `CqrsMapCompiler` и application-реализация `CqrsMapProviderInterface`. |
| `ClassFinderConfigKey::LISTENERS` | Единственный listener `CqrsDiscoveryIndex`. |
| `CompileConfigKey::LISTENER_COMPILERS` | Compiler, создающий `ConfigKey::CQRS_MAP`. |
| `AppConfigKey::AUTOWIRE_ENTRY_CONTRIBUTORS` | Discovery index, добавляющий найденные handlers и listeners в DI compilation. |

## Использование

Сначала зарегистрируйте core provider, затем application provider:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\App\ConfigProvider(),
];
```

`componenta/cqrs` предоставляет standalone binding `CqrsMapProviderInterface`, читающий карту из конфигурации. `componenta/cqrs-app` заменяет только этот binding своей application factory. В development factory возвращает composite из configured и discovered maps; вне development возвращается configured provider, читающий полную compiled map.

Все runtime-компоненты продолжают зависеть от одного `CqrsMapProviderInterface`: command, query и listener locators, command metadata и compiler используют одну effective map.

Используйте discovery attributes в коде приложения:

```php
use Componenta\CQRS\Command\Attribute\AsCommandHandler;

#[AsCommandHandler]
final readonly class PublishPostHandler
{
    public function __invoke(PublishPostCommand $command): void
    {
        // Обработка команды.
    }
}
```

## Discovery и метаданные

`CqrsDiscoveryIndex` один раз читает `ClassInfo::$reflector` для каждого класса и собирает command handlers, query handlers, listeners, известные имена команд и настроенные metadata attributes. Он проверяет public non-static методы, отклоняет конфликты, удаляет одинаковые listener descriptors и детерминированно сортирует данные в `finalize()`.

Дополнительные пакеты добавляют metadata без изменения compiler, дополняя `ConfigKey::COMMAND_METADATA_ATTRIBUTES` классом атрибута. Factory проверяет существование каждого класса и наличие `#[Attribute]`.

`ConfigKey::DISCOVERY_ENABLED` позволяет явно включить или выключить live overlay. По умолчанию live discovery включён только при точном `APP_ENV=development`. Любое другое окружение требует compiled CQRS map и запрещает включение runtime discovery.

## Сборка для production

Если установлен `componenta/app-console`, перед запуском production выполните:

```bash
APP_ENV=development php bin/console.php app:build
```

Application provider сначала формирует ту же effective map, которую использует development dispatch: configured map плюс discovery map, объединённые через `CqrsMap::merge()`. `CqrsMapCompiler` сериализует эту карту как один детерминированный versioned artifact. При записи build заменяет numeric positions descriptors, а не добавляет configured-часть второй раз.

Production читает готовый полный artifact через тот же `CqrsMapProviderInterface` без сканирования классов. Metadata известной compiled-команды не используют reflection fallback; для неизвестной команды fallback допустим.

Старый CQRS key, неподдерживаемая версия карты или отсутствующая карта вне development приводят к ошибке с указанием очистить cache и повторить build. При переходе с v1 удалите кеши конфигурации, discovery, старых CQRS maps и legacy container caches перед запуском `app:build`.

## Optional Runtime Packages

Для runtime concerns устанавливайте отдельные пакеты:

| Пакет | Что добавляет |
|---|---|
| `componenta/cqrs-policy` | Policy middleware для команд и запросов. |
| `componenta/cqrs-retry` | Retry middleware. |
| `componenta/cqrs-lock` | Resource lock middleware. |
| `componenta/cqrs-transaction-cycle` | Cycle Database transaction middleware. |
| `componenta/cqrs-transport` | Async transport middleware, contracts, serializer и worker. |
| `componenta/cqrs-transport-cycle` | Cycle Database transport implementation. |
| `componenta/cqrs-transport-console` | Symfony Console команда `cqrs:worker`. |
