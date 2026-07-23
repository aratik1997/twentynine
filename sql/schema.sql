-- =====================================================================
-- 29 (Twenty-Nine) Card Game - Database Schema
-- Traditional 4-player partnership game + configurable 3-player variant
--
-- This file does NOT create or select a database — shared hosting (e.g.
-- StackCP) pre-provisions one with a fixed name you can't choose, and
-- won't grant CREATE DATABASE privileges. Select (or create) your target
-- database first, then run this file against it:
--   - Local/XAMPP: CREATE DATABASE twentynine CHARACTER SET utf8mb4
--       COLLATE utf8mb4_unicode_ci; then USE twentynine; then run this file.
--   - phpMyAdmin (local or hosted): open your database, use the "Import"
--       tab, and import this file directly — it runs in that DB's context.
-- =====================================================================

-- ---------------------------------------------------------------------
-- players: a lightweight guest identity, persisted client-side via a
-- long-lived cookie (player_uuid). No password/auth required to keep
-- "jump in and play" friction low.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS players (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  nickname      VARCHAR(24) NOT NULL,
  avatar_color  VARCHAR(7) NOT NULL DEFAULT '#4a7c59',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- games: a lobby/table/room
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS games (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            CHAR(6) NOT NULL UNIQUE,
  name            VARCHAR(40) NOT NULL DEFAULT 'Table',
  variant         ENUM('4p','3p') NOT NULL DEFAULT '4p',
  host_player_id  INT UNSIGNED NOT NULL,
  status          ENUM('lobby','bidding','trump_select','playing','round_end','finished','cancelled')
                    NOT NULL DEFAULT 'lobby',
  settings_json   JSON NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_games_host FOREIGN KEY (host_player_id) REFERENCES players(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- game_players: seat membership for a game, tracks status so we can
-- support kick / leave / spectate-until-round-ends / reconnection.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_players (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id       INT UNSIGNED NOT NULL,
  player_id     INT UNSIGNED NOT NULL,
  seat          TINYINT NULL,                     -- 0..3 (4p) or 0..2 (3p); NULL while spectating
  team          TINYINT NULL,                      -- 0 or 1 for 4p partnerships; NULL for 3p
  is_host       TINYINT(1) NOT NULL DEFAULT 0,
  role          ENUM('player','spectator') NOT NULL DEFAULT 'spectator',
  status        ENUM('active','left','kicked') NOT NULL DEFAULT 'active',
  is_bot        TINYINT(1) NOT NULL DEFAULT 0,     -- filled by host to seat an AI player
  score         INT NOT NULL DEFAULT 0,            -- cumulative score (marks or numeric)
  joined_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_game_player (game_id, player_id),
  CONSTRAINT fk_gp_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  CONSTRAINT fk_gp_player FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- game_state: single-row-per-game live engine state (JSON documents).
-- Optimistic-concurrency `version` column guards against two players'
-- actions racing on the same round.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_state (
  game_id           INT UNSIGNED NOT NULL PRIMARY KEY,
  version           INT UNSIGNED NOT NULL DEFAULT 0,
  round_no          INT UNSIGNED NOT NULL DEFAULT 0,
  dealer_seat       TINYINT NULL,
  turn_seat         TINYINT NULL,
  turn_started_at   INT UNSIGNED NULL,  -- unix timestamp turn_seat last changed (drives the 30s turn timer)
  phase             ENUM('waiting','dealing','bidding','trump_select','playing','round_end','finished')
                      NOT NULL DEFAULT 'waiting',
  bid_history_json  JSON NULL,       -- [{seat, action:'bid'|'pass', value}]
  highest_bid       TINYINT UNSIGNED NULL,
  bidder_seat       TINYINT NULL,
  doubled_by        TINYINT NULL,    -- seat that called double, if any
  redoubled_by      TINYINT NULL,    -- seat that called redouble, if any
  trump_suit        ENUM('S','H','D','C') NULL,
  trump_revealed    TINYINT(1) NOT NULL DEFAULT 0,
  hands_json        JSON NULL,       -- {seat: [cards]}
  deck_json         JSON NULL,       -- undealt cards awaiting the second 4-card deal
  table_cards_json  JSON NULL,       -- current trick: [{seat, card}]
  trick_no          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  trick_lead_seat   TINYINT NULL,
  trick_history_json JSON NULL,      -- completed tricks this round
  points_json       JSON NULL,       -- per-seat or per-team card points this round
  seat_order_json   JSON NULL,       -- active seat -> player_id mapping (for reshuffle/skip on leave)
  marriage_seat     TINYINT NULL,    -- seat holding King+Queen of trump at reveal time, if any
  marriage_status   ENUM('none','half','full') NOT NULL DEFAULT 'none',
  last_result_json  JSON NULL,       -- summary of the last completed round
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_state_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- round_history: append-only ledger of completed rounds for the
-- scoreboard / post-game review.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS round_history (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id       INT UNSIGNED NOT NULL,
  round_no      INT UNSIGNED NOT NULL,
  dealer_seat   TINYINT NOT NULL,
  bidder_seat   TINYINT NULL,
  bid_value     TINYINT UNSIGNED NULL,
  trump_suit    ENUM('S','H','D','C') NULL,
  doubled       TINYINT(1) NOT NULL DEFAULT 0,
  redoubled     TINYINT(1) NOT NULL DEFAULT 0,
  result        ENUM('success','fail','no_bid') NOT NULL,
  points_json   JSON NOT NULL,       -- points collected per seat/team
  score_delta_json JSON NOT NULL,    -- score change per seat this round
  scores_after_json JSON NOT NULL,   -- cumulative scores after this round
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rh_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- chat_messages: table talk, plus system notices (kicks, leaves, etc.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_messages (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id     INT UNSIGNED NOT NULL,
  player_id   INT UNSIGNED NULL,     -- NULL for system messages
  nickname    VARCHAR(24) NOT NULL,
  message     VARCHAR(500) NOT NULL,
  is_system   TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_chat_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_chat_game_time ON chat_messages(game_id, id);
CREATE INDEX idx_gp_game ON game_players(game_id, status);
