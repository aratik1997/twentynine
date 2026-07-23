<?php
/**
 * Central configuration. Defaults below match a stock XAMPP install.
 * For real credentials (remote hosting, etc.), create config/config.local.php
 * (gitignored) defining the DB_* constants via define() — it's loaded first
 * and takes priority, so the tracked defaults here never see real secrets.
 */

declare(strict_types=1);

$__localConfig = __DIR__ . '/config.local.php';
if (file_exists($__localConfig)) {
    require_once $__localConfig;
}

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', 3306);
if (!defined('DB_NAME')) define('DB_NAME', 'twentynine');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// Poll cadence hint shared with the client (ms). Kept in one place so
// server-side rate limiting and client polling stay in sync.
const POLL_INTERVAL_MS = 1200;

// Each player gets this many seconds to act on their turn (bid / select
// trump / play a card) before the server auto-resolves it for them.
const TURN_TIMEOUT_SECONDS = 30;

// How long (seconds) a player can be unseen before we consider them
// disconnected for presence purposes (does not auto-kick).
const PRESENCE_TIMEOUT_SECONDS = 30;

const PLAYER_COOKIE = 'tn_player_uuid';
const COOKIE_LIFETIME_DAYS = 90;

date_default_timezone_set('UTC');
