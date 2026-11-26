<?php
declare(strict_types=1);

/*
 * The known space (data/book/known.sqlite): opening theory plus every learned game.
 *
 *   php bin/book.php stats
 *   php bin/book.php learn 'orrery/census/results/*\/*.json' [...]   learn finished recordings (first 30 plies each)
 *   php bin/book.php theory                                          (re)load the opening lines
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\KnownSpace;
use Parliament\AI\Players;
use Parliament\Engine\Recording;

$verb = $argv[1] ?? 'stats';
$book = Players::book();
if ($verb === 'theory') {
    foreach (KnownSpace::theory() as $name => $line) {
        $n = $book->addTheory(explode(' ', $line));
        printf("%-26s %2d plies\n", $name, $n);
    }
} elseif ($verb === 'learn') {
    $games = $plies = 0;
    foreach (array_slice($argv, 2) as $pattern) {
        foreach (glob($pattern) ?: [] as $f) {
            if (str_ends_with($f, 'summary.json')) {
                continue;
            }
            $g = Recording::load($f);
            $plies += $book->learn($g);
            $games++;
        }
    }
    echo "learned $games games, $plies positions\n";
}
echo json_encode($book->stats()) . "\n"; // read by key: nothing is held in memory to count
