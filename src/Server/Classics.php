<?php
declare(strict_types=1);

namespace Parliament\Server;

/**
 * Famous short games of chess history. Every one is legal Parliament Chess too: no Writ can be filed
 * before move 10, and a Writ is never forced, so every miniature survives move for move. The lobby's
 * "The classics" replays one with the champion net reading both sides (mode "classic"), and
 * tools/classics.php measures where the net ranks each historic move and what it would play instead.
 */
final class Classics
{
    public const GAMES = [
        'fool' => [
            'title' => "Fool's Mate", 'white' => 'Fool', 'black' => 'Anyone', 'event' => 'the shortest mate', 'year' => null,
            'moves' => 'f3 e5 g4 Qh4#',
            'note' => 'Two pawn moves open the diagonal to the white King; the Queen mates on the second move.',
        ],
        'scholar' => [
            'title' => "Scholar's Mate", 'white' => 'Scholar', 'black' => 'Beginner', 'event' => 'the classroom trap', 'year' => null,
            'moves' => 'e4 e5 Bc4 Nc6 Qh5 Nf6 Qxf7#',
            'note' => 'Queen and Bishop aim at f7, the square only the King defends; 3...Nf6 attacks the Queen and lets it mate.',
        ],
        'legal' => [
            'title' => "Légal's Mate", 'white' => 'Légal de Kermeur', 'black' => 'Saint Brie', 'event' => 'Paris', 'year' => 1750,
            'moves' => 'e4 e5 Bc4 d6 Nf3 Bg4 Nc3 g6 Nxe5 Bxd1 Bxf7+ Ke7 Nd5#',
            'note' => 'White gives the Queen with 5.Nxe5!; Black takes it, and three minor pieces mate.',
        ],
        'reti' => [
            'title' => 'Réti–Tartakower', 'white' => 'Richard Réti', 'black' => 'Savielly Tartakower', 'event' => 'Vienna', 'year' => 1910,
            'moves' => 'e4 c6 d4 d5 Nc3 dxe4 Nxe4 Nf6 Qd3 e5 dxe5 Qa5+ Bd2 Qxe5 O-O-O Nxe4 Qd8+ Kxd8 Bg5+ Kc7 Bd8#',
            'note' => 'A Queen sacrifice on d8, a double check, and a Bishop mates from the square the Queen died on.',
        ],
        'opera' => [
            'title' => 'The Opera Game', 'white' => 'Paul Morphy', 'black' => 'Duke Karl and Count Isouard', 'event' => 'Paris Opera', 'year' => 1858,
            'moves' => 'e4 e5 Nf3 d6 d4 Bg4 dxe5 Bxf3 Qxf3 dxe5 Bc4 Nf6 Qb3 Qe7 Nc3 c6 Bg5 b5 Nxb5 cxb5 Bxb5+ Nbd7 '
                . 'O-O-O Rd8 Rxd7 Rxd7 Rd1 Qe6 Bxd7+ Nxd7 Qb8+ Nxb8 Rd8#',
            'note' => 'Development, open lines, a pin: Morphy gives a Knight, a Rook and the Queen, and mates with the last Rook.',
        ],
        'lasker' => [
            'title' => 'Lasker–Thomas', 'white' => 'Edward Lasker', 'black' => 'George Alan Thomas', 'event' => 'London', 'year' => 1912,
            'moves' => 'd4 e6 Nf3 f5 Nc3 Nf6 Bg5 Be7 Bxf6 Bxf6 e4 fxe4 Nxe4 b6 Ne5 O-O Bd3 Bb7 Qh5 Qe7 Qxh7+ Kxh7 '
                . 'Nxf6+ Kh6 Neg4+ Kg5 h4+ Kf4 g3+ Kf3 Be2+ Kg2 Rh2+ Kg1 Kd2#',
            'note' => 'The Queen is given on h7 and the black King is driven from h8 to g1, where a King move mates it.',
        ],
    ];

    /** The games, as the lobby lists them. */
    public static function all(): array
    {
        $out = [];
        foreach (self::GAMES as $key => $g) {
            $out[] = ['key' => $key, 'plies' => count(self::moves($key))] + array_diff_key($g, ['moves' => 1]);
        }
        return $out;
    }

    public static function get(string $key): ?array
    {
        return isset(self::GAMES[$key]) ? ['key' => $key, 'moves' => self::moves($key)] + self::GAMES[$key] : null;
    }

    /** @return list<string> the game's moves in SAN */
    public static function moves(string $key): array
    {
        return preg_split('/\s+/', trim(self::GAMES[$key]['moves']));
    }
}
