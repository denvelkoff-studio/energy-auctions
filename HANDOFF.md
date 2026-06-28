# HANDOFF — Energy Auctions

Живо състояние на проекта. Обновява се след всяка фаза.

---

## Текущ статус: Фаза 1 завършена ✅ (чакаща преглед на чекпойнт)

### Какво е готово (Фаза 1 — Фундамент)
- **Скеле на плъгина**: `energy-auctions.php` (header, константи, активация/деактивация hooks, bootstrap на `plugins_loaded`).
- **Autoloader** (`includes/class-ea-autoloader.php`): мапва `EnergyAuctions\...` → `class-ea-*.php` по WP конвенция, с под-namespace → под-папка.
- **Главен клас** (`includes/class-ea-plugin.php`): singleton, проверка за активен WooCommerce (admin notice при липса), HPOS съвместимост, зареждане на textdomain, init на модулите.
- **Product type „auction“** (`includes/class-ea-product-type.php`): регистрация в `product_type_selector`, мапване на класа през `woocommerce_product_class`, вкарване на term в таксономията `product_type`.
- **Продуктов клас** (`includes/product/class-ea-auction-product.php`): `Auction_Product extends WC_Product`, `get_type()='auction'`, типизирани getters/setters за всички мета-полета.
- **DB таблица** (`includes/class-ea-install.php`): създава `{prefix}_ea_bids` чрез `dbDelta` при активация + auto-upgrade на init при промяна на схемата.
- **Admin UI** (`includes/admin/class-ea-product-data.php`): таб „Търг“ с полета start/bid_increment/reserve/buy_now/start_date/end_date/status, nonce, capability проверка, sanitize, валидация с грешки през admin notice, авто-изчисляване на статус от датите. Скрива стандартния „General“ ценови таб за auction, показва Inventory/Shipping/Advanced.
- **uninstall.php**: консервативно (не трие офертите освен при `EA_REMOVE_ALL_DATA`).
- **Превод**: textdomain `energy-auctions`, `languages/energy-auctions.pot` (стартов template), всички низове на български и преводими.

### Структура на DB таблицата `{prefix}_ea_bids`
| Колона | Тип | Бележка |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| auction_id | BIGINT UNSIGNED | ID на продукта-търг |
| user_id | BIGINT UNSIGNED | наддаващ |
| amount | DECIMAL(19,4) | сумата на офертата |
| max_amount | DECIMAL(19,4) NULL | за proxy bidding (отложено) |
| is_auto | TINYINT(1) | авто-оферта? (отложено) |
| created_at | DATETIME | |

Индекси: `auction_amount (auction_id, amount)`, `auction_created (auction_id, created_at)`, `user_id`.

### Мета-полета на продукта
`_ea_start_price`, `_ea_bid_increment`, `_ea_reserve_price`, `_ea_buy_now_price`, `_ea_start_date` (Y-m-d H:i:s), `_ea_end_date`, `_ea_status` (scheduled/active/ended/sold/unsold).

### Решения
- **Standalone плъгин**, не в темата — данните преживяват смяна на тема.
- **Product type = WooCommerce „auction“** (наследява checkout/плащане/акаунти).
- **Цени като DECIMAL(19,4)** в DB — без float грешки върху пари.
- **Дати**: засега `strtotime`/`gmdate`. ⚠️ Часовите зони ще се изпипат коректно във Фаза 5 (магазинът е BG/EET).
- **Статус** се авто-изчислява от датите при запис, но `sold`/`unsold` са финални (само от затварянето).
- **uninstall** е консервативен (пази офертите по подразбиране).

### Чекпойнт за преглед (Фаза 1)
1. Admin: създай продукт → избери тип **„Търг (auction)“** → таб **„Търг“** показва всички полета.
2. Валидация: опитай край преди начало / празна стъпка → виж admin error notice.
3. Таблицата `{prefix}_ea_bids` е създадена при активация (виж структурата по-горе).

### Отворени въпроси
- Нужен ли е отделен архивен изглед/меню „Аукцион“ още сега или във Фаза 6? (план: Фаза 6)

---

## Следва: Фаза 2 — Наддаване (frontend)
- Текуща оферта + минимална следваща + форма + история (маскирани имена A***y).
- Запис с DB транзакция + `SELECT ... FOR UPDATE` (race condition).
- Валидации (логнат, ≥ мин. следваща, active, не-собственик).
- „Купи сега“ → кошница + затваряне.

---

## CRON (за Фаза 3 — записва се тук предварително)
Реален системен cron за надеждно затваряне (НЕ WP-cron). Команда за hPanel (ще се финализира във Фаза 3):
```
*/1 * * * * wp --path=/home/USER/public_html eval 'do_action("ea_close_auctions_cron");' >/dev/null 2>&1
```
> Точната команда ще бъде потвърдена във Фаза 3 след имплементацията на closer-а.

---

## Deploy
- Repo root = плъгина. Hostinger Git deploy → `public_html/wp-content/plugins/energy-auctions`.
- Темата `energy-things` е в отделно repo — не се пипа.
