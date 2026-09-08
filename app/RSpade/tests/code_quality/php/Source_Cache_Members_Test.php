<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Support\Source_Cache;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE MEMBER SUMMARY - the AST-free projection four ancestry rules read.
 *
 * PHP-PARENT-CHAIN-01, SEALED-01, REVISION-01 and POLY-01 each walk the whole indexed tree
 * asking structural questions about a class and its ancestors. They ran one after another
 * over the same seven hundred files, and a 16-entry LRU cannot hold seven hundred files, so
 * every rule re-parsed every file: 5.2 s of a 10.3 s cold build. Sharing the AST could not
 * fix that - the AST is exactly what the memory budget forbids retaining.
 *
 * `Source_Cache::declared_members()` is what replaced it: a flat summary holding no nikic
 * nodes, so it can outlive the AST it came from and still be proportional to the index.
 *
 * What is asserted here is the CONTRACT the four rules read - the fields they branch on -
 * against synthetic fixtures, so a change to the extraction shows up as a failing row
 * rather than as a rule that silently stops finding anything.
 */
class Source_Cache_Members_Test extends Rsx_Test_Abstract
{
    // Pure parse work - no database access.
    protected static $use_database_transactions = false;

    private static array $fixtures = [];

    /**
     * Write $source to a throwaway .php file and return its absolute path.
     */
    private static function __fixture(string $source): string
    {
        $path = storage_path('rsx-tmp') . '/source_cache_members_' . uniqid() . '.php';
        file_put_contents($path, $source);
        self::$fixtures[] = $path;

        return $path;
    }

    private static function __cleanup(): void
    {
        foreach (self::$fixtures as $path) {
            @unlink($path);
        }

        self::$fixtures = [];
    }

    /**
     * Methods: name, abstract, static, visibility, attributes, body presence.
     */
    public static function test_method_summary_fields()
    {
        $path = self::__fixture(<<<'PHP'
<?php

abstract class Scm_Methods
{
    public function plain() {}

    abstract protected function shaped();

    #[Replaceable]
    private static function marked() {}
}
PHP);

        try {
            $cache = new Source_Cache(4);
            $methods = $cache->declared_members($path, 'Scm_Methods')['methods'];

            static::__assert_count(3, $methods, 'every declared method is summarized');

            static::__assert_equals('plain', $methods['plain']['name']);
            static::__assert_false($methods['plain']['abstract']);
            static::__assert_false($methods['plain']['static']);
            static::__assert_equals('public', $methods['plain']['visibility']);
            static::__assert_true($methods['plain']['has_body']);

            static::__assert_true($methods['shaped']['abstract'], 'an abstract declaration says so');
            static::__assert_equals('protected', $methods['shaped']['visibility']);
            static::__assert_false($methods['shaped']['has_body'], 'a bodiless declaration has no body');

            static::__assert_true($methods['marked']['static']);
            static::__assert_equals('private', $methods['marked']['visibility']);
            static::__assert_array_has_key(
                'replaceable',
                $methods['marked']['attributes'],
                'a marker attribute is indexed by its lowercased simple name'
            );
        } finally {
            self::__cleanup();
        }
    }

    /**
     * The methods a body invokes through parent::, including the dynamic form.
     */
    public static function test_parent_call_extraction()
    {
        $path = self::__fixture(<<<'PHP'
<?php

class Scm_Parent_Calls extends Scm_Base
{
    public function chained()
    {
        parent::chained();
    }

    public function unchained()
    {
        // parent::unchained() named only in a comment
        return 1;
    }

    public function other()
    {
        parent::somethingElse();
    }

    public function dynamic($name)
    {
        return parent::{$name}();
    }
}
PHP);

        try {
            $cache = new Source_Cache(4);
            $methods = $cache->declared_members($path, 'Scm_Parent_Calls')['methods'];

            static::__assert_array_has_key('chained', $methods['chained']['parent_calls']);
            static::__assert_empty(
                $methods['unchained']['parent_calls'],
                'a mention inside a comment is not a call - the AST decides'
            );
            static::__assert_array_has_key('somethingelse', $methods['other']['parent_calls']);
            static::__assert_false(
                isset($methods['other']['parent_calls']['other']),
                'parent::somethingElse() does not satisfy other()'
            );
            static::__assert_array_has_key(
                '*',
                $methods['dynamic']['parent_calls'],
                'a dynamic parent call is recorded as the wildcard, so a caller fails open'
            );
        } finally {
            self::__cleanup();
        }
    }

    /**
     * Properties: attributes, and the LITERAL defaults the ancestry rules read.
     */
    public static function test_property_summary_fields()
    {
        $path = self::__fixture(<<<'PHP'
<?php

class Scm_Properties
{
    protected $table = 'scm_things';

    public static $revisions = true;

    public static $realtime = false;

    public static $type_ref_columns = ['owner_type', 'target_type'];

    #[Sealed]
    public static $locked = 1;

    public static $computed = SOME_CONSTANT . 'x';

    public static $undecided;
}
PHP);

        try {
            $cache = new Source_Cache(4);
            $properties = $cache->declared_members($path, 'Scm_Properties')['properties'];

            static::__assert_equals('scm_things', $properties['table']['default']);
            static::__assert_equals('protected', $properties['table']['visibility']);
            static::__assert_true($properties['revisions']['default']);
            static::__assert_false($properties['realtime']['default']);
            static::__assert_true($properties['realtime']['static']);
            static::__assert_equals(
                ['owner_type', 'target_type'],
                $properties['type_ref_columns']['default'],
                'a flat list of string literals is carried whole'
            );
            static::__assert_array_has_key('sealed', $properties['locked']['attributes']);
            static::__assert_null(
                $properties['computed']['default'],
                'an expression is not a literal, so the summary declines to carry it'
            );
            static::__assert_true($properties['computed']['has_default']);
            static::__assert_false($properties['undecided']['has_default']);
        } finally {
            self::__cleanup();
        }
    }

    /**
     * Exception markers, in the three places every rule has always looked.
     */
    public static function test_exception_markers()
    {
        $path = self::__fixture(<<<'PHP'
<?php

class Scm_Markers
{
    /**
     * @PHP-PARENT-CHAIN-01-EXCEPTION documented in the docblock
     */
    public function in_docblock() {}

    // @SEALED-01-EXCEPTION on the line above
    public function above() {}

    public function clean() {}
}
PHP);

        try {
            $cache = new Source_Cache(4);
            $methods = $cache->declared_members($path, 'Scm_Markers')['methods'];

            static::__assert_array_has_key('PHP-PARENT-CHAIN-01', $methods['in_docblock']['exceptions']);
            static::__assert_array_has_key('SEALED-01', $methods['above']['exceptions']);
            static::__assert_empty($methods['clean']['exceptions']);
        } finally {
            self::__cleanup();
        }
    }

    /**
     * A class that is absent, or a file that does not parse, summarizes to nothing - the
     * four rules read "declares no members" and decline to judge, exactly as they did when
     * an unlocatable ancestor produced a null class node.
     */
    public static function test_missing_class_and_unparseable_file_summarize_to_nothing()
    {
        $present = self::__fixture("<?php\n\nclass Scm_Present\n{\n    public function go() {}\n}\n");
        $broken = self::__fixture("<?php\n\nclass Scm_Broken\n{\n    public function go( {\n");

        try {
            $cache = new Source_Cache(4);

            $absent = $cache->declared_members($present, 'Scm_Not_Declared_Here');
            static::__assert_empty($absent['methods']);
            static::__assert_empty($absent['properties']);

            $unparseable = $cache->declared_members($broken, 'Scm_Broken');
            static::__assert_empty($unparseable['methods']);

            $missing = $cache->declared_members('/no/such/file/at/all.php', 'Scm_Nothing');
            static::__assert_empty($missing['methods']);
        } finally {
            self::__cleanup();
        }
    }

    /**
     * THE POINT OF THE WHOLE THING: the summary outlives the AST.
     *
     * A cache bounded at one entry evicts the first file's AST the moment the second is
     * parsed - and asking the first for its members again must not re-parse it, because
     * that eviction pattern is exactly what four rules in a row produce.
     */
    public static function test_summary_survives_ast_eviction()
    {
        $one = self::__fixture("<?php\n\nclass Scm_One\n{\n    public function a() {}\n}\n");
        $two = self::__fixture("<?php\n\nclass Scm_Two\n{\n    public function b() {}\n}\n");

        try {
            $cache = new Source_Cache(1);

            $cache->declared_members($one, 'Scm_One');
            $cache->declared_members($two, 'Scm_Two');

            $parses_before = $cache->work_counts()['ast'];

            static::__assert_array_has_key('a', $cache->declared_members($one, 'Scm_One')['methods']);

            static::__assert_equals(
                $parses_before,
                $cache->work_counts()['ast'],
                'the summary answered without a second parse of the evicted file'
            );
        } finally {
            self::__cleanup();
        }
    }

    /**
     * release() drops the summaries with everything else - the instance belongs to one pass.
     */
    public static function test_release_drops_the_summaries()
    {
        $path = self::__fixture("<?php\n\nclass Scm_Released\n{\n    public function a() {}\n}\n");

        try {
            $cache = new Source_Cache(4);
            $cache->declared_members($path, 'Scm_Released');

            static::__assert_equals(1, $cache->resident_counts()['members']);

            $cache->release();

            static::__assert_equals(0, $cache->resident_counts()['members']);
        } finally {
            self::__cleanup();
        }
    }
}
