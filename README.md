# 🃏 29 — The Card Game

A real-time, multiplayer web implementation of **29 (Twenty-Nine)**, the classic South Asian trick-taking card game — played traditionally by 4 players in fixed partnerships, plus a configurable 3-player variant. Built with plain **HTML, CSS, JavaScript, PHP and MySQL** — no frameworks, no build step.

![players](https://img.shields.io/badge/players-3%20or%204-d4af37) ![stack](https://img.shields.io/badge/stack-PHP%20%2B%20MySQL%20%2B%20JS-145c39) ![license](https://img.shields.io/badge/license-MIT-blue)

## Features

- **Traditional 4-player 29**: fixed partnerships (seats 1↔3, 2↔4), 32-card deck, J-9-A-10-K-Q-8-7 ranking, secret trump with automatic reveal-on-play, full bidding (16–28, configurable), 8-trick play, marks or numeric scoring.
- **3-player variant**: clearly labelled as a configurable house variant (no official 3-player rule set exists) — 24-card deck, 8 cards each, individual ranking by points collected.
- **House rules**: Double / Re-Double, Auto Trump, Single Hand, configurable min/max bid, scoring mode, scoreboard sort order, round/marks limits, last-trick bonus point.
- **Lobby system**: shareable 6-character table codes, host controls, kick/leave, mid-game join = spectate until the round ends then take the next open seat.
- **Live table**: polling-based real-time sync, legal-move enforcement, hidden hands, secret trump until revealed, animated dealing/playing/trick-collection, synthesized sound effects (Web Audio — no audio files needed), in-table chat.
- **Automatic rotation**: dealer rotates clockwise, seats reshuffle every round, departed players are auto-passed/auto-played so the table never stalls.
- **Rules reference**: in-app rules page/modal explaining every rule this implementation enforces.

## Tech Stack

| Layer | Tech |
|---|---|
| Server | PHP 8+ (PDO/MySQL, no framework) |
| Database | MySQL / MariaDB |
| Client | Vanilla JavaScript, HTML, CSS |
| Animation | [GSAP](https://gsap.com/) (CDN) + CSS keyframes |
| Sound | Web Audio API (synthesized — zero audio assets) |
| Realtime | Client-side polling against a JSON API (no WebSocket server required) |

## Getting Started (XAMPP)

1. Copy this folder into your `htdocs` directory (e.g. `C:\xampp\htdocs\twentynine`).
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Create a database (e.g. `twentynine`, `utf8mb4`/`utf8mb4_unicode_ci`), select it, then import `sql/schema.sql` into it via phpMyAdmin's Import tab (or `mysql <dbname> < sql/schema.sql` on the CLI). The file doesn't create or select a database itself, since hosted environments provision their own fixed DB name.
4. Set your DB credentials in `config/config.local.php` (create it — it's gitignored, see `config/config.php` for the constants it should `define()`), or edit the defaults directly in `config/config.php` for local-only use.
5. Visit `http://localhost/twentynine/` in your browser.
6. Open the same URL in more browser tabs/devices (or share your LAN IP) to seat additional players — enter a name, then **Create a Table** or **Join** with the 6-character code.

No `composer install` or `npm install` needed — everything ships ready to run.

## Project Structure

```
twentynine/
├── api/                 JSON endpoints (bid, play_card, state, chat, kick, ...)
├── assets/
│   ├── css/style.css    All styling: landing, lobby, game table, animations
│   ├── js/               api.js, sound.js, lobby.js, game.js
│   └── img/              logo.svg + generated favicons
├── config/               config.php (DB creds), db.php (PDO connection)
├── includes/             GameEngine.php (rules), Cards.php, GameContext.php
│                         (persistence), Autoplay.php (handles departed players)
├── sql/schema.sql        Full MySQL schema
├── tools/                one-off asset generation scripts
├── index.php             Landing page (nickname, create/join table)
├── lobby.php             Waiting room
├── game.php              Live game table
└── rules.php             Full rules reference (also used as an in-game modal)
```

## How the Realtime Layer Works

Rather than requiring a persistent WebSocket process (which XAMPP/Apache doesn't run out of the box), the client polls `api/state.php` roughly every 1.2s. All game mutations run inside a MySQL transaction with `SELECT ... FOR UPDATE`, so concurrent actions from different players resolve safely without race conditions. If a seated player leaves or is kicked mid-round, the next poll (or action) automatically auto-passes/auto-plays on their behalf so the round always reaches completion.

## Scoring Notes

- **Marks mode** follows the traditional rule: only the bidding side's mark total changes each round (+1 on success, −1 on failure, doubled/redoubled), first to the configured target (default ±6) wins the match.
- **Numeric mode** is this app's simpler alternative: the bidding side gains/loses the bid value each round.
- The scoreboard's sort direction defaults to *highest score wins* (the standard convention for 29) but is configurable per table.

Full rule details, including the 3-player variant's specific (non-official) house rules, are in [`rules.php`](rules.php) — also reachable from the 📖 Rules button in-game.

## Testing

Two end-to-end smoke scripts drive the live API over HTTP (via cURL, simulating multiple browser sessions with separate cookie jars) to exercise the full game loop without a browser:

```
php tools/smoke_test.php   # create → join → start → bid → trump → play 8 tricks → next round → kick
php tools/smoke_test2.php  # cancel_round redeal, and leaving mid-trick (autoplay takes over)
```

Requires Apache + MySQL running and the schema imported. They create and clean up their own throwaway tables/players.

## Credits

Rules cross-referenced against [pagat.com's Twenty-Nine reference](https://www.pagat.com/jass/29.html). Card game logo/favicon generated for this project. Animation via [GSAP](https://gsap.com/) (free tier, loaded from CDN).

## License

MIT — see [LICENSE](LICENSE).
