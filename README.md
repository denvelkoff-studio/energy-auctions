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
⚠️ Затварянето на търгове **не** разчита на WP-cron (ненадежден). Използва се реален системен cron, който всяка минута извиква действието `ea_close_auctions_cron` (активира започнали, затваря изтекли, генерира поръчки за победители, маркира неплатени).

**Добавете в hPanel → Advanced → Cron Jobs** (заменете `USER` с реалния потребител; проверете пътя до `wp` с `which wp`):

```
* * * * * cd /home/USER/public_html && wp eval 'do_action("ea_close_auctions_cron");' --quiet > /dev/null 2>&1
```

Ако `wp` (WP-CLI) не е в PATH, използвайте пълния път, напр. `/usr/local/bin/wp` или `php /home/USER/wp-cli.phar`.

Плъгинът има и **резервен WP-cron** на всеки 5 минути, но той е по-неточен — реалният системен cron е препоръчителният механизъм.

Полезни филтри: `ea_anti_sniping_minutes` (по подразб. 5), `ea_payment_due_days` (3), `ea_poll_interval_seconds` (7).

## Списък с активни търгове (меню „Аукцион“)
Поставете шорткода на страница:
```
[ea_active_auctions status="active" limit="12"]
```

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
