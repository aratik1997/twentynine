<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';
require_once __DIR__ . '/GameEngine.php';

/**
 * Computer players for 29. A bot seat is a normal game_players row with is_bot = 1. On its turn it
 * makes the same moves a person can make, through GameEngine, so every rule still applies.
 *
 * Difficulty only changes the decisions:
 *  - easy:   bids rarely and only with a strong hand; plays a random legal card.
 *  - medium: bids at the minimum when the hand looks worth it; leads its best card; wins tricks
 *            when it can and otherwise discards its lowest card.
 *  - hard:   bids in proportion to hand strength; leads from its longest suit; when a partner is
 *            winning, feeds the trick with points; never overtakes a winning partner needlessly.
 *
 * Bots never double, redouble, declare a single hand or reveal trump. Those are optional calls, and
 * leaving them out keeps the bot predictable.
 */
final class Bot
{
    /** Seconds a bot waits before its move, so it reads as a person thinking. */
    private const DELAY_SECONDS = ['easy' => 3, 'medium' => 2, 'hard' => 1];

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /** True once the bot's thinking time for the current turn has passed (plus a fixed 0-1s jitter). */
    public static function isDue(array $state, string $difficulty): bool
    {
        $start = (int)($state['turn_started_at'] ?? 0);
        $jitter = abs(crc32($start . ':' . $state['turn_seat'])) % 2;
        return time() - $start >= (self::DELAY_SECONDS[$difficulty] ?? 2) + $jitter;
    }

    /** Makes the bot's move for the current phase and returns the new state. */
    public static function act(array $game, array $state, int $seat, string $difficulty): array
    {
        switch ($state['phase']) {
            case 'bidding':
                return self::bid($game, $state, $seat, $difficulty);
            case 'trump_select':
                if ($state['trump_suit'] === null) {
                    return GameEngine::selectTrump($game, $state, $seat, self::bestSuit($state['hands'][$seat]));
                }
                return GameEngine::proceedToPlay($game, $state, $seat);
            case 'playing':
                return GameEngine::playCard($game, $state, $seat, self::chooseCard($game, $state, $seat, $difficulty));
        }
        return $state;
    }

    /** The suit with the most cards in $hand, ties broken by point value (same rule the engine's auto-trump uses). */
    public static function bestSuit(array $hand): string
    {
        $counts = ['S' => 0, 'H' => 0, 'D' => 0, 'C' => 0];
        $values = ['S' => 0, 'H' => 0, 'D' => 0, 'C' => 0];
        foreach ($hand as $card) {
            $s = Cards::suitOf($card);
            $counts[$s]++;
            $values[$s] += Cards::pointValue($card);
        }
        $best = 'S';
        foreach (Cards::SUITS as $s) {
            if ($counts[$s] > $counts[$best] || ($counts[$s] === $counts[$best] && $values[$s] > $values[$best])) {
                $best = $s;
            }
        }
        return $best;
    }

    /**
     * Rough strength of a hand as a points estimate, used for bidding. Trump-suit cards count fully
     * (plus one for the trick they can win); off-suit cards count for half their point value.
     */
    public static function estimate(array $hand): float
    {
        $trump = self::bestSuit($hand);
        $score = 0.0;
        foreach ($hand as $card) {
            $value = Cards::pointValue($card);
            $score += Cards::suitOf($card) === $trump ? $value + 1 : $value * 0.5;
        }
        return $score * 2; // the second batch of four cards is unseen, so scale up to a full hand
    }

    private static function bid(array $game, array $state, int $seat, string $difficulty): array
    {
        $settings = $game['settings'];
        $min = $state['highest_bid'] !== null ? $state['highest_bid'] + 1 : (int)$settings['min_bid'];
        $max = (int)$settings['max_bid'];
        $estimate = self::estimate($state['hands'][$seat]);

        if ($difficulty === 'easy') {
            return $estimate >= $min + 4 && $min <= $max
                ? GameEngine::placeBid($game, $state, $seat, $min)
                : GameEngine::passBid($game, $state, $seat);
        }
        if ($estimate < $min || $min > $max) {
            return GameEngine::passBid($game, $state, $seat);
        }
        $value = $difficulty === 'hard'
            ? max($min, min($max, (int)floor($estimate)))
            : $min;
        return GameEngine::placeBid($game, $state, $seat, $value);
    }

    private static function chooseCard(array $game, array $state, int $seat, string $difficulty): string
    {
        $legal = GameEngine::legalCards($state, $seat);
        if ($difficulty === 'easy') {
            return $legal[array_rand($legal)];
        }

        $trump = $state['trump_revealed'] ? $state['trump_suit'] : null;

        // Leading: the bot opens the trick.
        if (empty($state['table_cards'])) {
            if ($difficulty === 'hard') {
                $longest = self::bestSuit($legal);
                $inSuit = array_values(array_filter($legal, fn($c) => Cards::suitOf($c) === $longest));
                $pool = $inSuit ?: $legal;
            } else {
                $pool = $legal;
            }
            return self::maxBy($pool, fn($c) => Cards::rankStrength($c));
        }

        // Following: work out who is winning the trick right now, and who would win with each card.
        $leadSuit = Cards::suitOf($state['table_cards'][0]['card']);
        $currentWinnerSeat = (int)$state['table_cards'][Cards::winner($state['table_cards'], $leadSuit, $trump)]['seat'];
        $partnerIsWinning = $difficulty === 'hard'
            && GameEngine::teamOf($game, $seat) !== null
            && GameEngine::teamOf($game, $currentWinnerSeat) === GameEngine::teamOf($game, $seat)
            && $currentWinnerSeat !== $seat;

        if ($partnerIsWinning) {
            // Safe to feed points to a partner who is already winning.
            return self::maxBy($legal, fn($c) => Cards::pointValue($c) * 10 + Cards::rankStrength($c));
        }

        $winners = array_values(array_filter($legal, function ($card) use ($state, $seat, $leadSuit, $trump) {
            $table = array_merge($state['table_cards'], [['seat' => $seat, 'card' => $card]]);
            return (int)$table[Cards::winner($table, $leadSuit, $trump)]['seat'] === $seat;
        }));

        if ($winners) {
            // Win as cheaply as possible: fewest points, then the lowest card that still wins.
            return self::minBy($winners, fn($c) => Cards::pointValue($c) * 10 + Cards::rankStrength($c));
        }
        // Can't win the trick: dump the least valuable card.
        return self::minBy($legal, fn($c) => Cards::pointValue($c) * 10 + Cards::rankStrength($c));
    }

    private static function maxBy(array $items, callable $score)
    {
        $best = $items[0];
        foreach ($items as $item) {
            if ($score($item) > $score($best)) {
                $best = $item;
            }
        }
        return $best;
    }

    private static function minBy(array $items, callable $score)
    {
        $best = $items[0];
        foreach ($items as $item) {
            if ($score($item) < $score($best)) {
                $best = $item;
            }
        }
        return $best;
    }
}
