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

| Переменная                                          | Откуда                                                                              |
|-----------------------------------------------------|-------------------------------------------------------------------------------------|
| `BACK_OFFICE_URL`                                   | адрес сервиса, например `https://localhost`                                         |
| `BACK_OFFICE_KEY`                                   | `php artisan platform:key-issue <проект>` на сервисе                                |
| `BACK_OFFICE_WEBHOOK_SECRET`                        | `php artisan platform:project-webhook <проект> <url>` на сервисе                    |
| `BACK_OFFICE_CACHE_STORE`                           | необязательно: хранилище состояния circuit breaker, общее для всех воркеров (redis) |
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

| Ответ сервиса                                                       | Что делает очередь                                                          |
|---------------------------------------------------------------------|-----------------------------------------------------------------------------|
| 2xx                                                                 | отправлено, событие `OutboxMessageSent` (в нём ответ, например id операции) |
| нет ответа, 5xx, circuit breaker открыт, 401/403                    | повтор без ограничения, задержка растёт до 10 минут                         |
| `409 operation_not_found` (сторно раньше операции), 429             | повтор, до `max_attempts` раз                                               |
| остальные отказы (422, `409 idempotency_conflict`, `period_locked`) | `failed`, событие `OutboxMessageFailed`; поток ждёт                         |

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

## Расписание (timetable)

```php
use Mnd\BackOffice\Dto\WorkScheduleInput;
use Mnd\BackOffice\Requests\TimetableRequests;

$tt = BackOffice::timetable($school->id, new Actor($user->id, $user->name));

// График площадки: каждый день 10:00–18:00, по числу машин; обед — нерабочий интервал.
$tt->createWorkSchedule(WorkScheduleInput::working('track-1', '2026-11-01', '10:00', '18:00', maxEntries: 3,
    metadata: ['car_ids' => [1, 2, 3]]));
$tt->createWorkSchedule(WorkScheduleInput::nonWorking('track-1', '2026-11-01', '13:00', '14:00', description: 'Обед'));

// Свободные слоты с пулом машин окна; время — со смещением площадки.
$slots = $tt->slots('track-1', '2026-11-03', duration: 60, resourceRefs: ['car-1', 'car-2', 'car-3']);

// Запись с проверкой у проекта (баланс сертификата): холд → своя транзакция → confirm или cancel.
$booking = $tt->hold('track-1', $slots[0]->startsAt, $slots[0]->endsAt, 'Иванов — 000123',
    ttl: 300, resourceRef: $slots[0]->freeResourceRefs[0], externalRef: new ExternalRef('certificate', $cert->id));

DB::transaction(function () use ($booking, $cert, $school) {
    $cert->holdMinutes($booking->duration, $booking->id);
    BackOffice::outbox()->push(TimetableRequests::confirm($booking->id), tenant: $school->id);
});
```

- Без проверки у проекта — сразу `$tt->book(...)`.
- Переходы (`confirm`, `complete`, `cancel`, `noShow`) идемпотентны: их можно отправлять через очередь, повтор
  отвечает бронью как есть. Изменения одной брони идут в одном потоке очереди и не обгоняют друг друга.
- Перенос — `reschedule($id, $startsAt, $endsAt, $version)`: `version` — та, что видел проект; без `resourceRef`
  ресурс остаётся, `null` освобождает его.
- Правило с активными бронями меняется только безопасно (больше мест, шире время); остальное —
  `closeWorkSchedule($id, $fromDate, replacement: WorkScheduleInput::working(...))`.
- Отказы: `slot_unavailable`, `resource_conflict`, `outside_working_hours`, `hold_expired`, `version_conflict`,
  `has_active_bookings` (в `details()['bookings']` — затронутые брони) и другие — `docs/openapi.yaml` сервиса.

## Склад (warehouse)

```php
use Mnd\BackOffice\Dto\WarehouseItemInput;
use Mnd\BackOffice\Requests\WarehouseRequests;

$wh = BackOffice::warehouse($organization->id, new Actor($user->id, $user->name));

// Позиции и места; место этапа связано с этапом через external_ref.
$tent  = $wh->createItem(new WarehouseItemInput('Шатёр 3×3', 'шт', minBalance: '2'));
$radio = $wh->createItem(new WarehouseItemInput('Рация', 'шт', accounting: 'serial'));
$store = $wh->createLocation('Склад', 'warehouse');
$field = $wh->createLocation('Этап 3', 'field', externalRef: new ExternalRef('stage', $stage->id, $stage->name));

// Приход со стоимостью: при включённом finance и статье в settings — расход в finance.
$wh->move(WarehouseRequests::receipt($tent->id, $store->id, quantity: '10', unitCost: '15000.00'));
$wh->move(WarehouseRequests::receipt($radio->id, $store->id, serialNumber: 'R-0001'));

// Выдача человеку этапа до конца этапа и возврат — через очередь: возврат не обгонит выдачу.
BackOffice::outbox()->push(
    WarehouseRequests::issue($tent->id, $store->id, new ExternalRef('stage_staff', $staff->id, $staff->name),
        quantity: '2', dueAt: '2026-11-03T20:00:00+05:00', sourceType: 'stage_staff_issue', sourceId: $issue->id),
    tenant: $organization->id
);
// Возврат — по источнику выдачи: очередь ещё не знает id выдачи в сервисе. Выдача не дошла — 409 issue_not_found, повтор.
BackOffice::outbox()->push(
    WarehouseRequests::returnBySource($tent->id, 'stage_staff_issue', $issue->id, sourceType: 'stage_staff_return',
        sourceId: $return->id),
    tenant: $organization->id
);

// Ответ на движение — с остатками мест после него.
$movement = $wh->move(WarehouseRequests::transfer($tent->id, $store->id, $field->id, quantity: '3'));
$movement->balances; // list<WarehouseBalance>

$wh->issues(openOnly: true, recipient: new ExternalRef('stage_staff', $staff->id)); // что не вернулось
$wh->setUnitCondition($unitId, 'in_repair', 'Не включается');                       // состояние единицы на месте
```

- Количества и стоимости — строки: `2.500`, `15000.00`. Серийная позиция движется по одной единице: приход —
  по `serialNumber`, остальное — по `unitId`.
- Движения одной позиции идут в одном потоке очереди (`warehouse_item:<id>`).
- `inventory()` передаёт фактическое количество; без расхождения `move()` вернёт `null`.
- Отказы: `insufficient_stock`, `unit_unavailable`, `return_exceeds_issue`, `unknown_item`, `unknown_issue`,
  `period_locked` (списание по закрытой в finance ссылке) и другие — `docs/openapi.yaml` сервиса.

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
