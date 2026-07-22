<?php
/**
 * Central configuration. Copy this file's values into your own
 * environment before deploying — defaults match a stock XAMPP install.
 */

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'twentynine';
const DB_USER = 'root';
const DB_PASS = '';

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
