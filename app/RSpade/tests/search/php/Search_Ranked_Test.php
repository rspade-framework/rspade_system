<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Search\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Search_Index_Model::search_ranked() - the relevance-exposing sibling of search().
 *
 * Proves the four things the API promises: the `relevance` column is present and numeric,
 * the builder arrives pre-ordered by it descending, composing further where() clauses keeps
 * BOTH the score and the ordering, and search() itself is unchanged (same match set, no
 * relevance column). NATURAL LANGUAGE mode is exercised alongside the BOOLEAN default.
 *
 * This class COMMITS. InnoDB's FULLTEXT index is not consulted for a transaction's own
 * uncommitted rows, so a MATCH...AGAINST inside the harness transaction would see nothing at
 * all; the fixture rows must be committed, which means $use_database_transactions = false and
 * $requires_db_reset = true (the runner provisions a clean baseline around the class). The
 * rows are removed in teardown regardless.
 *
 * FIXTURE DESIGN. The corpus is four rows written directly into _search_indexes: there is no
 * FK on indexable_id, so no blob or attachment is needed and the content is authored exactly
 * as ranking needs it. Two rows carry a per-run nonsense token, one of them six times and the
 * other once, so they genuinely rank apart on term frequency; two carry unrelated prose so the
 * term is rare in the corpus. The token is 12+ characters (above innodb_ft_min_token_size and
 * not a stopword) and is present in a MINORITY of the rows - MySQL's natural-language 50%
 * threshold scores a term appearing in more than half the rows as zero, so the fixture stays
 * on the safe side of it in every engine.
 */
class Search_Ranked_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    /** @var array<int> indexable_id values written by this class, removed in teardown. */
    private static $created_indexable_ids = [];

    /** @var string the per-run nonsense token the fixture ranks on. */
    private static $token = '';

    /** @var int indexable_id of the row repeating the token (should rank first). */
    private static $strong_id = 0;

    /** @var int indexable_id of the row mentioning the token once. */
    private static $weak_id = 0;

    public static function setup()
    {
        static::$token = 'rankneedle' . substr(md5((string) microtime(true)), 0, 8);

        // indexable_id is not a foreign key - the rows stand alone. Start above every existing
        // id so the unique (indexable_type, indexable_id) key can never collide with real rows.
        $base = (int) DB::selectOne('SELECT COALESCE(MAX(indexable_id), 0) AS max_id FROM _search_indexes')->max_id + 1000;

        static::$strong_id = $base + 1;
        static::$weak_id = $base + 2;

        $repeated = trim(str_repeat(static::$token . ' ', 6));

        static::__write_row(static::$strong_id, "quarterly filing {$repeated} closing remarks");
        static::__write_row(static::$weak_id, 'quarterly filing ' . static::$token . ' among a good deal of unrelated prose about shipping schedules');
        static::__write_row($base + 3, 'an unrelated memorandum about shipping schedules and warehouse capacity');
        static::__write_row($base + 4, 'minutes of a meeting concerning warehouse capacity and staffing');
    }

    public static function teardown()
    {
        foreach (static::$created_indexable_ids as $indexable_id) {
            $row = Search_Index_Model::forModel('File_Storage_Model', $indexable_id)->first();
            if ($row) {
                $row->delete();
            }
        }
        static::$created_indexable_ids = [];
    }

    /**
     * Write one committed index row carrying $content.
     */
    private static function __write_row(int $indexable_id, string $content): void
    {
        $row = Search_Index_Model::find_or_create_for_model('File_Storage_Model', $indexable_id);
        $row->status_id = Search_Index_Model::STATUS_EXTRACTED;
        $row->content = $content;
        $row->extraction_method = 'Search_Ranked_Test';
        $row->save();

        static::$created_indexable_ids[] = $indexable_id;
    }

    /**
     * The fixture rows matching $token in $mode, ranked, as [indexable_id => relevance] in the
     * order the builder returned them.
     */
    private static function __ranked(string $mode = 'BOOLEAN'): array
    {
        $rows = Search_Index_Model::search_ranked(static::$token, $mode)
            ->whereIn('indexable_id', static::$created_indexable_ids)
            ->limit(10)
            ->get();

        $ranked = [];
        foreach ($rows as $row) {
            $ranked[(int) $row->indexable_id] = (float) $row->relevance;
        }

        return $ranked;
    }

    public static function test_relevance_column_is_exposed_and_positive()
    {
        $ranked = static::__ranked();

        static::__assert_equals(2, count($ranked), 'only the two rows carrying the token match');
        foreach ($ranked as $indexable_id => $relevance) {
            static::__assert_true(is_numeric($relevance), "row {$indexable_id} exposes a numeric relevance");
            static::__assert_greater_than(0, $relevance, "row {$indexable_id} scores above zero");
        }
    }

    public static function test_ordered_by_relevance_descending()
    {
        $ranked = static::__ranked();
        $order = array_keys($ranked);

        static::__assert_equals(static::$strong_id, $order[0], 'the row repeating the term ranks first');
        static::__assert_equals(static::$weak_id, $order[1], 'the row mentioning it once ranks second');
        static::__assert_greater_than($ranked[static::$weak_id], $ranked[static::$strong_id], 'term frequency produces a genuinely higher score');
    }

    public static function test_composed_where_preserves_score_and_order()
    {
        // Composing after search_ranked() is the whole point of returning a Builder: the extra
        // clauses must not drop the selected score or the ordering the method applied.
        $rows = Search_Index_Model::search_ranked(static::$token)
            ->where('indexable_type', 'File_Storage_Model')
            ->where('status_id', Search_Index_Model::STATUS_EXTRACTED)
            ->whereIn('indexable_id', static::$created_indexable_ids)
            ->limit(10)
            ->get();

        static::__assert_count(2, $rows, 'the composed query returns the same two matches');

        $first = $rows[0];
        $second = $rows[1];

        static::__assert_not_null($first->relevance, 'relevance survives the composed where clauses');
        static::__assert_equals(static::$strong_id, (int) $first->indexable_id, 'ordering survives the composed where clauses');
        static::__assert_greater_than((float) $second->relevance, (float) $first->relevance, 'the ranking is still descending after composition');
    }

    public static function test_search_is_unchanged()
    {
        // search() must keep its cheaper shape: the same match set, and NO relevance column.
        $rows = Search_Index_Model::search(static::$token)
            ->whereIn('indexable_id', static::$created_indexable_ids)
            ->limit(10)
            ->get();

        $matched = [];
        foreach ($rows as $row) {
            $matched[] = (int) $row->indexable_id;
        }
        sort($matched);

        $expected = [static::$strong_id, static::$weak_id];
        sort($expected);

        static::__assert_equals($expected, $matched, 'search() matches exactly the rows search_ranked() ranks');
        static::__assert_false(array_key_exists('relevance', $rows[0]->toArray()), 'search() selects no relevance column');
        static::__assert_null($rows[0]->relevance, 'the attribute is simply absent on a search() row');
    }

    public static function test_natural_language_mode_ranks_too()
    {
        $ranked = static::__ranked('NATURAL LANGUAGE');
        $order = array_keys($ranked);

        static::__assert_equals(2, count($ranked), 'natural language mode matches the same two rows');
        static::__assert_equals(static::$strong_id, $order[0], 'natural language mode ranks the repeated term first');
        static::__assert_greater_than(0, $ranked[static::$strong_id], 'natural language mode produces a positive score (the term is in a minority of rows, below the 50% threshold)');
    }
}
