# mnd/back-office-sdk

PHP SDK сервиса back-office с адаптером Laravel: клиент, DTO, проверка подписи вебхуков, ретраи чтения,
circuit breaker и исходящая очередь. Контракт API — `docs/openapi.yaml`
в репозитории сервиса.

PHP 8.2+, Laravel 10–13. Ядро (клиент, DTO, вебхуки) работает и без Laravel.

## Подключение к Laravel-проекту

```sh
composer require mnd/back-office-sdk
php artisan back-office:install     # config/back-office.php и миграция
php artisan migrate                 # back_office_outbox, back_office_webhook_events
```

`.env` (и `.env.example`):

| Переменная | Откуда                                                                              |
| --- |-------------------------------------------------------------------------------------|
| `BACK_OFFICE_URL` | адрес сервиса, например `https://localhost`                                         |
| `BACK_OFFICE_KEY` | `php artisan platform:key-issue <проект>` на сервисе                                |
| `BACK_OFFICE_WEBHOOK_SECRET` | `php artisan platform:project-webhook <проект> <url>` на сервисе                    |
| `BACK_OFFICE_CACHE_STORE` | необязательно: хранилище состояния circuit breaker, общее для всех воркеров (redis) |
| `BACK_OFFICE_QUEUE`, `BACK_OFFICE_QUEUE_CONNECTION` | необязательно: очередь задачи-отправителя                                           |

Вебхук — в `routes/api.php` (CSRF не нужен, запрос подписан):

```php
Route::backOfficeWebhook('webhooks/back-office');
```

Отправитель очереди сам встаёт в планировщик проекта (`back-office:outbox-send` каждую минуту) — нужен только
работающий `schedule:work` / cron и воркер очереди.

## Записи, которые нельзя потерять, — через исходящую очередь

Сообщение пишется в той же транзакции, что изменение в проекте, и уходит после коммита. Если сервис недоступен —
повторяется, пока он не вернётся, с тем же `Idempotency-Key`.

```php
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Laravel\Facades\BackOffice;
use Mnd\BackOffice\Requests\FinanceRequests;

DB::transaction(function () use ($entry, $user) {
    $entry->markFeePaid();

    BackOffice::outbox()->push(
        FinanceRequests::createOperation(
            category: 'entry_fee',
            amount: $entry->fee,
            operationDate: $entry->fee_paid_at->toDateString(),
            sourceType: 'stage_entry_fee',
            sourceId: $entry->id,
            externalRef: new ExternalRef('stage', $entry->stage_id, $entry->stage->name),
        ),
        tenant: $entry->organization_id,
        actor: new Actor($user->id, $user->name),
    );
});

// Снятие отметки — сторно по источнику: оно в том же потоке и не уйдёт раньше операции.
BackOffice::outbox()->push(FinanceRequests::reverseBySource('stage_entry_fee', $entry->id), $entry->organization_id);
```

Порядок: сообщения одного потока (`stream`) уходят по очереди. Поток операции с источником —
`source:<source_type>:<source_id>`; его можно задать явно аргументом `stream`.

Что происходит с сообщением:

| Ответ сервиса | Что делает очередь |
| --- | --- |
| 2xx | отправлено, событие `OutboxMessageSent` (в нём ответ, например id операции) |
| нет ответа, 5xx, circuit breaker открыт, 401/403 | повтор без ограничения, задержка растёт до 10 минут |
| `409 operation_not_found` (сторно раньше операции), 429 | повтор, до `max_attempts` раз |
| остальные отказы (422, `409 idempotency_conflict`, `period_locked`) | `failed`, событие `OutboxMessageFailed`; поток ждёт |

«Застрявшие»: `OutboxMessage::stuck()` — для экрана в проекте, `php artisan back-office:outbox-stuck` — в консоли.
После исправления причины: `php artisan back-office:outbox-retry <id>…` или `--all`.

## Чтение и записи, которых ждёт человек, — напрямую

```php
$finance = BackOffice::finance($organization->id, new Actor($user->id, $user->name));

$summary = $finance->summary([new ExternalRef('stage', $stage->id), new ExternalRef('championship', $champ->id)]);
$summary->income->fact;                        // '7500.00'
$summary->row('entry_fee')?->totals->deviation;

$page = $finance->listOperations(ref: new ExternalRef('stage', $stage->id), perPage: 50);
$next = $finance->listOperations(ref: new ExternalRef('stage', $stage->id), cursor: $page->nextCursor);

$finance->putBudget(new ExternalRef('stage', $stage->id, $stage->name), ['entry_fee' => '10000', 'staff' => '3000']);
$finance->lock(new ExternalRef('stage', $stage->id));          // закрыть этап
$xlsx = $finance->export([new ExternalRef('stage', $stage->id)]);

BackOffice::tenants()->put($organization->id, $organization->name, 'RUB', 'Europe/Moscow');
BackOffice::finance(null)->putCategory('entry_fee', 'Взносы', 'income');   // набор статей проекта
```

Ошибки:

- `ApiErrorException` — сервис отказал: `status()`, `errorCode()` (`period_locked`, `unknown_category`…),
  `details()`, `isRetriable()`.
- `ServiceUnavailableException` — сервис не ответил или ответил 5xx; `CircuitOpenException` — запросы временно
  не отправляются. Экран показывает «Финансы временно недоступны»; запись идёт через очередь.

Чтение повторяется дважды (100 и 400 мс), запись — нет. После 5 сбоев подряд circuit breaker на 30 секунд
перестаёт отправлять запросы.

## Вебхуки

```php
use Mnd\BackOffice\Dto\FinanceOperation;
use Mnd\BackOffice\Laravel\Events\WebhookReceived;

Event::listen(function (WebhookReceived $received) {
    if ($received->event->type === 'finance.operation.created') {
        $operation = FinanceOperation::fromArray($received->event->data);
        // обновить проекцию в проекте
    }
});
```

Подпись проверяется, повтор того же `event_id` отбрасывается. Слушатель, который бросил исключение, откатывает
отметку о получении: сервис получит 500 и пришлёт событие снова.

## Без Laravel

```php
$client = BackOfficeClient::create('https://localhost', $key, new CircuitBreaker($psr16Cache));
$client->forTenant('org-1')->finance()->summary([new ExternalRef('stage', '1')]);

$event = (new WebhookVerifier($secret))->verify($_SERVER['HTTP_X_SIGNATURE'] ?? null, file_get_contents('php://input'));
```

## Разработка

```sh
composer install
vendor/bin/phpcs
vendor/bin/phpunit
```
