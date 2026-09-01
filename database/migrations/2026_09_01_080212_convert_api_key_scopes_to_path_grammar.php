<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert every stored _api_keys.scopes value from the old rule language to the new one.
     *
     * THE OLD LANGUAGE was one `Grant|Deny METHOD /api/vN/pattern` per line, with `*` matching
     * one segment, `**` matching the rest, and the most specific rule winning. THE NEW ONE is
     * one bare path pattern per line, always a grant, with `?` for one segment of any shape,
     * `#` for one all-digits segment, and `*` for the last segment and everything below it.
     *
     * A GRANT-ONLY KEY CONVERTS EXACTLY. Dropping the keyword and the method loses nothing a
     * scope can express, and the pattern translates term for term: `**` becomes `*`, a lone
     * `*` segment becomes `?`. Two rules that differed only by method collapse into one path.
     * The result grants the same paths it always did, for both verbs rather than one - which
     * is the only direction this conversion can go, since a scope carries no method, and it
     * is safe because API-GET-PURE-01 already guarantees a GET endpoint does not write.
     *
     * A KEY CARRYING ANY `Deny` IS DELIBERATELY NOT CONVERTED, AND FAILS CLOSED. A Deny is a
     * carve-out: it exists precisely because some subset of a broader grant was meant to be
     * unreachable, and the new language has no way to say so - the operator has to re-express
     * the intent as a narrower set of grants, and only they know what that set is. Guessing
     * would either widen the key (dropping the Deny) or narrow it silently (dropping the
     * Grant it carved out of), and the first of those is a privilege escalation performed by
     * a migration. So the text is LEFT UNTOUCHED. Under the new grammar every one of its
     * lines is malformed, and a malformed scope is ignored for matching while still counting
     * as a registered scope - so the key denies everything and each request logs a warning
     * naming it. The key stops working loudly rather than working wrongly, and this migration
     * prints its id so the operator knows which one to re-scope.
     *
     * WRITTEN AS A PHP LOOP, not as SQL: this is a data TRANSFORM of a text column with a
     * grammar in it, and the transform is not expressible in MySQL. The scope classes are
     * deliberately NOT used (a migration is self-contained and never reaches into model or
     * framework code) - the twenty lines below are the whole of the old grammar it must read.
     *
     * @return void
     */
    public function up()
    {
        $rows = DB::select("SELECT id, scopes FROM _api_keys WHERE scopes IS NOT NULL AND scopes <> ''");

        $converted = 0;
        $skipped = [];

        foreach ($rows as $row) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $row->scopes);

            $paths = [];
            $has_deny = false;

            foreach ($lines as $raw_line) {
                $line = trim($raw_line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $tokens = preg_split('/\s+/', $line);

                // Anything that is not a three-token Grant rule - a Deny, or a line that was
                // already malformed - disqualifies the whole key. See the docblock.
                if (count($tokens) !== 3 || strtolower($tokens[0]) !== 'grant') {
                    $has_deny = true;
                    break;
                }

                $pattern = $tokens[2];

                $segments = explode('/', ltrim($pattern, '/'));
                foreach ($segments as $index => $segment) {
                    if ($segment === '**') {
                        $segments[$index] = '*';
                        continue;
                    }
                    if ($segment === '*') {
                        $segments[$index] = '?';
                    }
                }

                $paths['/' . implode('/', $segments)] = true;
            }

            if ($has_deny) {
                $skipped[] = (int) $row->id;
                continue;
            }

            $text = empty($paths) ? null : implode("\n", array_keys($paths));

            DB::update('UPDATE _api_keys SET scopes = ? WHERE id = ?', [$text, (int) $row->id]);
            $converted++;
        }

        echo "  [OK] API key scopes converted to the path grammar: {$converted} key(s).\n";

        if (!empty($skipped)) {
            echo "  [WARNING] " . count($skipped) . " key(s) carried a Deny rule and were NOT converted: "
                . implode(', ', $skipped) . "\n";
            echo "  [WARNING] Those keys now DENY EVERYTHING (fail closed). Revoke and re-mint each one\n";
            echo "  [WARNING] with grant-only scopes: php artisan rsx:man external_api (KEY SCOPES).\n";
        }
    }
};
