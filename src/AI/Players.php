<?php
declare(strict_types=1);

namespace Parliament\AI;

/**
 * Players by name: "<level>[/<style>][+net]", e.g. "medium", "hard/populist", "medium/monarchist+net".
 *
 * Levels set the search: easy (1 ply, loose), medium (2 plies), hard (3 plies).
 * Styles set the politics:
 *   whig        files a Writ when the search says it pays (the default)
 *   monarchist  never files; plays plain chess against the election layer
 *   populist    loves a coronation: Writs and crowned pawns are worth more to it
 */
final class Players
{
    public const LEVELS = [
        'easy' => ['depth' => 1, 'margin' => 60],
        'medium' => ['depth' => 2, 'margin' => 12],
        'hard' => ['depth' => 3, 'margin' => 0],
    ];
    public const STYLES = [
        'whig' => ['writValue' => 18, 'coronationBonus' => 0, 'files' => true],
        'monarchist' => ['writValue' => 0, 'coronationBonus' => 0, 'files' => false],
        'populist' => ['writValue' => 8, 'coronationBonus' => 90, 'files' => true],
    ];

    public static function make(string $spec, ?KnownSpace $book = null, ?int $seed = null): Search
    {
        // "+net2" makes the second-generation net the evaluation and its policy the move order;
        // "+net2:/path/net.json" names the weights (default $PC_NET2, then data/models/net2.json).
        $net2 = null;
        $policyOnly = false;
        if (preg_match('/\+net2policy(?::(\S+))?$/', $spec, $mm)) {
            $policyOnly = true;
            $spec = str_replace('+net2policy', '+net2', $spec);
        }
        if (preg_match('/\+net2(?::(\S+))?$/', $spec, $mm)) {
            $net2 = $mm[1] ?? (getenv('PC_NET2') ?: pc_root() . '/data/models/net2.json');
            $spec = substr($spec, 0, -strlen($mm[0]));
        }
        // "+net" blends the trained value net in at 35%; "+net:0.15" picks the blend.
        $netWeight = null;
        if (preg_match('/\+net(?::([0-9.]+))?$/', $spec, $mm)) {
            $netWeight = isset($mm[1]) ? (float)$mm[1] : 0.35;
            $spec = substr($spec, 0, -strlen($mm[0]));
        }
        $useNet = $netWeight !== null;
        [$level, $style] = array_pad(explode('/', $spec, 2), 2, 'whig');
        $lv = self::LEVELS[$level] ?? throw new \InvalidArgumentException("unknown level $level");
        $st = self::STYLES[$style] ?? throw new \InvalidArgumentException("unknown style $style");
        $ev = new Evaluator($st);
        if ($useNet) {
            $path = pc_root() . '/data/models/net.json';
            if (is_file($path)) {
                $ev->net = Net::load($path);
                $ev->netWeight = $netWeight;
            }
        }
        if ($net2 !== null) {
            static $cache = [];
            $ev->net2 = $cache[$net2] ??= Net2::load($net2);
            $ev->net2Eval = !$policyOnly;
        }
        return new Search($ev, $book, $lv['depth'], $lv['margin'], $seed);
    }

    /** The known-space book, or null where PHP has no SQLite (then players simply search). */
    public static function book(): ?KnownSpace
    {
        if (!class_exists(\PDO::class) || !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return null;
        }
        $path = pc_data() . '/book/known.sqlite';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $k = new KnownSpace($path);
        if ($k->size() === 0) {
            foreach (KnownSpace::theory() as $line) {
                $k->addTheory(explode(' ', $line));
            }
            $k->load();
        }
        return $k;
    }
}
