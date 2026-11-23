## Release notes: consumeFromLocation

### Что изменилось
Additive WarehouseRequests::consumeFromLocation: consume с from_location_id, quantity/packages, optional lot_id и прежними source/ref/occurred_at. Прежний consumeIssue/consumeBySource сохранён. Сервис должен объявить settings.data.capabilities.consume_from_location=true и применить миграцию direct consumption. Новый helper сам по себе не отправляет запрос.

### Инфраструктура
Не требуется. Secrets.yml: нет.

### Переменные .env
Новых нет.

### Порядок действий
1. Новых env нет.
2. Ansible не требуется.
3. Сначала BackOffice с миграцией, затем обычная поставка SDK/потребителя.
4. Разовых команд нет.
5. Проверить capability и FEFO/lot/повторы на отдельном tenant. Unit tests проверяют тело без issue.
6. Сообщить потребителям: consumeFromLocation для прямого расхода, consumeIssue для выданного; цены/списание порчи не смешиваются.

Проверено: 82 PHPUnit-теста / 399 assertions, PHP_CodeSniffer проходит. Сетевые вызовы — fixtures.

### Откат
Предыдущая версия SDK, данные не меняются. Не подменять незавершённые source/idempotency keys другим типом движения.
