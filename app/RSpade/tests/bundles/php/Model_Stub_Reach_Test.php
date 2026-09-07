<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Bundles\Php;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A JS model class carries its generated Base_ stub into every bundle it reaches.
 *
 * THE DEFECT THIS PINS. A model's generated Base_<Model> stub was emitted only at the
 * include position of the PHP MODEL FILE, but the hand-written JS model class arrives in
 * a bundle by CLASS REFERENCE from anything that names it - a framework core component
 * naming File_Attachment_Model was the field case. Bundles that happened to include a
 * models directory got the stub by side effect of that unrelated include; bundles that
 * did not (a login bundle; a framework-owned bundle that by CONV-BUNDLE-04 may include no
 * rsx/ path at all) evaluated `class X_Model extends Base_X_Model` with Base_X_Model
 * undefined - an UNCAUGHT ReferenceError during bundle evaluation, so NO client component
 * in that bundle booted. A class-overridden model made it worse: moving the PHP file into
 * rsx/models moved the stub's only emission point out of every framework bundle, and no
 * application-side remedy existed for a framework-owned bundle.
 *
 * The resolution is structural: the stub follows the class that EXTENDS it. These tests
 * drive that seam directly with a synthetic file set and manifest index - no bundle
 * compile - so the mechanism is proven for a file set the template app cannot produce.
 */
class Model_Stub_Reach_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // A JS model class file, present in the bundle by class reference. Its PHP model file
    // is deliberately absent from the set - that IS the field case.
    private const JS_MODEL = 'app/RSpade/Core/Files/File_Attachment_Model.js';

    // A stub path (relative to the project root) guaranteed not to exist on disk.
    private const MISSING_STUB = 'storage/rsx-build/js-model-stubs/base-does-not-exist-xyzzy-model.js';

    /**
     * BUNDLE-STUB-01 - A JS model class alone in the file set pulls its stub in.
     */
    public static function test_js_model_class_pulls_its_stub_without_the_php_model()
    {
        $stub_rel = static::__make_temp_stub();

        try {
            $resolved = BundleCompiler::_get_model_stubs_for_js_classes(
                [base_path() . '/' . self::JS_MODEL],
                static::__manifest_index($stub_rel)
            );

            static::__assert_equals(
                [rsx_project_file_path($stub_rel)],
                $resolved,
                'a JS model class must carry its generated stub even when the PHP model file is in no bundle'
            );
        } finally {
            static::__unlink_temp_stub($stub_rel);
        }
    }

    /**
     * BUNDLE-STUB-02 - The stub is resolved once per stub, however many classes ask.
     *
     * The caller dedups against the positionally-emitted stubs by path, so a duplicate
     * here would be a second `class Base_X_Model` in the bundle - a redeclaration.
     */
    public static function test_a_stub_is_resolved_once()
    {
        $stub_rel = static::__make_temp_stub();

        try {
            $index = static::__manifest_index($stub_rel);
            $index['rsx/models/file_attachment_model.js'] = [
                'extension' => 'js',
                'class'     => 'File_Attachment_Model',
                'extends'   => 'Base_File_Attachment_Model',
            ];

            $resolved = BundleCompiler::_get_model_stubs_for_js_classes(
                [
                    base_path() . '/' . self::JS_MODEL,
                    base_path() . '/rsx/models/file_attachment_model.js',
                    rsx_project_file_path($stub_rel),
                ],
                $index
            );

            static::__assert_equals(1, count($resolved), 'the same stub must not be emitted twice');
        } finally {
            static::__unlink_temp_stub($stub_rel);
        }
    }

    /**
     * BUNDLE-STUB-03 - An extends clause that names no generated stub resolves nothing.
     *
     * Ordinary class inheritance is the overwhelming majority of the file set; the
     * mechanism must key on the stub REGISTRY, never on a name shape.
     */
    public static function test_a_non_model_class_resolves_nothing()
    {
        $stub_rel = static::__make_temp_stub();

        try {
            $index = static::__manifest_index($stub_rel);
            $index['rsx/app/frontend/thing.js'] = [
                'extension' => 'js',
                'class'     => 'Thing',
                'extends'   => 'Base_Thing_That_Is_Not_A_Model',
            ];

            $resolved = BundleCompiler::_get_model_stubs_for_js_classes(
                [base_path() . '/rsx/app/frontend/thing.js'],
                $index
            );

            static::__assert_equals([], $resolved, 'only a class extending a REGISTERED stub may pull one in');
        } finally {
            static::__unlink_temp_stub($stub_rel);
        }
    }

    /**
     * BUNDLE-STUB-04 - A registered stub missing from disk fails loud, naming the JS class
     * file that needs it.
     *
     * Silently skipping is the exact shape of the field bug this seam exists to prevent:
     * the bundle would compile "successfully" with the model layer dead.
     */
    public static function test_a_missing_stub_output_throws()
    {
        $index = [
            self::MISSING_STUB => [
                'extension'     => 'js',
                'class'         => 'Base_Does_Not_Exist_Xyzzy_Model',
                'is_model_stub' => true,
            ],
            'rsx/models/does_not_exist_xyzzy_model.js' => [
                'extension' => 'js',
                'class'     => 'Does_Not_Exist_Xyzzy_Model',
                'extends'   => 'Base_Does_Not_Exist_Xyzzy_Model',
            ],
        ];

        static::__assert_throws(\RuntimeException::class, function () use ($index) {
            BundleCompiler::_get_model_stubs_for_js_classes(
                [base_path() . '/rsx/models/does_not_exist_xyzzy_model.js'],
                $index
            );
        }, 'missing from disk');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    // The synthetic manifest index: one registered model stub plus the JS model class
    // that extends it. Mirrors what Database_BundleIntegration records for a real stub.
    private static function __manifest_index(string $stub_rel): array
    {
        return [
            $stub_rel => [
                'extension'     => 'js',
                'class'         => 'Base_File_Attachment_Model',
                'is_model_stub' => true,
                'source_model'  => 'app/RSpade/Core/Files/File_Attachment_Model.php',
            ],
            self::JS_MODEL => [
                'extension' => 'js',
                'class'     => 'File_Attachment_Model',
                'extends'   => 'Base_File_Attachment_Model',
            ],
        ];
    }

    // A real file under storage/rsx-tmp, addressed by its project-logical path (storage
    // lives at the project root, NOT under base_path()).
    private static function __make_temp_stub(): string
    {
        $rel = 'storage/rsx-tmp/model_stub_reach_test_' . uniqid() . '.js';
        file_put_contents(rsx_project_file_path($rel), "class Base_File_Attachment_Model {}\n");

        return $rel;
    }

    private static function __unlink_temp_stub(string $rel): void
    {
        $abs = rsx_project_file_path($rel);
        if (file_exists($abs)) {
            unlink($abs);
        }
    }
}
