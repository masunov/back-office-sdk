# mnd/back-office-sdk

PHP SDK сервиса back-office с адаптером Laravel: клиент, DTO, проверка подписи вебхуков, ретраи чтения,
circuit breaker и исходящая очередь.

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
| `429 too_many_requests` (лимит проекта)                             | повтор без ограничения не раньше `Retry-After`; остаток пачки ждёт          |
| `409 operation_not_found` (сторно раньше операции)                  | повтор, до `max_attempts` раз                                               |
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
  `details()`, `isRetriable()`; при `429` — `isRateLimited()` и `retryAfter()` (секунды из `Retry-After`).
- `ServiceUnavailableException` — сервис не ответил или ответил 5xx; `CircuitOpenException` — запросы временно
  не отправляются. Экран показывает «Финансы временно недоступны»; запись идёт через очередь.

Чтение повторяется дважды (100 и 400 мс), запись — нет. Отказ по лимиту (`429`) сразу не повторяется и
circuit breaker не открывает: повтор раньше `Retry-After` только тратит лимит. После 5 сбоев подряд circuit breaker на 30 секунд
перестаёт отправлять запросы.

## Обязательства (finance)

Договорённость заплатить контрагенту или получить от него деньги, с графиком оплат. Это не операция: факт и
отчёты не меняются, закрытая ссылка не мешает. Направление — из статьи.

```php
use Mnd\BackOffice\Requests\FinanceRequests;

BackOffice::outbox()->push(
    FinanceRequests::createObligation(
        'hotel',
        new ExternalRef('contractor', (string) $hotel->id, $hotel->name),
        '24000.00',
        'trip_obligation',
        (string) $obligation->id,                    // источник обязателен
        new ExternalRef('trip', (string) $trip->id),
        [
            ['key' => 'prepay', 'amount' => '12000.00', 'due_on' => '2026-11-05', 'label' => 'Предоплата'],
            ['key' => 'checkin', 'amount' => '12000.00', 'due_on' => null, 'label' => 'При заселении'],
        ],
    ),
    'org-1'
);

// Дальше — по источнику, тем же потоком: изменения не обгонят создание.
$key = 'trip_obligation:' . $obligation->id;
BackOffice::outbox()->push(FinanceRequests::putObligationSchedule($key, $lines, 'Гостиница перенесла предоплату'), 'org-1');
BackOffice::outbox()->push(FinanceRequests::updateObligation($key, 'fulfilled'), 'org-1');

$finance = BackOffice::forTenant('org-1')->finance();
$o = $finance->obligation($key);                    // $o->totals, $o->schedule[0]->remaining, $o->overdue
[$page, $cursor] = $finance->obligations(ref: new ExternalRef('trip', '42'), overdue: true);
```

У строки графика может быть свой объект: договор спонсора чемпионата платится по этапам —
`['key' => 'stage-3', 'amount' => '100000', 'due_on' => '2027-01-10', 'external_ref' => ['type' => 'stage', 'id' => '3']]`;
`obligations(ref: new ExternalRef('stage', '3'))` найдёт его по этапу. `$o->due()` — что должно было прийти к
сегодня и не пришло.

**Признание.** Операция признаёт обязательство по своему источнику — полем `obligation`:

```php
BackOffice::outbox()->push(
    FinanceRequests::createOperation(
        'hotel', '10000.00', '2026-11-01', 'trip_cost_entry', (string) $entry->id,
        new ExternalRef('trip', (string) $trip->id),
        obligation: 'trip_obligation:' . $obligation->id,
    ),
    'org-1'
);
// Операция ушла раньше обязательства — связать отдельно, в потоке обязательства:
BackOffice::outbox()->push(FinanceRequests::linkRecognition($key, 'trip_cost_entry', (string) $entry->id), 'org-1');
```

`totals.recognized` — сумма связанных операций с их сторно; связи — `$finance->obligation($key)->recognitions`.
Источник уже признаёт другое обязательство — `recognition_taken`; направление статьи другое — `direction_mismatch`.

Обязательство по источнику ещё не дошло — `409 obligation_not_found`, очередь повторит.

**Платежи.** Выплата по расходу или поступление по доходу — не операция: факт не меняется, закрытый выезд или этап
не мешает.

```php
BackOffice::outbox()->push(
    FinanceRequests::createPayment($key, '10000.00', 'trip_payment', (string) $payment->id, '2026-11-08'),
    'org-1'
);
// Возврат — kind refund; бартер спонсора — method barter. Ошибочная запись — сторно, по источнику:
BackOffice::outbox()->push(FinanceRequests::reversePayment('trip_payment:' . $payment->id), 'org-1');

[$payments, $cursor] = $finance->payments(obligation: $key);   // $finance->obligation($key)->payments — тоже
```

Больше остатка или возврат больше оплаченного — `payment_exceeds_obligation`, не повторяется. Платежа по
источнику ещё нет — `409 payment_not_found`, очередь повторит.

**Платёжный календарь.** Что осталось заплатить и получить — строки графиков с остатком после оплат:

```php
$calendar = $finance->paymentCalendar('2026-11-01', '2026-11-30', ref: new ExternalRef('trip', '42'));
$calendar->lines;                          // bucket: overdue / in_period / undated, due_on, remaining, counterparty…
$calendar->total('expense', 'overdue');    // и in_period, undated, paid_in_period (деньги без бартера)
```

Оба дня периода включительно. Раскладывать строки по датам выезда или этапа — на стороне проекта. Сумма графика не равна сумме
— `schedule_mismatch`; исполнение назад — `invalid_transition`.

## Денежные счета (finance)

Наличные, карты, банковские счета арендатора (BO-FIN-007). У счёта есть:

- `balance` — остаток в валюте счёта;
- `baseCost` — стоимость остатка в валюте арендатора;
- `averageRate` — средневзвешенный курс.

Остаток меняют только движения денег. Движение — не операция: факт и отчёты не меняются, закрытый выезд не мешает.

```php
$finance = BackOffice::forTenant('org-1')->finance();
$usd = $finance->createAccount('Доллары в кассе', 'cash', 'USD');
$finance->accounts();                               // $account->balance, ->baseCost, ->averageRate

// Покупка валюты: 9 000 ₽ и 100 ₽ комиссии за 100 $ — 91 ₽ за доллар, комиссия входит в цену.
$exchange = $finance->moveMoney(
    FinanceRequests::exchange($rub->id, '9000.00', $usd->id, '100.00', 'trip_exchange', (string) $op->id, fee: '100.00')
);
$exchange->effectiveRate;                           // '91.000000000000'
$exchange->account($usd->id)->averageRate;

// Расход из валютной кассы: стоимость — по среднему курсу счёта.
$movement = $finance->moveMoney(
    FinanceRequests::withdraw($usd->id, '30.00', 'expense', 'trip_cost_entry', (string) $entry->id, ref: new ExternalRef('trip', '42'))
);
$movement->debit()->rate;                           // фактический курс потраченных денег
$movement->debit()->baseAmount;                     // сколько это в валюте арендатора

// Ошибочное движение — сторно, по источнику; деньги вернутся по курсу исходного движения.
BackOffice::outbox()->push(FinanceRequests::reverseMoneyMovement('trip_cost_entry:' . $entry->id), 'org-1');

[$movements, $cursor] = $finance->moneyMovements(account: $usd->id, ref: new ExternalRef('trip', '42'));
```

Движения:

- `topUp` — пополнение. Для счёта не в валюте арендатора нужна оценка `baseAmount`, иначе `valuation_required`.
- `withdraw` — списание. Может ссылаться на операцию и платёж: `operation`, `payment` — id или источник.
- `transfer` — перевод, только между счетами одной валюты.
- `exchange` — обмен, только между разными валютами.
- `adjust` — корректировка: `delta` со знаком и причина.

Перевод между разными валютами или обмен в одной — `currency_mismatch`. Источник у движения обязателен. Повтор с
тем же источником возвращает то же движение, с другими данными — `idempotency_conflict`.

Ошибки:

- `insufficient_funds` — денег на счёте меньше, чем нужно (поля `available`, `requested`); не повторяется.
- `account_archived` — движение в архивный счёт.
- `account_not_empty` — счёт с остатком не убирается в архив.
- `409 operation_not_found`, `payment_not_found`, `money_movement_not_found` — объекта по источнику ещё нет;
  очередь повторит.

Вебхуки `finance.money_movement.created` и `.reversed` разбираются через `FinanceMoneyMovement::fromArray($event->data)`
— со счетами после движения; `finance.account.*` — через `FinanceAccount::fromArray()`.

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

// Графики нескольких площадок за одни даты — одним запросом, в порядке переданных id.
$calendars = $tt->calendars(['track-1', 'track-2'], '2026-11-02', '2026-11-08');

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

// Названия того, что на экране, без чтения всего каталога: по id, архивные тоже, запросами до 200.
$named = $wh->itemsByIds(array_column($balances, 'itemId')); // [id => WarehouseItem]
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

// Израсходовано там, куда выдали (масло на выезде, разрушенная шина): погашает выдачу без возврата, остаток места
// второй раз не меняется. Без quantity — остаток выдачи; серийная единица выдачи становится written_off.
BackOffice::outbox()->push(
    WarehouseRequests::consumeBySource($oil->id, 'trip_issue', $tripIssue->id, quantity: '6',
        sourceType: 'trip_consumption', sourceId: $consumption->id),
    tenant: $team->id
);

// Ответ на движение — с остатками мест после него.
$movement = $wh->move(WarehouseRequests::transfer($tent->id, $store->id, $field->id, quantity: '3'));
$movement->balances; // list<WarehouseBalance>

$wh->issues(openOnly: true, recipient: new ExternalRef('stage_staff', $staff->id)); // что не вернулось и не израсходовано

// Все единицы (сверка по QR) — по страницам: units(), issues(), reservations() отдают только первую, до 1000.
$cursor = null;
do {
    [$units, $cursor] = $wh->unitsPage(locationId: $store->id, perPage: 200, cursor: $cursor);
    // …
} while ($cursor !== null);

// Метки QR: партия для печати (PDF делает проект), скан, назначение, замена.
[$batchId, $labels] = $wh->issueLabels(48);            // $label->code — в QR, $label->shortCode (Q-000017) — на наклейку
$label = $wh->resolveLabel($scanned);                  // код QR или короткий код; $label->unit — карточка единицы
if ($label->isAvailable()) {
    $wh->assignLabel($label->code, $unit->id);         // прежняя метка единицы снимается как replaced
}
$wh->retireLabel($lost->code, 'lost');                 // навсегда; снятая метка не назначается снова
// label_not_found, label_unavailable (назначена другой единице или снята), unit_unavailable (единица списана)
$wh->setUnitCondition($unitId, 'in_repair', 'Не включается');                       // состояние единицы на месте
```

- Количества и стоимости — строки: `2.500`, `15000.00`. Серийная позиция движется по одной единице: приход —
  по `serialNumber`, остальное — по `unitId`.
- Движения одной позиции идут в одном потоке очереди (`warehouse_item:<id>`).
- `inventory()` передаёт фактическое количество; без расхождения `move()` вернёт `null`.
- Выдача погашается возвратами и расходом: `WarehouseIssue::$returnedQuantity` и `$consumedQuantity`; закрытая
  (`closedAt`) не открыта и не просрочена.
- Отказы: `insufficient_stock`, `unit_unavailable`, `return_exceeds_issue`, `consume_exceeds_issue`, `unknown_item`, `unknown_issue`,
  `period_locked` (списание по закрытой в finance ссылке) и другие — `docs/openapi.yaml` сервиса.

### Резервы

Резерв держит количество на месте под этап (или другой `holder`): другие движения берут только свободное
(`WarehouseBalance::$available = quantity − reserved`), зарезервированную единицу двигает только её резерв.

```php
// Под этап, до его конца; серийная — одной unitId или целым quantity любых свободных единиц.
BackOffice::outbox()->push(
    WarehouseRequests::reserve($tent->id, $store->id, new ExternalRef('stage', $stage->id, $stage->name),
        quantity: '4', expiresAt: $stage->ends_at, sourceType: 'stage_item', sourceId: $row->id),
    tenant: $organization->id
);
// Выдача по резерву — из его места, без quantity — весь остаток. По источнику: id резерва очередь ещё не знает.
BackOffice::outbox()->push(
    WarehouseRequests::issueReservedBySource($tent->id, 'stage_item', $row->id,
        new ExternalRef('stage_staff', $staff->id, $staff->name), quantity: '2'),
    tenant: $organization->id
);
// Отмена освобождает остаток; повторная возвращает резерв как есть.
BackOffice::outbox()->push(WarehouseRequests::cancelReservationBySource($tent->id, 'stage_item', $row->id), tenant: $organization->id);

$wh->reserve(WarehouseRequests::reserve(...));                             // сразу, когда человек ждёт ответа
$wh->reservations(activeOnly: true, holder: new ExternalRef('stage', $stage->id));
```

- Резерв, выдача и отмена по нему идут в потоке позиции и не обгоняют друг друга. Резерв по источнику ещё не дошёл —
  `409 reservation_not_found`, очередь повторяет; `404` с тем же кодом (неизвестный id) — нет.
- Резерв закрывается: `fulfilled` (выдан весь), `cancelled`, `expired` (по `expires_at`). Инвентаризация, нашедшая
  меньше зарезервированного, сокращает самые новые резервы места.
- Изменения приходят вебхуком `warehouse.reservation.changed`: `WarehouseReservation::fromArray($event->data)`,
  `action` — `created`, `issued`, `fulfilled`, `cancelled`, `expired`, `shortened`.
- Отказы: `insufficient_stock` (`available`, `reserved`, `requested`), `unit_reserved`, `reservation_closed`,
  `reservation_exceeded`, `unknown_reservation`, `invalid_reservation`.

**Проводки склада в finance.** Приход со стоимостью и списание становятся операциями по статьям из
`$warehouse->putSettings($purchase, $writeOff)`. Проект, который признаёт затраты сам (DriftManager), оставляет
статьи пустыми, а сервис может запретить проводки всему проекту. Это видно в настройках:
`$warehouse->settings()['finance_auto_post'] === false`.

## Курсы валют (currency)

Официальные курсы ЦБ РФ (`cbr`, к RUB) и НБРБ (`nbrb`, к BYN), общие для всех проектов: арендатор и актор не
передаются. Нужен модуль `currency` у проекта и право ключа `currency:read`. Источник указывается всегда — курс
никогда не берётся у другого. Курсы и суммы — decimal-строки, не float.

```php
$currency = BackOffice::currency();

// Курс действует с даты установления (rateDate) до следующего: на выходной — курс пятницы (ЦБ).
$usd = $currency->rate('cbr', 'USD', 'RUB', '2026-11-08');  // $usd->rate === '82.138600000000'
$all = $currency->rates('nbrb', 'BYN', ['RUB', 'USD', 'EUR']); // [quote => CurrencyRate], на сегодня источника

// Пересчёт по курсу снимка, half-up до копеек. Сумму хранить вместе с rate и rate->rateSetId:
// повтор операции берёт их, а не новый курс.
$conversion = $currency->convert('cbr', '1000.00', 'RUB', 'USD', '2026-11-09');
$manual     = $currency->convert('manual', '100', 'USD', 'RUB', '2026-11-09', rate: '80');

// История пары за период до 366 дней — все страницы подряд.
foreach ($currency->history('cbr', 'RUB', 'USD', '2026-01-01', '2026-11-09') as $rate) {
    // $rate->rateDate … $rate->validThrough
}

$currency->sources(); // confirmedThrough — последний день, на который курс известен
```

Ошибки: `404 rate_not_found` — курс на дату ещё не известен (поле `confirmed_through`), `409 rate_stale` — курсы
на сегодня ещё не загружены (`isRetriable()`: загрузка догоняет каждый час), `422 unsupported_currency`,
`422 unknown_source`, `422 invalid_conversion`.

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
