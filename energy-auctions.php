<?php
/**
 * Plugin Name:       Energy Auctions
 * Plugin URI:        https://energy-things.example/
 * Description:       Самостоятелен плъгин за търгове (auctions) към WooCommerce — стандартен търг с ръчно наддаване, скрит резерв, „купи сега“, надеждно затваряне през реален cron.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            energy-things
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       energy-auctions
 * Domain Path:       /languages
 * WC requires at least: 7.0
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

defined( 'ABSPATH' ) || exit;

/**
 * Основни константи на плъгина.
 */
define( 'EA_VERSION', '0.1.0' );
define( 'EA_PLUGIN_FILE', __FILE__ );
define( 'EA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once EA_PLUGIN_DIR . 'includes/class-ea-autoloader.php';

Autoloader::register();

/**
 * Активация: създава DB таблиците и записва версията.
 */
register_activation_hook( __FILE__, array( Install::class, 'activate' ) );

/**
 * Деактивация: чисти scheduled събития (без да трие данни).
 */
register_deactivation_hook( __FILE__, array( Install::class, 'deactivate' ) );

/**
 * Връща единствената инстанция на плъгина.
 */
function plugin(): Plugin {
	return Plugin::instance();
}

// Старт след зареждане на всички плъгини (за да е сигурно, че WooCommerce е наличен).
add_action( 'plugins_loaded', __NAMESPACE__ . '\\plugin', 11 );
