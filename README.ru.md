# Componenta CQRS App

`componenta/cqrs-app` находит обработчики и слушателей по атрибутам и собирает PHP-файл с их регистрациями. Шины команд и запросов, локаторы и метаданные предоставляет [componenta/cqrs](https://github.com/componenta/cqrs).

## Установка

```bash
composer require componenta/cqrs-app
```

Для этого API требуются PHP 8.4+, App 4, Config 3, DI 5 и CQRS 4. Class Finder предоставляет общий итератор классов, Tokenizer — сведения о классах, Path Resolver разрешает путь артефакта. Для команды `app:build` нужен App Console 4.

Порядок провайдеров:

```php
return [
    new \Componenta\App\ConfigProvider(),
    new \Componenta\App\Console\ConfigProvider(),
    new \Componenta\CQRS\ConfigProvider(),
    new \Componenta\CQRS\App\ConfigProvider(),
    new ApplicationConfigProvider(),
];
```

Провайдеры могут подключаться существующим списком, созданным Composer. Пути поиска классов задаются обычными средствами App. App предоставляет ленивый исходный итератор через `Componenta\App\ConfigKey::DISCOVERY_SOURCE` (`app.discovery.source`).

## Обработчики и слушатели

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

Для обработчиков запросов используется `AsQueryHandler`. Атрибут обработчика объявляется на классе или на его публичном нестатическом методе. Атрибут класса выбирает `__invoke()`, а при его отсутствии — `handle()`. Тип первого параметра определяет сообщение, если имя не задано явно в атрибуте. Дополнительные обязательные аргументы при вызове обработчика через DI не разрешаются.

Слушатели реализуют `CommandListenerInterface`:

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
        // Реакция на успешную публикацию.
    }
}
```

Для отправки событий добавьте `EventMiddleware::class` в `Componenta\CQRS\ConfigKey::COMMAND_MIDDLEWARES`. Зависимости обработчиков и слушателей задаются обычными фабриками DI или разрешаются автосвязыванием.

Явные регистрации добавляются в списки ядра `cqrs.command_handlers`, `cqrs.query_handlers`, `cqrs.command_listeners`. Одинаковые явные и найденные регистрации объединяются. Разные обработчики одного сообщения вызывают исключение. Повтор одинакового атрибута слушателя объединяется; повтор одинаковой явной регистрации слушателя вызывает исключение. Порядок слушателей: приоритет по убыванию, затем ID сервиса и канонический список событий.

## Загрузка и сборка

Провайдер регистрирует три фабрики:

| Сервис | Назначение |
|---|---|
| `cqrs.maps` | Общие массивы для фабрик локаторов ядра. |
| `CqrsDiscoveryIndex` | Ленивое извлечение всех трёх секций за один проход. |
| `CqrsBuilder` | Билдер из списка `app.builders`, записывающий полный результат из исходников. |

Путь задаётся через `Componenta\CQRS\App\ConfigKey::MAP_FILE` (`cqrs.map_file`). По умолчанию используется `var/cache/build/cqrs.php`, разрешённый существующим `PathResolverInterface`.

```bash
php bin/console.php app:build
```

Обычная консольная команда использует текущий контейнер и окружение приложения. Просмотр списка команд и справки не создаёт билдеры и не запускает поиск классов ради сборки. Конструктор билдера только сохраняет зависимости.

`CqrsBuilder::build()` получает исходный индекс и явные регистрации, создаёт каталог, записывает полный временный файл рядом с целевым и публикует его через `rename()`. Текущая карта времени выполнения не используется как источник сборки. Ошибка исходных данных оставляет прежний артефакт; неудачная публикация удаляет временный файл. Атомарность относится к одному артефакту, а не ко всем билдерам приложения.

PHP-файл возвращает ровно три секции:

```php
return [
    'command_handlers' => [],
    'query_handlers' => [],
    'command_listeners' => [],
];
```

При первом получении локатора общая фабрика `cqrs.maps` проверяет явную конфигурацию и загружает файл. Отсутствующий, нечитаемый или некорректный файл приводит к использованию исходного индекса. Ошибки конфигурации и исходных регистраций выходят наружу. Во время работы приложения каталоги и файлы не создаются, билдер не вызывается.

Правила одинаковы во всех окружениях. Актуальный артефакт позволяет пропустить поиск классов, сохраняя выбор обработчиков, порядок слушателей и ошибки исходных регистраций. После изменения исходников или конфигурации пересоберите либо удалите файл и перезапустите долгоживущие процессы. Код, конфигурация и карта публикуются как согласованный выпуск. Структурно корректный, но устаревший файл не выявляется повторным обходом исходников во время работы приложения.

## Метаданные

Стандартный `ReflectionCommandMetadataProvider` ядра создаёт свежие атрибуты по запросу, в том числе для команд без регистрации обработчика. Билдер записывает только регистрации обработчиков и слушателей. Метаданные retry, lock и transport читаются при выполнении команды.

## Переход на новый API

Версионную карту и скомпилированную конфигурацию CQRS заменяют явные списки регистраций и `cqrs.map_file`. Вместо `CqrsMap` и его провайдеров используются обычные массивы либо общий сервис `cqrs.maps`. Регистрация классов метаданных и вызовы `isKnown()` удаляются. Собственные фабрики локаторов переводятся на массивы, как показано в документации ядра.

При публикации удалите прежний артефакт CQRS и выполните `app:build` для соответствующих кода и конфигурации. Кеши App и DI имеют собственный жизненный цикл.

## Интеграционные тесты

Сквозной набор использует рабочие копии App, Config, DI, CQRS и его расширений из соседних каталогов. Он проверяет загрузку этих копий через Composer, обычную команду сборки приложения, а также transport, policy, retry, блокировки и транзакции с исходными и сохранёнными картами. SQLite работает в памяти.

Разместите репозитории зависимостей рядом с этим пакетом и выполните:

```bash
composer --working-dir=integration install
composer test:integration
```

Нужны расширения PHP `pdo_sqlite` и `mbstring`. Интеграционный workflow получает ветки `main` соседних пакетов и запускает набор на PHP 8.4 и 8.5.
