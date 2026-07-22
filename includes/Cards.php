<?php
declare(strict_types=1);

/**
 * Card representation: a 2-3 char string, suit letter + rank code.
 * Suits: S H D C   Ranks: 7 8 9 10 J Q K A
 * e.g. "S7", "H10", "DJ", "CA"
 */
final class Cards
{
    public const SUITS = ['S', 'H', 'D', 'C'];
    public const SUIT_NAMES = ['S' => 'Spades', 'H' => 'Hearts', 'D' => 'Diamonds', 'C' => 'Clubs'];
    public const SUIT_SYMBOLS = ['S' => '♠', 'H' => '♥', 'D' => '♦', 'C' => '♣'];
    public const RANKS = ['7', '8', '9', '10', 'J', 'Q', 'K', 'A'];

    // Higher number = stronger card. J-9-A-10-K-Q-8-7
    public const RANK_ORDER = [
        '7' => 0, '8' => 1, 'Q' => 2, 'K' => 3, '10' => 4, 'A' => 5, '9' => 6, 'J' => 7,
    ];

    // J=3, 9=2, A=1, 10=1, K/Q/8/7=0  (total 28 points in the deck)
    public const RANK_VALUE = [
        'J' => 3, '9' => 2, 'A' => 1, '10' => 1, 'K' => 0, 'Q' => 0, '8' => 0, '7' => 0,
    ];

    /** @return string[] a fresh, unshuffled 32-card deck */
    public static function fullDeck(): array
    {
        $deck = [];
        foreach (self::SUITS as $s) {
            foreach (self::RANKS as $r) {
                $deck[] = $s . $r;
            }
        }
        return $deck;
    }

    public static function suitOf(string $card): string
    {
        return $card[0];
    }

    public static function rankOf(string $card): string
    {
        return substr($card, 1);
    }

    public static function pointValue(string $card): int
    {
        return self::RANK_VALUE[self::rankOf($card)];
    }

    public static function rankStrength(string $card): int
    {
        return self::RANK_ORDER[self::rankOf($card)];
    }

    public static function label(string $card): string
    {
        $rank = self::rankOf($card);
        return $rank . self::SUIT_SYMBOLS[self::suitOf($card)];
    }

    /**
     * Compare two cards played to the same trick and return which wins.
     * $leadSuit is the suit that was led; $trump is the trump suit or
     * null if trump has not been revealed (in which case only the lead
     * suit can win — no card played can be "secretly" trump-winning).
     */
    public static function winner(array $trickCards, string $leadSuit, ?string $trump): int
    {
        // $trickCards: ordered list of ['seat'=>int,'card'=>string]
        $best = null;
        $bestIdx = 0;
        foreach ($trickCards as $i => $entry) {
            $card = $entry['card'];
            $suit = self::suitOf($card);
            $isTrump = $trump !== null && $suit === $trump;
            $isLead = $suit === $leadSuit;
            if (!$isTrump && !$isLead) {
                continue; // can't win, off-suit discard
            }
            if ($best === null) {
                $best = $entry;
                $bestIdx = $i;
                continue;
            }
            $bestSuit = self::suitOf($best['card']);
            $bestIsTrump = $trump !== null && $bestSuit === $trump;

            if ($isTrump && !$bestIsTrump) {
                $best = $entry;
                $bestIdx = $i;
            } elseif ($isTrump === $bestIsTrump) {
                if (self::rankStrength($card) > self::rankStrength($best['card'])) {
                    $best = $entry;
                    $bestIdx = $i;
                }
            }
        }
        return $bestIdx;
    }
}
