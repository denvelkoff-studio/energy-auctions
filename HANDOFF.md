# HANDOFF — Energy Auctions

Живо състояние на проекта. Обновява се след всяка фаза.

---

## Текущ статус: Фази 1–6 имплементирани ✅ (MVP готов за тест в среда)

> Кодът е написан и минава `php -l` чисто. Понеже тук няма WordPress runtime, **функционалният тест се прави в реалната среда** (Hostinger/staging) — виж „Какво да тествам“ най-долу.

---

## Архитектура (накратко)
- Самостоятелен плъгин (не в темата). Namespace `EnergyAuctions\`, префикс `ea_`, textdomain `energy-auctions`.
- Auction = WooCommerce product type **`auction`** (`Auction_Product extends WC_Product`).
- Оферти в собствена таблица `{prefix}_ea_bids`.
- Мета на продукта: `_ea_start_price`, `_ea_bid_increment`, `_ea_reserve_price`, `_ea_buy_now_price`, `_ea_start_date`, `_ea_end_date`, `_ea_status`, `_ea_winner_id`, `_ea_winning_amount`, `_ea_order_id`.

## Файлова структура
```
energy-auctions.php                         bootstrap
uninstall.php                               консервативно изтриване
includes/
  class-ea-autoloader.php
  class-ea-plugin.php                       singleton, init на модулите
  class-ea-install.php                      dbDelta таблица + cron cleanup
  class-ea-time.php                         часови зони (абсолютни timestamps)
  class-ea-product-type.php                 регистрация на типа
  class-ea-bids.php                         data слой + атомарно place_bid (FOR UPDATE)
  class-ea-ajax.php                         ea_auction_state (polling)
  class-ea-closer.php                       cron: активиране/затваряне/поръчки/неплатени/guard
  class-ea-mailer.php                       имейли (WC mailer)
  product/class-ea-auction-product.php
  admin/class-ea-product-data.php           таб „Търг“ + валидация + колона в списъка
  frontend/class-ea-bidding.php             форма/buy-now handlers + cart guard
  frontend/class-ea-product-display.php     UI на продуктовата страница
  frontend/class-ea-shortcodes.php          [ea_active_auctions]
assets/css/ea-frontend.css                  бранд: графит + син glow + злато
assets/js/ea-frontend.js                    countdown + AJAX polling + confirm
languages/energy-auctions.pot
```

---

## По фази

### Фаза 1 — Фундамент ✅
Скеле, autoloader, активация/деактивация, product type, DB таблица (dbDelta), admin таб „Търг“ с валидация, авто-статус от датите.

### Фаза 2 — Наддаване (frontend) ✅
- Продуктова страница (hook `woocommerce_auction_add_to_cart`): текуща оферта, мин. следваща (текуща+стъпка), форма, история с маскирани имена (`А***р`).
- **Атомарно наддаване**: `Bids::place_bid()` прави `START TRANSACTION` → `SELECT ID FROM wp_posts WHERE ID=? FOR UPDATE` (row lock сериализира паралелните оферти за този търг) → чете свежо състояние под lock-а → валидира → insert → `COMMIT`. Срещу race condition (двама в същата милисекунда → само валидно ескалиращи оферти минават, без дубъл).
- Валидация: само логнати; ≥ мин. следваща; търгът да е `active` и неизтекъл; собственикът не може да наддава.
- „Купи сега“ → маркира `sold`, добавя в кошницата (guard пази стандартно add-to-cart), цена в кошницата = buy_now → checkout.

### Фаза 3 — Затваряне ✅
- `Closer::run()` (от cron): `activate_due()` (scheduled→active), `close_expired()` (active→sold/unsold), `sweep_unpaid()`.
- Победител ако най-високата ≥ резерв → `sold` + **чакаща WooCommerce поръчка** за победителя (native плащане, адрес от профила, status `pending`); иначе `unsold`.
- **Anti-sniping** (в `Bids::place_bid`): оферта в последните N мин. (по подразб. 5) удължава края с N мин. (`ea_anti_sniping_minutes`).

### Фаза 4 — Real-time + имейли ✅
- AJAX polling (`ea_auction_state`, ~7s, `ea_poll_interval_seconds`): обновява текуща оферта, брой, мин. следваща, край; при смяна на статус презарежда.
- Countdown таймер (JS, всяка секунда, от ISO с offset).
- Имейли (BG, преводими, неблокиращи през WC mailer): **наддаден си** (до предишния водач), **спечели** (до победителя + линк за плащане), **търгът приключи** (до собственика), **неплатено** (до победителя).

### Фаза 5 — Edge cases ✅
- Неплатено: `sweep_unpaid()` маркира поръчки с изтекъл `_ea_due_date` (по подразб. 3 дни, `ea_payment_due_days`) + имейл. (Повторно листване — по-късно.)
- Изтрит/кошнат продукт по време на търг → `Closer::on_trash()` безопасно затваря (`unsold`).
- Часови зони: `Time` helper работи с **абсолютни UNIX timestamps** спрямо `wp_timezone()` (магазинът BG/EET) — консистентни сравнения; `now_ts()=time()`.
- Confirmation reminder преди оферта (JS `confirm`).

### Фаза 6 — Дизайн ✅
- Брандиран CSS (графит `#1c2128` + син glow `#4ea1ff` + злато `#d4af37`, Spectral/Inter) — само `.ea-*` класове, темата може да override-не.
- Стилизирани: countdown, „текуща оферта“ badge, форма, история, карти за архив.
- Шорткод `[ea_active_auctions status="active" limit="12"]` за меню „Аукцион“.

---

## CRON команда (за hPanel → Cron Jobs)
Реален системен cron, всяка минута (заменете `USER`, проверете пътя до `wp` с `which wp`):
```
* * * * * cd /home/USER/public_html && wp eval 'do_action("ea_close_auctions_cron");' --quiet > /dev/null 2>&1
```
Резервен WP-cron (на 5 мин.) се регистрира автоматично, но реалният cron е препоръчителен. WP-CLI е наличен на Hostinger; при нужда ползвайте пълен път/`wp-cli.phar`.

---

## Какво да тествам в средата (понеже няма WP runtime тук)
1. **Phase 1**: създай auction продукт; таб „Търг“; валидация (край преди начало и т.н.); колона „Търг“ в списъка с продукти.
2. **Phase 2**: логнат потребител наддава; история с маскирани имена; мин. следваща се вдига. **Race тест**: два паралелни request-а със същата сума → само един успява (другият получава „офертата трябва да е поне …“).
3. **Phase 3**: ръчно `wp eval 'do_action("ea_close_auctions_cron");'` за изтекъл търг → правилен победител, `sold`/`unsold`, генерирана pending поръчка; late bid → удължен край.
4. **Phase 4**: polling обновява без refresh; имейли пристигат (BG).
5. **Phase 5**: неплатена поръчка след срок → маркирана + имейл; кошване на активен търг → `unsold`.
6. **Phase 6**: визуална проверка >1280px (продуктова страница + `[ea_active_auctions]`).

## Решения
- Цени `DECIMAL(19,4)` (без float грешки). Сравнения с малък epsilon при оферти.
- Row lock на `wp_posts` реда (винаги съществува) вместо `GET_LOCK`/lock на празен резултат — гарантира сериализация дори при 0 оферти.
- `Time` използва абсолютни timestamps (`time()` + `wp_timezone()`), за да няма offset бъгове.
- Buy-now ползва нативната кошница; cron-победителят получава генерирана поръчка — два отделни, но непрепокриващи се пътя (buy-now вече е `sold`, cron го прескача).
- uninstall е консервативен (пази офертите освен при `EA_REMOVE_ALL_DATA`).
- **Формите се обработват на `template_redirect`** (frontend контекст), НЕ през `admin-post.php` — иначе `WC()->cart` и `wc_add_notice` не са заредени (admin контекст). Форма → POST към permalink-а на продукта с `ea_action`.
- Buy-now: добавя в кошницата ПЪРВО, маркира `sold` само при успех; поръчката се свързва с търга на `woocommerce_checkout_order_processed` / `woocommerce_store_api_checkout_order_processed` (класически + block checkout) → `_ea_order_id`, `_ea_due_date`.
- Таблицата `ea_bids` е `ENGINE=InnoDB` (row lock-овете изискват InnoDB).

### Поправки след code review (Фаза 2–5)
- ❌→✅ admin-post контекст бъг (cart/notices липсваха) → преместено на `template_redirect`.
- ❌→✅ buy-now поръчка вече се проследява от `sweep_unpaid`.
- ❌→✅ явно `ENGINE=InnoDB`.

## Отворени въпроси / отложено (по дизайн)
- Dutch/sealed/reverse, auto/proxy bidding (колоните `is_auto`/`max_amount` са готови за това), watchlist, такса участие, Stripe card-hold — извън MVP.
- Повторно листване на неплатени/непродадени търгове — по-късно.
- Блок (Gutenberg) за списък търгове — засега само шорткод.

## Deploy
Repo root = плъгина. Commit/push **директно в `main`** → Hostinger Git deploy към `public_html/wp-content/plugins/energy-auctions`. Темата `energy-things` е в отделно repo — не се пипа.
