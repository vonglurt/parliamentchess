<?php
declare(strict_types=1);

namespace Parliament\Server;

/**
 * Player names. A bot is a nut, a legume or a squash, named at random by its common or its
 * Latin name; a person starts as a pumpkin or another large squash and may rename themself.
 */
final class Names
{
    /** Bots: [common name, Latin name]. 20 nuts, 22 legumes, 18 squash. */
    public const BOTS = [
        // Nuts
        ['Almond', 'Prunus dulcis'], ['English Walnut', 'Juglans regia'], ['Black Walnut', 'Juglans nigra'],
        ['Butternut', 'Juglans cinerea'], ['Pecan', 'Carya illinoinensis'], ['Shagbark Hickory', 'Carya ovata'],
        ['Hazelnut', 'Corylus avellana'], ['Cashew', 'Anacardium occidentale'], ['Pistachio', 'Pistacia vera'],
        ['Macadamia', 'Macadamia integrifolia'], ['Brazil Nut', 'Bertholletia excelsa'], ['Sweet Chestnut', 'Castanea sativa'],
        ['Pine Nut', 'Pinus pinea'], ['Beechnut', 'Fagus sylvatica'], ['Pili Nut', 'Canarium ovatum'],
        ['Kola Nut', 'Cola acuminata'], ['Candlenut', 'Aleurites moluccanus'], ['Ginkgo Nut', 'Ginkgo biloba'],
        ['Coconut', 'Cocos nucifera'], ['Monkey Puzzle Nut', 'Araucaria araucana'],
        // Legumes
        ['Chickpea', 'Cicer arietinum'], ['Lentil', 'Lens culinaris'], ['Garden Pea', 'Pisum sativum'],
        ['Fava Bean', 'Vicia faba'], ['Soybean', 'Glycine max'], ['Peanut', 'Arachis hypogaea'],
        ['Mung Bean', 'Vigna radiata'], ['Adzuki Bean', 'Vigna angularis'], ['Black-eyed Pea', 'Vigna unguiculata'],
        ['Kidney Bean', 'Phaseolus vulgaris'], ['Lima Bean', 'Phaseolus lunatus'], ['Runner Bean', 'Phaseolus coccineus'],
        ['Tepary Bean', 'Phaseolus acutifolius'], ['Pigeon Pea', 'Cajanus cajan'], ['White Lupin', 'Lupinus albus'],
        ['Grass Pea', 'Lathyrus sativus'], ['Tamarind', 'Tamarindus indica'], ['Carob', 'Ceratonia siliqua'],
        ['Fenugreek', 'Trigonella foenum-graecum'], ['Winged Bean', 'Psophocarpus tetragonolobus'],
        ['Hyacinth Bean', 'Lablab purpureus'], ['Bambara Groundnut', 'Vigna subterranea'],
        // Squash
        ['Butternut Squash', 'Cucurbita moschata'], ['Acorn Squash', "Cucurbita pepo 'Table Queen'"], ['Zucchini', 'Cucurbita pepo'],
        ['Blue Hubbard', "Cucurbita maxima 'Blue Hubbard'"], ['Kabocha', "Cucurbita maxima 'Kabocha'"],
        ['Spaghetti Squash', "Cucurbita pepo 'Vegetable Spaghetti'"], ['Delicata', "Cucurbita pepo 'Delicata'"],
        ['Yellow Crookneck', "Cucurbita pepo 'Crookneck'"], ['Pattypan', "Cucurbita pepo 'Pattypan'"],
        ['Turban Squash', "Cucurbita maxima 'Turk's Turban'"], ['Buttercup Squash', "Cucurbita maxima 'Buttercup'"],
        ['Cushaw', 'Cucurbita argyrosperma'], ['Fig-leaf Gourd', 'Cucurbita ficifolia'],
        ['Banana Squash', "Cucurbita maxima 'Pink Banana'"], ['Tromboncino', "Cucurbita moschata 'Tromboncino'"],
        ['Calabaza', "Cucurbita moschata 'Calabaza'"], ['Buffalo Gourd', 'Cucurbita foetidissima'],
        ['Seminole Pumpkin', "Cucurbita moschata 'Seminole'"],
    ];

    /** People start as one of these: pumpkins and other large squash. */
    public const PEOPLE = [
        'Atlantic Giant', 'Big Max', 'Prizewinner', 'Big Moose', 'Mammoth Gold', 'Connecticut Field', 'Howden',
        'Jack-o\'-Lantern', 'Rouge Vif d\'Etampes', 'Jarrahdale', 'Musquee de Provence', 'Hundredweight',
    ];

    /** A bot's name: a random entry, by its common or its Latin name; never one already in $taken. */
    public static function bot(array $taken = []): string
    {
        for ($i = 0; $i < 50; $i++) {
            [$common, $latin] = self::BOTS[random_int(0, count(self::BOTS) - 1)];
            $name = random_int(0, 1) ? $common : $latin;
            if (!in_array($name, $taken, true)) {
                return $name;
            }
        }
        return self::BOTS[0][0];
    }

    public static function person(): string
    {
        return self::PEOPLE[random_int(0, count(self::PEOPLE) - 1)];
    }

    /** A name as typed: printable, single-spaced, 1 to 40 characters; null if nothing is left. */
    public static function clean(?string $name): ?string
    {
        $name = preg_replace('/[^\p{L}\p{N} .,\'\-()]/u', '', (string)$name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return null;
        }
        return mb_substr($name, 0, 40);
    }

    public static function isBotName(string $name): bool
    {
        foreach (self::BOTS as [$common, $latin]) {
            if ($name === $common || $name === $latin) {
                return true;
            }
        }
        return false;
    }
}
