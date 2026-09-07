<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Codegen\Model_Codegen_Rewriter;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * Regression tests for the fence-safe model-docblock rewriter.
 *
 * These enforce the contract from
 * docs.dev/external_requests/2026_07_15_document_models_regen_clobbers_class_top.md:
 *
 *   1. Every byte outside the __AUTO_GENERATED fences survives a regeneration verbatim -
 *      including code between the class brace and the first fence (the spot a past regen
 *      silently deleted a `public static $realtime = true;` block).
 *   2. A structural surprise (class not found, duplicate/unbalanced markers, parse error)
 *      fails loud rather than best-effort-rewriting the file.
 *   3. A mechanism-independent self-check refuses the write when the strip-and-compare of
 *      hand-written content differs.
 *
 * Pure in-memory rewriter calls - no database, no filesystem.
 */
class Model_Codegen_Rewriter_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The CR requirement-4 fixture: a hand-written static property + docblock between the
     * class brace and the first fence, hand-written methods after the fences, a trailing
     * hand-written comment, and stale generated content inside the fences.
     */
    protected static function __fixture(): string
    {
        return <<<'EOF'
<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Generated on: 2020-01-01 00:00:00
 * Table: widgets
 *
 * @property int $id
 * @property mixed $old_column
 *
 * @mixin \Eloquent
 */
class Widget_Model extends Rsx_Site_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_STALE = 9;

    // HANDWRITTEN: this realtime block sits between the brace and the first fence.
    public static $realtime = true;

    protected $table = 'widgets';

    protected static $type_ref_columns = ['thing_type'];

    public static $enums = [
        'status' => [
            1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],
            2 => ['constant' => 'STATUS_DONE', 'label' => 'Done'],
        ],
    ];

    // HANDWRITTEN trailing method
    public function hello()
    {
        return 'world';
    }
}
EOF;
    }

    protected static function __new_doc_block(): string
    {
        return "/**\n"
            . " * _AUTO_GENERATED_ Database type hints - do not edit manually\n"
            . " * Generated on: 2026-07-15 12:00:00\n"
            . " * Table: widgets\n"
            . " *\n"
            . " * @property int \$id\n"
            . " * @property mixed \$new_column\n"
            . " *\n"
            . " * @mixin \\Eloquent\n"
            . " */";
    }

    protected static function __new_constants_block(): string
    {
        return "    /**\n"
            . "     * _AUTO_GENERATED_ Enum constants\n"
            . "     */\n"
            . "    const STATUS_ACTIVE = 1;\n"
            . "    const STATUS_DONE = 2;\n";
    }

    // -------------------------------------------------------------------------
    // Requirement 1 + 4: hand-written content survives verbatim
    // -------------------------------------------------------------------------

    public static function test_handwritten_property_between_brace_and_fence_survives()
    {
        $result = Model_Codegen_Rewriter::rewrite(
            static::__fixture(),
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        static::__assert_contains('public static $realtime = true;', $result);
        static::__assert_contains("protected static \$type_ref_columns = ['thing_type'];", $result);
    }

    public static function test_handwritten_method_after_fences_survives()
    {
        $result = Model_Codegen_Rewriter::rewrite(
            static::__fixture(),
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        static::__assert_contains('public function hello()', $result);
        static::__assert_contains("return 'world';", $result);
    }

    public static function test_trailing_and_inline_handwritten_comments_survive()
    {
        $result = Model_Codegen_Rewriter::rewrite(
            static::__fixture(),
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        static::__assert_contains('// HANDWRITTEN: this realtime block', $result);
        static::__assert_contains('// HANDWRITTEN trailing method', $result);
    }

    public static function test_handwritten_bytes_are_byte_identical_after_rewrite()
    {
        // The strict guarantee: strip the owned regions from the original and the result;
        // everything left (the hand-written content) must be byte-for-byte identical.
        $original = static::__fixture();
        $result = Model_Codegen_Rewriter::rewrite(
            $original,
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        $stripped_original = Model_Codegen_Rewriter::strip_owned_regions($original, 'Widget_Model');
        $stripped_result = Model_Codegen_Rewriter::strip_owned_regions($result, 'Widget_Model');

        static::__assert_equals($stripped_original, $stripped_result, 'Hand-written content changed during rewrite');
    }

    // -------------------------------------------------------------------------
    // Generated content IS regenerated inside the fences
    // -------------------------------------------------------------------------

    public static function test_stale_generated_content_is_regenerated()
    {
        $result = Model_Codegen_Rewriter::rewrite(
            static::__fixture(),
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        // Fresh docblock + fresh consts in, stale docblock column + stale const out.
        static::__assert_contains('@property mixed $new_column', $result);
        static::__assert_contains('const STATUS_ACTIVE = 1;', $result);
        static::__assert_contains('const STATUS_DONE = 2;', $result);
        static::__assert_true(!str_contains($result, 'old_column'), 'Stale @property survived');
        static::__assert_true(!str_contains($result, 'STATUS_STALE'), 'Stale enum const survived');
    }

    public static function test_result_is_valid_php()
    {
        $result = Model_Codegen_Rewriter::rewrite(
            static::__fixture(),
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        static::__assert_not_null($parser->parse($result), 'Rewrite produced unparseable PHP');
    }

    public static function test_rewrite_is_idempotent()
    {
        $once = Model_Codegen_Rewriter::rewrite(
            static::__fixture(),
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );
        $twice = Model_Codegen_Rewriter::rewrite(
            $once,
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        static::__assert_equals($once, $twice, 'Rewrite is not idempotent');
    }

    // -------------------------------------------------------------------------
    // Insertion (a model with no existing auto regions)
    // -------------------------------------------------------------------------

    public static function test_insertion_into_model_without_auto_regions()
    {
        $bare = <<<'EOF'
<?php
namespace Rsx\Models;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
class Bare_Model extends Rsx_Site_Model_Abstract
{
    protected $table = 'bares';
    public static $realtime = true;

    public static $enums = [
        'kind' => [ 1 => ['constant' => 'KIND_A', 'label' => 'A'] ],
    ];
}
EOF;
        $doc = "/**\n * _AUTO_GENERATED_ Database type hints - do not edit manually\n * Table: bares\n *\n * @property int \$id\n *\n * @mixin \\Eloquent\n */";
        $cb = "    /**\n     * _AUTO_GENERATED_ Enum constants\n     */\n    const KIND_A = 1;\n";

        $result = Model_Codegen_Rewriter::rewrite($bare, 'Bare_Model', $doc, $cb);

        static::__assert_contains('_AUTO_GENERATED_ Database type hints', $result);
        static::__assert_true(
            strpos($result, '_AUTO_GENERATED_ Database') < strpos($result, 'class Bare_Model'),
            'Docblock was not inserted before the class'
        );
        static::__assert_contains('const KIND_A = 1;', $result);
        static::__assert_contains('public static $realtime = true;', $result);

        // Byte-exact hand-written preservation across an insertion too.
        static::__assert_equals(
            Model_Codegen_Rewriter::strip_owned_regions($bare, 'Bare_Model'),
            Model_Codegen_Rewriter::strip_owned_regions($result, 'Bare_Model')
        );
    }

    // -------------------------------------------------------------------------
    // Old-format constants_regenerate fence (B2) is MIGRATED in one pass, never duplicated.
    // A B2 fence is the old-format home for enum constants; a fresh B1 must REPLACE it, not
    // stack beside it (which declared every constant twice - the parse fatal this fixes).
    // See docs.dev/external_requests/2026_07_15_document_models_regen_clobbers_class_top.md
    // (UPDATE 2026-07-15).
    // -------------------------------------------------------------------------

    /**
     * The exact production shape emitted by rsx:constants:regenerate: enum constants live in
     * an old-format __AUTO_GENERATED fence (the B2 pair), with no canonical B1 block and
     * hand-written members around it.
     */
    protected static function __old_format_fixture(): string
    {
        return <<<'EOF'
<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
/**
 * _AUTO_GENERATED_
 * @property integer $id
 * @method static mixed status_enum()
 * @property-read mixed $status_label
 * @mixin \Eloquent
 */
class Widget_Model extends Rsx_Site_Model_Abstract
{
    /** __AUTO_GENERATED: */
    const STATUS_ACTIVE = 1;
    const STATUS_DONE = 2;
    /** __/AUTO_GENERATED */

    // HANDWRITTEN realtime block sits after the old-format fence.
    public static $realtime = true;

    protected $table = 'widgets';

    protected static $type_ref_columns = ['thing_type'];

    public static $enums = [
        'status' => [
            1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],
            2 => ['constant' => 'STATUS_DONE', 'label' => 'Done'],
        ],
    ];

    // HANDWRITTEN trailing method
    public function hello()
    {
        return 'world';
    }
}
EOF;
    }

    public static function test_old_format_fence_is_migrated_not_duplicated()
    {
        $result = Model_Codegen_Rewriter::rewrite(
            static::__old_format_fixture(),
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        // Exactly ONE set of constants, in the canonical (B1) format.
        static::__assert_equals(1, substr_count($result, 'const STATUS_ACTIVE = 1;'), 'STATUS_ACTIVE was duplicated');
        static::__assert_equals(1, substr_count($result, 'const STATUS_DONE = 2;'), 'STATUS_DONE was duplicated');
        static::__assert_contains('_AUTO_GENERATED_ Enum constants', $result);

        // The old-format B2 fence is gone (migrated into B1).
        static::__assert_true(!str_contains($result, '__AUTO_GENERATED:'), 'old-format fence open marker survived');
        static::__assert_true(!str_contains($result, '__/AUTO_GENERATED'), 'old-format fence close marker survived');

        // Every hand-written member is byte-identical (owned-region strip equality).
        static::__assert_contains('public static $realtime = true;', $result);
        static::__assert_contains("protected static \$type_ref_columns = ['thing_type'];", $result);
        static::__assert_contains('public function hello()', $result);
        static::__assert_equals(
            Model_Codegen_Rewriter::strip_owned_regions(static::__old_format_fixture(), 'Widget_Model'),
            Model_Codegen_Rewriter::strip_owned_regions($result, 'Widget_Model'),
            'Hand-written content changed during the migration rewrite'
        );

        // And the migrated file is valid PHP.
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        static::__assert_not_null($parser->parse($result), 'Migration produced unparseable PHP');
    }

    public static function test_old_format_migration_is_idempotent()
    {
        $once = Model_Codegen_Rewriter::rewrite(
            static::__old_format_fixture(),
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );
        // Second pass runs against the already-migrated (canonical B1) output.
        $twice = Model_Codegen_Rewriter::rewrite(
            $once,
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        static::__assert_equals($once, $twice, 'Migrated output is not idempotent on a second pass');
    }

    public static function test_mixed_stale_b1_and_old_format_fence_consolidates_to_one_block()
    {
        // The doubly-corrupt case: a stale canonical B1 AND a old-format consts fence both present.
        $mixed = <<<'EOF'
<?php
namespace Rsx\Models;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
/**
 * _AUTO_GENERATED_ Database type hints
 * @property int $id
 * @mixin \Eloquent
 */
class Widget_Model extends Rsx_Site_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_STALE = 9;

    /** __AUTO_GENERATED: */
    const STATUS_ACTIVE = 1;
    const STATUS_DONE = 2;
    /** __/AUTO_GENERATED */

    public static $realtime = true;
    protected $table = 'widgets';
    public static $enums = [];
}
EOF;
        $result = Model_Codegen_Rewriter::rewrite(
            $mixed,
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        static::__assert_equals(1, substr_count($result, 'const STATUS_ACTIVE = 1;'), 'STATUS_ACTIVE duplicated in mixed case');
        static::__assert_equals(1, substr_count($result, 'const STATUS_DONE = 2;'), 'STATUS_DONE duplicated in mixed case');
        static::__assert_true(!str_contains($result, 'STATUS_STALE'), 'stale B1 const survived');
        static::__assert_true(!str_contains($result, '__AUTO_GENERATED:'), 'old-format fence survived in mixed case');
        static::__assert_contains('public static $realtime = true;', $result);

        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        static::__assert_not_null($parser->parse($result), 'Mixed-case rewrite produced unparseable PHP');
    }

    public static function test_empty_old_format_fence_is_left_untouched()
    {
        // A model already migrated to B1 keeps an inert empty fence (as the template app does):
        // removing it would churn the file for no gain. The rewrite must leave it in place.
        $with_empty_fence = <<<'EOF'
<?php
namespace Rsx\Models;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
/**
 * _AUTO_GENERATED_ Database type hints
 * @property int $id
 * @mixin \Eloquent
 */
class Widget_Model extends Rsx_Site_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_ACTIVE = 1;
    const STATUS_DONE = 2;


    /** __AUTO_GENERATED: */

    /** __/AUTO_GENERATED */

    protected $table = 'widgets';
    public static $enums = [];
}
EOF;
        $result = Model_Codegen_Rewriter::rewrite(
            $with_empty_fence,
            'Widget_Model',
            static::__new_doc_block(),
            static::__new_constants_block()
        );

        static::__assert_contains('/** __AUTO_GENERATED: */', $result);
        static::__assert_contains('/** __/AUTO_GENERATED */', $result);
        static::__assert_equals(1, substr_count($result, 'const STATUS_ACTIVE = 1;'), 'STATUS_ACTIVE duplicated with empty fence present');
    }

    public static function test_fence_with_unclassifiable_content_fails_loud()
    {
        // A fence holding a hand-written member (not an enum const) cannot be classified;
        // the rewrite must fail loud and skip the file rather than guess at its ownership.
        $unknown = <<<'EOF'
<?php
namespace Rsx\Models;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
class Widget_Model extends Rsx_Site_Model_Abstract
{
    /** __AUTO_GENERATED: */
    const STATUS_ACTIVE = 1;
    public $handwritten_inside_fence = 5;
    /** __/AUTO_GENERATED */
    protected $table = 'widgets';
}
EOF;
        static::__assert_throws(
            \RuntimeException::class,
            fn() => Model_Codegen_Rewriter::rewrite(
                $unknown,
                'Widget_Model',
                static::__new_doc_block(),
                static::__new_constants_block()
            ),
            'cannot be classified'
        );
    }

    public static function test_parse_guard_refuses_a_duplicate_constant_candidate()
    {
        // The parse-guard is the mechanical backstop for the whole duplicate-const bug class:
        // a constants block that itself declares a name twice passes the (owned-region)
        // self-check but must be refused because the written file would fatal.
        $src = <<<'EOF'
<?php
namespace Rsx\Models;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
class Widget_Model extends Rsx_Site_Model_Abstract
{
    public static $realtime = true;
    protected $table = 'widgets';
}
EOF;
        $doc = "/**\n * _AUTO_GENERATED_ Database type hints\n * @mixin \\Eloquent\n */";
        $dup_cb = "    /**\n     * _AUTO_GENERATED_ Enum constants\n     */\n    const DUP = 1;\n    const DUP = 2;\n";

        static::__assert_throws(
            \RuntimeException::class,
            fn() => Model_Codegen_Rewriter::rewrite($src, 'Widget_Model', $doc, $dup_cb),
            'Parse-guard FAILED'
        );
    }

    // -------------------------------------------------------------------------
    // strip_owned_regions unit behavior
    // -------------------------------------------------------------------------

    public static function test_strip_removes_every_marker_family()
    {
        $stripped = Model_Codegen_Rewriter::strip_owned_regions(static::__fixture(), 'Widget_Model');

        static::__assert_true(!str_contains($stripped, '_AUTO_GENERATED_'), 'strip left an _AUTO_GENERATED_ marker');
        static::__assert_contains('public static $realtime = true;', $stripped);
        static::__assert_contains('public function hello()', $stripped);
    }

    public static function test_strip_of_model_with_no_fences_returns_whole_source()
    {
        $plain = <<<'EOF'
<?php
namespace Rsx\Models;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
class Plain_Model extends Rsx_Site_Model_Abstract
{
    protected $table = 'plains';
    public static $enums = [];
}
EOF;
        $stripped = Model_Codegen_Rewriter::strip_owned_regions($plain, 'Plain_Model');
        static::__assert_equals($plain, $stripped, 'strip altered a model that has no owned regions');
    }

    // -------------------------------------------------------------------------
    // Requirement 2: fail loud on structural surprises
    // -------------------------------------------------------------------------

    public static function test_duplicate_pre_class_docblocks_fail_loud()
    {
        $dup = <<<'EOF'
<?php
namespace Rsx\Models;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
/**
 * _AUTO_GENERATED_ something
 * @property int $id
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * @property int $id
 */
class Dup_Model extends Rsx_Site_Model_Abstract
{
    protected $table = 'dups';
    public static $enums = [];
}
EOF;
        static::__assert_throws(
            \RuntimeException::class,
            fn() => Model_Codegen_Rewriter::strip_owned_regions($dup, 'Dup_Model'),
            'auto-generated docblocks'
        );
    }

    public static function test_unbalanced_fence_markers_fail_loud()
    {
        $unbalanced = <<<'EOF'
<?php
namespace Rsx\Models;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

class Bad_Model extends Rsx_Site_Model_Abstract
{
    /** __AUTO_GENERATED: */
    const X = 1;
    protected $table = 'bad';
}
EOF;
        static::__assert_throws(
            \RuntimeException::class,
            fn() => Model_Codegen_Rewriter::strip_owned_regions($unbalanced, 'Bad_Model'),
            'unbalanced'
        );
    }

    public static function test_missing_class_fails_loud()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => Model_Codegen_Rewriter::strip_owned_regions(static::__fixture(), 'Nonexistent_Model'),
            'Could not find class'
        );
    }

    public static function test_parse_error_fails_loud()
    {
        $broken = "<?php namespace Rsx\\Models; class Broken_Model { public function x( { } }";
        static::__assert_throws(
            \RuntimeException::class,
            fn() => Model_Codegen_Rewriter::strip_owned_regions($broken, 'Broken_Model')
        );
    }

    // -------------------------------------------------------------------------
    // Requirement 3: the self-check refuses a write that would lose hand-written code
    // -------------------------------------------------------------------------

    public static function test_self_check_aborts_when_a_handwritten_line_is_dropped()
    {
        // Simulate a broken rewrite via a seam: a candidate identical to the original except
        // the hand-written $realtime line has been dropped. The self-check must refuse it.
        $original = static::__fixture();
        $broken_candidate = str_replace(
            "    public static \$realtime = true;\n",
            '',
            $original
        );

        static::__assert_throws(
            \RuntimeException::class,
            fn() => Model_Codegen_Rewriter::assert_no_handwritten_change($original, $broken_candidate, 'Widget_Model'),
            'Self-check FAILED'
        );
    }

    public static function test_self_check_passes_for_identical_handwritten_content()
    {
        // A no-op candidate must pass (no throw). __assert_throws would fail if this threw.
        Model_Codegen_Rewriter::assert_no_handwritten_change(static::__fixture(), static::__fixture(), 'Widget_Model');
        static::__assert_true(true);
    }
}
