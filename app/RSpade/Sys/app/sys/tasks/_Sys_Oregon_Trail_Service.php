<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Tasks;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * _Sys_Oregon_Trail_Service - the task system's sample task: a scripted, three-minute game of
 * the Oregon Trail (with a few additions the original never had), started from the Tasks
 * screen's "Play the sample task" button.
 *
 * It exists to SHOW what a task can report, so it reports everything a task like it would -
 * with ONE progress indicator, as every task should (rsx:man tasks, DESIGNING A TASK'S
 * REPORTING):
 *
 *   status()          where the party is right now (each change is also a stderr line)
 *   progress_count()  landmarks reached of 14 (the percentage is derived from it; the miles
 *                     travelled are in the state)
 *   eta()             seconds left in the script
 *   heartbeat()       every beat
 *   state()           the wagon: date, miles, weather, pace, rations, provisions, the party
 *   state_list()      the landmarks still ahead
 *   message()         a landmark reached, a death, each strange encounter
 *   stdout()          the narrative, text-adventure style ("> " marks a decision)
 *   stderr()          hazards, losses, illness and deaths
 *   attach_bytes()    trail_journal.txt (the whole story) and epitaphs.txt - files for the
 *                     developer who started the run, the one shape attachments are for
 *   summary()         how the journey ended
 *
 * and it answers a GRACEFUL STOP: before every beat, and twice a second while a beat plays
 * out, it asks is_stop_requested(); when the answer is yes the party makes camp where it
 * stands, the journal so far is attached, and the method returns null - the run settles
 * STOPPED. (A force stop or force kill is not answered by the task: its worker is killed.)
 *
 * Everything is scripted (BEATS) - no randomness - so it plays the same way every time.
 * The script is docs.dev/task_system_overhaul_2026_10_07/oregon_trail_sample_narrative.md.
 */
class _Sys_Oregon_Trail_Service extends Rsx_Service_Abstract
{
    /** Miles from Independence, Missouri to the Willamette Valley. */
    const TRAIL_MILES = 2040;

    /** Every landmark, in order. */
    const LANDMARKS = [
        'Independence, Missouri', 'Kansas River Crossing', 'Fort Kearney', 'Chimney Rock',
        'Fort Laramie', 'Independence Rock', 'South Pass', 'Fort Bridger', 'Soda Springs',
        'Fort Hall', 'Snake River Crossing', 'Fort Boise', 'The Blue Mountains',
        'Willamette Valley, Oregon',
    ];

    /** How often a beat's pause checks for a stop request, in seconds. */
    const STOP_CHECK_SECONDS = 0.5;

    /**
     * The script. Each beat: when, where, how far (cumulative miles), how long it plays
     * (seconds), the landmark it reaches (if any), its narrative (stdout), its hazards
     * (stderr), its message, and what it does to the wagon (provisions deltas, health,
     * a death, pace / rations / weather changes).
     */
    const BEATS = [
        [
            'date' => 'March 1, 1848', 'status' => 'Outfitting in Independence, Missouri', 'miles' => 0, 'seconds' => 8,
            'landmark' => 'Independence, Missouri',
            'out' => [
                'THE OREGON TRAIL',
                '================',
                'It is 1848. Two thousand and forty miles of prairie, mountain and river lie',
                'between Independence, Missouri and the Willamette Valley.',
                '',
                'Your party: Ezekiel (a banker from Boston), his wife Martha (who never goes',
                'anywhere without her cast-iron skillet), her brother Cornelius, and their',
                'cousin Prudence, who plays the fiddle.',
                '',
                "Matt's General Store sells you 6 oxen, 1,000 lbs of food, 12 sets of clothing,",
                '400 rounds of ammunition and spare wheels, axles and tongues. $250 left.',
                '> Choose your pace: steady.',
                '> Choose your rations: filling.',
                'The wagon creaks west.',
            ],
            'message' => 'The party sets out from Independence, Missouri.',
        ],
        [
            'date' => 'March 12, 1848', 'status' => 'Rolling across the prairie', 'miles' => 102, 'seconds' => 6,
            'out' => [
                'Tall grass. Wildflowers. A sky the size of an argument.',
                'Prudence plays the fiddle by the fire. The oxen seem to approve.',
            ],
            'wagon' => ['food' => -40, 'weather' => 'warm'],
        ],
        [
            'date' => 'March 18, 1848', 'status' => 'Crossing the Kansas River', 'miles' => 185, 'seconds' => 8,
            'landmark' => 'Kansas River Crossing',
            'out' => [
                'You come to the Kansas River. It is 620 feet across and 4.2 feet deep.',
                '> Ford it, caulk the wagon and float it, or take a ferry? Caulk and float.',
                'The wagon bobs across like a very anxious duck.',
            ],
            'err' => ['The current takes a set of clothing. It is last seen heading for St. Louis.'],
            'wagon' => ['clothing' => -1, 'food' => -20],
        ],
        [
            'date' => 'April 2, 1848', 'status' => 'Hunting on the prairie', 'miles' => 268, 'seconds' => 7,
            'out' => [
                '> Hunt for food? Yes.',
                'Ezekiel stalks into the grass with the rifle and the confidence of a man who',
                'has read about hunting.',
                'He bags a buffalo. 1,200 pounds of meat - he can carry back 100.',
                'The rest is left to the prairie. The trail is cruel. So is arithmetic.',
            ],
            'wagon' => ['food' => 100, 'ammunition' => -15],
        ],
        [
            'date' => 'April 14, 1848', 'status' => 'Resting at Fort Kearney', 'miles' => 319, 'seconds' => 6,
            'landmark' => 'Fort Kearney',
            'out' => [
                'Fort Kearney: a cluster of sod buildings and a flag with opinions.',
                '> Trade? 50 rounds of ammunition for a spare wagon wheel. Deal.',
            ],
            'wagon' => ['ammunition' => -50, 'wheels' => 1, 'food' => -30],
        ],
        [
            'date' => 'April 21, 1848', 'status' => 'Sharing a campfire with strangers', 'miles' => 380, 'seconds' => 8,
            'out' => [
                'A painted wagon rolls up, covered in swirls and a hand-lettered sign:',
                '"THE MAGIC OX BUS - PEACE, LOVE AND OATS".',
                'Its passengers wear flowers in their hair and offer you "good vibes".',
                '> Trade? 10 lbs of food for one tie-dyed shirt. Martha sighs. Deal.',
                'Prudence jams with them until midnight. Everyone feels groovy.',
                '"Far out," says Cornelius, who does not know what that means.',
            ],
            'message' => 'Encounter: a band of wandering hippies. Good vibes acquired.',
            'wagon' => ['food' => -10, 'clothing' => 1, 'morale' => 'groovy'],
        ],
        [
            'date' => 'May 5, 1848', 'status' => 'Admiring Chimney Rock', 'miles' => 554, 'seconds' => 7,
            'landmark' => 'Chimney Rock',
            'out' => [
                'Chimney Rock rises from the plain like a very tall exclamation point.',
                'Cornelius drinks deeply from a green, still pond. "It\'s fine," he says.',
                'It is not fine.',
            ],
            'wagon' => ['food' => -50],
        ],
        [
            'date' => 'May 9, 1848', 'status' => 'Negotiating with the Knights Who Say "Ni!"', 'miles' => 590, 'seconds' => 9,
            'out' => [
                'Tall figures in helmets block the trail. "We are the Knights Who Say... NI!"',
                '"Bring us... a SHRUBBERY!"',
                'Martha produces a small sagebrush in a tin can. The knights confer.',
                '"It is a good shrubbery. I like the laurels particularly."',
                '"Now... bring us ANOTHER shrubbery!" one begins - but Ezekiel says "it",',
                'and the knights collapse, clutching their ears. The party rolls on.',
            ],
            'message' => 'Encounter: the Knights Who Say "Ni!" One shrubbery delivered.',
            'wagon' => ['food' => -10],
        ],
        [
            'date' => 'May 24, 1848', 'status' => 'Resting at Fort Laramie', 'miles' => 640, 'seconds' => 6,
            'landmark' => 'Fort Laramie',
            'out' => [
                'Fort Laramie: real walls, real coffee, and a blacksmith who whistles.',
            ],
            'err' => ['Cornelius feels unwell.'],
            'wagon' => ['food' => -40, 'health' => ['Cornelius' => 'poor']],
        ],
        [
            'date' => 'June 2, 1848', 'status' => 'Tending to Cornelius', 'miles' => 700, 'seconds' => 6,
            'out' => [
                '> Change rations? Meager - stretch the food, the road is long.',
                'Martha brews willow-bark tea. Cornelius insists the pond was fine.',
            ],
            'err' => ['Cornelius has dysentery.'],
            'wagon' => ['rations' => 'meager', 'food' => -30, 'health' => ['Cornelius' => 'very poor']],
        ],
        [
            'date' => 'June 8, 1848', 'status' => 'Burying Cornelius', 'miles' => 731, 'seconds' => 8,
            'out' => [
                'The wagon stops on a low rise above the river.',
                'A cairn of stones. A wooden marker. Prudence plays something slow.',
                'HERE LIES CORNELIUS. HE SAID THE WATER WAS FINE.',
            ],
            'err' => ['Cornelius has died of dysentery.'],
            'message' => 'Cornelius has died of dysentery.',
            'wagon' => ['dead' => 'Cornelius', 'epitaph' => 'Here lies Cornelius. He said the water was fine.'],
        ],
        [
            'date' => 'July 4, 1848', 'status' => 'Celebrating at Independence Rock', 'miles' => 830, 'seconds' => 6,
            'landmark' => 'Independence Rock',
            'out' => [
                'Independence Rock, on Independence Day. You carve your names into the granite',
                'beside a thousand others. Martha carves "CORNELIUS" too.',
            ],
            'wagon' => ['food' => -40, 'weather' => 'hot'],
        ],
        [
            'date' => 'July 9, 1848', 'status' => 'Outrunning the undead', 'miles' => 871, 'seconds' => 8,
            'out' => [
                'At dusk a line of shapes shambles across the trail, moaning about brains.',
                'ZOMBIES. Real ones. Gray, slow, and very sincere about it.',
                '> Run, fight, or hide? Run - at a steady pace, which beats a shamble.',
                'One zombie stops at a fresh grave, reads the marker, and seems genuinely moved.',
            ],
            'err' => ['The undead are on the trail. The oxen are deeply unhappy about it.'],
            'message' => 'Encounter: zombies. The party outpaces them.',
            'wagon' => ['food' => -20],
        ],
        [
            'date' => 'July 20, 1848', 'status' => 'Crossing the Continental Divide at South Pass', 'miles' => 932, 'seconds' => 6,
            'landmark' => 'South Pass',
            'out' => [
                'South Pass: the Continental Divide. From here, every river runs to the Pacific.',
                'It is less dramatic than expected. It is a very wide, very windy field.',
            ],
            'wagon' => ['food' => -40, 'weather' => 'windy'],
        ],
        [
            'date' => 'July 23, 1848', 'status' => 'Dueling a robot ninja cyborg', 'miles' => 951, 'seconds' => 9,
            'out' => [
                'Something drops from a juniper tree and lands in a crouch. A red visor glows.',
                'It is a ROBOT NINJA CYBORG. "BRING ME YOUR STRONGEST WARRIOR," it says.',
                'Ezekiel fires twenty rounds. They bounce off. Martha steps forward with the skillet.',
                'CLANG.',
                'The cyborg reboots into "friendly mode", apologizes for the inconvenience, and',
                'leaves behind a laser katana. It is excellent for cutting firewood.',
            ],
            'err' => ['20 rounds of ammunition wasted on a robot. The skillet did the work.'],
            'message' => 'Encounter: a robot ninja cyborg, defeated by a cast-iron skillet.',
            'wagon' => ['ammunition' => -20, 'items' => ['laser katana']],
        ],
        [
            'date' => 'August 3, 1848', 'status' => 'Trading at Fort Bridger', 'miles' => 989, 'seconds' => 6,
            'landmark' => 'Fort Bridger',
            'out' => [
                '> Trade? The laser katana for 2 oxen and 100 lbs of food.',
                'The trader is unimpressed by the katana. He is very impressed by the skillet.',
                'The skillet is not for sale.',
            ],
            'wagon' => ['oxen' => 2, 'food' => 100, 'items_lost' => ['laser katana']],
        ],
        [
            'date' => 'August 10, 1848', 'status' => 'Resting at Soda Springs', 'miles' => 1151, 'seconds' => 6,
            'landmark' => 'Soda Springs',
            'out' => [
                'The springs fizz like sarsaparilla. Martha does not let anyone drink from them.',
            ],
            'wagon' => ['food' => -50],
        ],
        [
            'date' => 'August 12, 1848', 'status' => 'Waiting for Ezekiel to come back down', 'miles' => 1175, 'seconds' => 9,
            'out' => [
                'In the night, a low hum. A ring of lights hangs over the wagon.',
                'A beam of white light lifts Ezekiel, still in his nightshirt, into the sky.',
                'Martha folds her arms and waits.',
                'Three hours later he drifts back down holding a probe-shaped souvenir, an',
                'improved map of the trail, and no memory of the in-flight snacks.',
                '"We already have a map," says Martha. The new one is better: it saves 40 miles.',
            ],
            'err' => ['Ezekiel has been abducted by aliens. (Temporarily.)'],
            'message' => 'Encounter: a minor, temporary alien abduction. Ezekiel is back.',
            'wagon' => ['items' => ['probe-shaped souvenir', 'an improved map']],
        ],
        [
            'date' => 'August 20, 1848', 'status' => 'Resting at Fort Hall', 'miles' => 1248, 'seconds' => 6,
            'landmark' => 'Fort Hall',
            'out' => [
                'Fort Hall, with its whitewashed walls and a trader who only accepts exact change.',
            ],
            'err' => ['Prudence feels unwell.'],
            'wagon' => ['food' => -50, 'health' => ['Prudence' => 'poor']],
        ],
        [
            'date' => 'August 28, 1848', 'status' => 'Crossing the Snake River', 'miles' => 1430, 'seconds' => 7,
            'landmark' => 'Snake River Crossing',
            'out' => [
                'The Snake River: 1,000 feet across and 6.8 feet deep.',
                '> Ford, caulk, or take the ferry? The ferry. $15, and worth every cent.',
            ],
            'err' => ['Prudence has dysentery.'],
            'wagon' => ['money' => -15, 'food' => -40, 'health' => ['Prudence' => 'very poor']],
        ],
        [
            'date' => 'September 3, 1848', 'status' => 'Burying Prudence', 'miles' => 1460, 'seconds' => 8,
            'out' => [
                'Prudence asks for her fiddle and plays one last reel, badly, and laughs about it.',
                'They bury her beneath a juniper. Martha leaves the fiddle on the marker.',
                'HERE LIES PRUDENCE. HER FIDDLE PLAYS ON.',
            ],
            'err' => ['Prudence has died of dysentery.'],
            'message' => 'Prudence has died of dysentery.',
            'wagon' => ['dead' => 'Prudence', 'epitaph' => 'Here lies Prudence. Her fiddle plays on.'],
        ],
        [
            'date' => 'September 15, 1848', 'status' => 'Resting at Fort Boise', 'miles' => 1543, 'seconds' => 6,
            'landmark' => 'Fort Boise',
            'out' => [
                'Fort Boise. Two travellers now, six oxen, and a skillet.',
            ],
            'wagon' => ['food' => -40],
        ],
        [
            'date' => 'September 18, 1848', 'status' => 'Investigating a strange metal carriage', 'miles' => 1570, 'seconds' => 10,
            'out' => [
                'Half-buried in the sagebrush: a low, gleaming carriage of brushed steel, its',
                'door raised like a gull\'s wing. A 1985 DeLorean.',
                'In the back, a canister glows green: PLUTONIUM. A box on the dashboard reads',
                '"FLUX CAPACITOR" and is, as far as anyone can tell, fluxing.',
                'A note under the wiper: "If found, please return to 1985. - Doc"',
                '> Drive it? It needs 1.21 gigawatts and 88 miles per hour. The oxen manage 2.',
                'Ezekiel keeps the note. They leave the car. "Great Scott," says Martha.',
            ],
            'err' => ['Radiation detected near the wagon. Moving on, briskly.'],
            'message' => 'Encounter: a 1985 DeLorean with a plutonium core. Great Scott.',
            'wagon' => ['items' => ['a note from Doc']],
        ],
        [
            'date' => 'September 29, 1848', 'status' => 'Climbing the Blue Mountains', 'miles' => 1703, 'seconds' => 7,
            'landmark' => 'The Blue Mountains',
            'out' => [
                'The Blue Mountains: switchbacks, cold mornings, and pines that smell like Christmas.',
                'You replace the broken axle from your spares and keep climbing.',
            ],
            'err' => ['A wagon axle has broken.'],
            'wagon' => ['axles' => -1, 'food' => -50, 'weather' => 'cold'],
        ],
        [
            'date' => 'October 14, 1848', 'status' => 'Rafting down the Columbia River', 'miles' => 1880, 'seconds' => 8,
            'out' => [
                'The Dalles. The road ends at the Columbia River.',
                '> Raft down the river, or take the Barlow toll road ($10)? Raft.',
                'White water, black rocks, and a lot of shouting.',
            ],
            'err' => ['The raft hits a rock. 50 lbs of food go over the side.'],
            'wagon' => ['food' => -50],
        ],
        [
            'date' => 'November 2, 1848', 'status' => 'Arrived in the Willamette Valley, Oregon', 'miles' => 2040, 'seconds' => 8,
            'landmark' => 'Willamette Valley, Oregon',
            'out' => [
                'The trees open onto green fields and a slow, wide river.',
                'You have reached the Willamette Valley, Oregon.',
                '',
                'Ezekiel and Martha step down from the wagon. Martha hangs the skillet on a',
                'nail by the door of a cabin that does not exist yet.',
                '',
                'Congratulations! You have made it to Oregon.',
            ],
            'message' => 'The party has reached the Willamette Valley, Oregon.',
        ],
    ];

    /**
     * Play the Oregon Trail. Optional params: speed (a multiplier on every pause, default 1;
     * 0 plays the whole script without pausing).
     */
    #[Task('Play a scripted three-minute game of the Oregon Trail, reporting every kind of task status')]
    public static function travel(Task_Instance $task, array $params = [])
    {
        $speed = isset($params['speed']) ? (float) $params['speed'] : 1.0;
        if ($speed < 0) {
            $task->stderr('speed must be 0 or more.');

            return 2;
        }

        $wagon = [
            'date' => 'March 1, 1848',
            'miles' => 0,
            'weather' => 'cool',
            'pace' => 'steady',
            'rations' => 'filling',
            'morale' => 'hopeful',
            'provisions' => [
                'oxen' => 6, 'food' => 1000, 'clothing' => 12, 'ammunition' => 400,
                'wheels' => 2, 'axles' => 2, 'tongues' => 2, 'money' => 250,
            ],
            'party' => ['Ezekiel' => 'good', 'Martha' => 'good', 'Cornelius' => 'good', 'Prudence' => 'good'],
            'items' => ['cast-iron skillet'],
        ];
        $journal = [];
        $epitaphs = [];
        $landmarks_reached = 0;
        $seconds_left = (int) ceil(array_sum(array_column(self::BEATS, 'seconds')) * $speed);

        foreach (self::BEATS as $beat) {
            if ($task->is_stop_requested()) {
                return static::__make_camp($task, $wagon, $journal, $epitaphs, $landmarks_reached);
            }

            $wagon['date'] = $beat['date'];
            $wagon['miles'] = $beat['miles'];
            static::__apply($wagon, $beat['wagon'] ?? [], $epitaphs);

            $task->status($beat['status']);
            $journal[] = '';
            $journal[] = '--- ' . $beat['date'] . ' - ' . $beat['status'] . ' (' . $beat['miles'] . ' miles) ---';

            if (isset($beat['landmark'])) {
                $landmarks_reached++;
                $task->message("Landmark reached: {$beat['landmark']} ({$beat['miles']} miles).");
            }
            if (isset($beat['message'])) {
                $task->message($beat['message']);
            }

            $task->progress_count($landmarks_reached, count(self::LANDMARKS));
            $task->state_list(array_slice(self::LANDMARKS, $landmarks_reached));
            $task->state($wagon);
            $task->heartbeat();

            // The beat plays out: its lines appear one at a time across its pause, and the
            // hazards land halfway through.
            $lines = $beat['out'];
            $hazards = $beat['err'] ?? [];
            $steps = max(1, count($lines));
            $step_seconds = $beat['seconds'] * $speed / $steps;

            foreach ($lines as $i => $line) {
                $task->stdout($line === '' ? ' ' : $line);
                $journal[] = $line;

                if ($hazards && $i === intdiv($steps, 2)) {
                    foreach ($hazards as $hazard) {
                        $task->stderr('! ' . $hazard);
                        $journal[] = '! ' . $hazard;
                    }
                    $hazards = [];
                }

                if (!static::__pause($task, $step_seconds)) {
                    return static::__make_camp($task, $wagon, $journal, $epitaphs, $landmarks_reached);
                }
            }

            $seconds_left = max(0, $seconds_left - (int) round($beat['seconds'] * $speed));
            $task->eta($seconds_left);
        }

        $survivors = array_keys(array_filter($wagon['party'], fn ($health) => $health !== 'dead'));
        $score = $wagon['provisions']['food'] + 50 * count($survivors) + 10 * $wagon['provisions']['oxen'] + $wagon['provisions']['money'];

        $task->stdout(' ');
        $task->stdout('FINAL TALLY');
        $task->stdout('  Survivors: ' . implode(' and ', $survivors));
        $task->stdout('  Lost to dysentery: ' . implode(', ', array_keys(array_filter($wagon['party'], fn ($health) => $health === 'dead'))));
        $task->stdout('  Food remaining: ' . $wagon['provisions']['food'] . ' lbs; oxen: ' . $wagon['provisions']['oxen'] . '; cash: $' . $wagon['provisions']['money']);
        $task->stdout('  Score: ' . $score);
        $task->stdout('THE END');

        $journal[] = '';
        $journal[] = 'Arrived ' . $wagon['date'] . '. Survivors: ' . implode(' and ', $survivors) . '. Score: ' . $score . '.';

        static::__attach_journal($task, $journal, $epitaphs);
        $task->summary('Reached the Willamette Valley on ' . $wagon['date'] . ' after ' . self::TRAIL_MILES . ' miles. '
            . implode(' and ', $survivors) . ' survived; ' . count($epitaphs) . ' of the party died of dysentery. Score: ' . $score . '.');
        $task->status('Arrived in Oregon');

        return null;
    }

    /**
     * Wait $seconds, asking about a stop request every STOP_CHECK_SECONDS. False when a stop
     * was requested.
     */
    private static function __pause(Task_Instance $task, float $seconds): bool
    {
        $remaining = $seconds;

        while ($remaining > 0) {
            $slice = min(self::STOP_CHECK_SECONDS, $remaining);
            usleep((int) ($slice * 1000000));
            $remaining -= $slice;

            if ($task->is_stop_requested()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply one beat's changes to the wagon.
     */
    private static function __apply(array &$wagon, array $changes, array &$epitaphs): void
    {
        foreach ($changes as $key => $value) {
            if (array_key_exists($key, $wagon['provisions'])) {
                $wagon['provisions'][$key] = max(0, $wagon['provisions'][$key] + $value);
            } elseif ($key === 'health') {
                foreach ($value as $name => $health) {
                    $wagon['party'][$name] = $health;
                }
            } elseif ($key === 'dead') {
                $wagon['party'][$value] = 'dead';
            } elseif ($key === 'epitaph') {
                $epitaphs[] = $value;
            } elseif ($key === 'items') {
                $wagon['items'] = array_values(array_merge($wagon['items'], $value));
            } elseif ($key === 'items_lost') {
                $wagon['items'] = array_values(array_diff($wagon['items'], $value));
            } else {
                $wagon[$key] = $value;
            }
        }
    }

    /**
     * Answer a graceful stop: make camp where the party stands, attach what the journal holds
     * so far, and end the run - it settles STOPPED.
     */
    private static function __make_camp(Task_Instance $task, array $wagon, array $journal, array $epitaphs, int $landmarks_reached)
    {
        $task->status('Made camp - the journey was stopped');
        $task->stdout(' ');
        $task->stdout('> A stop has been requested.');
        $task->stdout('The party pulls the wagon off the trail, circles up, and makes camp at mile '
            . $wagon['miles'] . ' (' . $wagon['date'] . '). Oregon will have to wait.');

        $journal[] = '';
        $journal[] = 'The journey was stopped at mile ' . $wagon['miles'] . ' on ' . $wagon['date'] . '.';

        static::__attach_journal($task, $journal, $epitaphs);
        $task->summary('Stopped on request at mile ' . $wagon['miles'] . ' of ' . self::TRAIL_MILES . ' (' . $wagon['date'] . '), '
            . $landmarks_reached . ' of ' . count(self::LANDMARKS) . ' landmarks reached.');

        return null;
    }

    private static function __attach_journal(Task_Instance $task, array $journal, array $epitaphs): void
    {
        $task->attach_bytes('trail_journal.txt', "THE OREGON TRAIL - A JOURNAL\n" . implode("\n", $journal) . "\n", 'trail_journal.txt', 'text/plain');

        if ($epitaphs) {
            $task->attach_bytes('epitaphs.txt', implode("\n", $epitaphs) . "\n", 'epitaphs.txt', 'text/plain');
        }
    }
}
