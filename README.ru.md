# Componenta CQRS App

`componenta/cqrs-app` добавляет application-level discovery и build-time map compilation для `componenta/cqrs`.

Пакет не содержит runtime middleware, транспорты или console workers. Для этих задач устанавливайте отдельные CQRS packages.

## Установка

```bash
composer require componenta/cqrs-app
```

## Зависимости

| Зависимость | Назначение |
|---|---|
| PHP `^8.4` | Современные возможности языка и strict types. |
| `componenta/app` | Интеграция compile cache contributors. |
| `componenta/arrayable` | Общий контракт преобразования в массив. |
| `componenta/class-finder` | Class discovery и listener compiler integration. |
| `componenta/config` | Интеграция с config provider. |
| `componenta/cqrs` | Core CQRS runtime contracts и config keys. |
| `componenta/tokenizer` | Source inspection для compilation handler maps. |
| `psr/container` | Получение сервисов. |

## Что регистрирует пакет

| Config section | Entries |
|---|---|
| `factories` | Attribute и compiled-map locators для command handlers, command listeners и query handlers. |
| `invokables` | Command/query handler map compilers, command listener map compiler и command attribute map compiler/contributor. |
| `ClassFinderConfigKey::LISTENERS` | Attribute locators для discovery. |
| `CompileConfigKey::LISTENER_COMPILERS` | Map compilers для build step. |
| `AppConfigKey::COMPILE_CACHE_CONTRIBUTORS` | Command attribute map contributor. |

## Использование

Зарегистрируйте provider вместе с `componenta/cqrs`:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\App\ConfigProvider(),
];
```

Используйте discovery attributes в application code:

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

В production build-time maps должны генерироваться и восстанавливаться из cache. App provider переключается на plain runtime locators, когда environment production и compiled maps доступны.

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
