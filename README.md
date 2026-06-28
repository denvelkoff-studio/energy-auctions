# Energy Auctions

Самостоятелен WordPress плъгин за търгове (auctions) към WooCommerce магазина **energy-things**.

Търгът е WooCommerce продукт от тип **`auction`** — така checkout, плащане и акаунти работят наготово. Офертите се пазят в собствена DB таблица, а плъгинът е напълно отделен от темата, за да преживее смяна на тема без загуба на данни.

## Изисквания
- WordPress 6.0+
- WooCommerce 7.0+ (активен)
- PHP 8.0+
- MySQL, валута EUR, локал български

## Инсталация
1. Сложи плъгина в `wp-content/plugins/energy-auctions` (Hostinger Git deploy към този път).
2. Активирай **Energy Auctions** от Plugins. При активация се създава таблицата `{prefix}_ea_bids`.
3. Уверй се, че WooCommerce е активен (иначе плъгинът показва admin notice и не се зарежда).

## Създаване на търг
**Продукти → Нов продукт** → тип **„Търг (auction)“** → таб **„Търг“**:
- Стартова цена, стъпка на наддаване
- Скрит резерв (опц.), „Купи сега“ (опц.)
- Начало и край
- Статус (авто от датите; `sold`/`unsold` се задават при затваряне)

## Cron (надеждно затваряне)
⚠️ Затварянето на търгове **не** разчита на WP-cron. Ще се ползва реален системен cron — командата ще се документира тук във Фаза 3 и се добавя в hPanel.

## Разработка
Виж [HANDOFF.md](HANDOFF.md) за текущо състояние, решения и следващи стъпки.

Структура:
```
energy-auctions.php          # bootstrap
uninstall.php
includes/
  class-ea-autoloader.php
  class-ea-plugin.php
  class-ea-install.php        # dbDelta таблици
  class-ea-product-type.php   # регистрация на типа
  product/class-ea-auction-product.php
  admin/class-ea-product-data.php
languages/energy-auctions.pot
```
