<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Exception;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\Document_Sandbox;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Search\Pdftotext_Text_Extractor;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The document sandbox: the ONE seam that decides whether soffice and pdftotext run on the host
 * or inside a throwaway container, the health rows that prove the chain, and - where a docker
 * daemon is reachable - a real document rendered and a real PDF extracted through it.
 *
 * The unit half forces the mode with config() and restores it in a finally, so it runs identically
 * on a box with no docker at all. The integration half skips when this host cannot run a hardened
 * container - no daemon (the normal case inside the sibling test containers, which have no docker
 * of their own), no image, or a daemon that refuses one of the hardening flags.
 */
class Document_Sandbox_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    public static function setup()
    {
        // The pipeline kick switch: creating an attachment records its state but never spawns a
        // detached worker - the integration test drives render_storage() directly.
        config(['rsx.search.enabled' => false]);
    }

    public static function teardown()
    {
        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        foreach (static::$created_files as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        static::$created_files = [];

        config(['rsx.search.enabled' => true]);
    }

    /**
     * Run $fn with the sandbox configured this way, restoring every key afterwards.
     *
     * @param array $overrides
     * @param callable $fn
     * @return mixed
     */
    private static function __with_config(array $overrides, callable $fn)
    {
        $keys = ['rsx.libreoffice.sandbox', 'rsx.libreoffice.sandbox_image', 'rsx.libreoffice.sandbox_memory', 'rsx.libreoffice.sandbox_pids'];

        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = config($key);
        }

        config($overrides);

        try {
            return $fn();
        } finally {
            config($previous);
        }
    }

    /**
     * Can this box actually run a sandboxed conversion, and if not, why not?
     *
     * Three things have to hold and each fails for its own reason: a reachable daemon (the
     * sibling test containers have no docker at all), the configured image, and a container that
     * the daemon will START WITH THE HARDENING FLAGS. A host that refuses the memory cap is not
     * one of them: the seam probes for that and drops the cap, so the flags this asks for are
     * exactly the flags a conversion would get here.
     *
     * @return string|null null when the sandbox works here; otherwise the reason to skip.
     */
    private static function __sandbox_unusable_reason(): ?string
    {
        $output = [];
        $exit_code = 0;
        exec_safe('docker info > /dev/null 2>&1', $output, $exit_code);
        if ($exit_code !== 0) {
            return 'no docker daemon is reachable from here';
        }

        $image = Document_Sandbox::image();

        $output = [];
        $exit_code = 0;
        exec_safe('docker image inspect ' . escapeshellarg($image) . ' > /dev/null 2>&1', $output, $exit_code);
        if ($exit_code !== 0) {
            return 'the sandbox image ' . $image . ' is not present on this host';
        }

        $flags = '';
        foreach (Document_Sandbox::hardening_flags() as $flag) {
            $flags .= ' ' . escapeshellarg($flag);
        }

        $output = [];
        $exit_code = 0;
        exec_safe(
            'docker run --rm' . $flags . ' --entrypoint ' . escapeshellarg('')
            . ' ' . escapeshellarg($image) . ' true 2>&1',
            $output,
            $exit_code
        );
        if ($exit_code !== 0) {
            return 'this host will not start a container with the hardening flags: ' . trim(implode(' ', $output));
        }

        return null;
    }

    // ============================================================================================
    // THE COMMAND SEAM
    // ============================================================================================

    // DOCUMENTS-SANDBOX-NONE-PASSTHROUGH: the default posture adds nothing at all. The argv the
    // callers build is the argv that is spawned, so 'none' is not a second code path.
    public static function test_none_mode_returns_the_argv_unchanged()
    {
        $argv = ['/usr/bin/soffice', '--headless', '--convert-to', 'pdf', '--outdir', '/tmp/wd', '/tmp/wd/source.docx'];

        $command = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'none'],
            static fn () => Document_Sandbox::command($argv, '/tmp/wd')
        );

        static::__assert_equals($argv, $command, 'none mode spawns exactly what the caller built');
        static::__assert_null(Document_Sandbox::container_name($command), 'an unsandboxed argv names no container');
    }

    // DOCUMENTS-SANDBOX-DOCKER-WRAP: every hardening flag, the work dir mounted at its OWN
    // absolute path, the worker's own uid:gid, and the caller's argv appended verbatim.
    public static function test_docker_mode_wraps_the_argv_with_every_hardening_flag()
    {
        $work_dir = '/var/www/html/tmp/rsx_sandbox_test_dir';
        $argv = ['soffice', '-env:UserInstallation=file://' . $work_dir . '/profile', '--headless', '--convert-to', 'pdf', '--outdir', $work_dir, $work_dir . '/source.docx'];

        // The daemon's answer on the memory cap is forced: this test is about the wrapper's
        // shape, not about this box's cgroup layout.
        Document_Sandbox::_testing_set_memory_cap(true);
        // The image is a fiction here - this test is about the wrapper's shape, and
        // ensure_image() would otherwise refuse to run a container on an image nobody has.
        Document_Sandbox::_testing_set_image_present(true);
        try {
            $command = static::__with_config([
                'rsx.libreoffice.sandbox' => 'docker',
                'rsx.libreoffice.sandbox_image' => 'example/image:tag',
                'rsx.libreoffice.sandbox_memory' => '1g',
                'rsx.libreoffice.sandbox_pids' => 256,
            ], static fn () => Document_Sandbox::command($argv, $work_dir));
        } finally {
            Document_Sandbox::_testing_set_memory_cap(null);
            Document_Sandbox::_testing_set_image_present(null);
        }

        static::__assert_equals('docker', $command[0], 'the sandbox spawns the docker client');
        static::__assert_equals(['run', '--rm'], [$command[1], $command[2]], 'a throwaway container, one per file');

        // Every flag, as an adjacent pair, so a flag silently losing its value is a failure.
        $pairs = [
            ['--network', 'none'],
            ['--cap-drop', 'ALL'],
            ['--security-opt', 'no-new-privileges'],
            ['--memory', '1g'],
            ['--pids-limit', '256'],
            ['--tmpfs', '/tmp'],
            ['--user', posix_getuid() . ':' . posix_getgid()],
            ['-e', 'HOME=' . $work_dir],
            // THE MOUNT: the work dir at its own absolute path, so every path in the argv is
            // valid unchanged inside the container.
            ['-v', $work_dir . ':' . $work_dir],
            // The image's own ENTRYPOINT is cleared, so the argv IS the process.
            ['--entrypoint', ''],
        ];

        foreach ($pairs as $pair) {
            $index = array_search($pair[0], $command, true);
            static::__assert_true($index !== false, $pair[0] . ' is passed to docker run');
            static::__assert_equals($pair[1], $command[$index + 1], $pair[0] . ' carries its value');
        }

        static::__assert_true(in_array('--read-only', $command, true), 'the container root filesystem is read-only');

        // Nothing but the work dir is mounted - a storage-tree mount would defeat the sandbox.
        static::__assert_count(1, array_keys($command, '-v', true), 'exactly one bind mount');

        // The argv is appended VERBATIM, after the image.
        $image_index = array_search('example/image:tag', $command, true);
        static::__assert_true($image_index !== false, 'the configured image is what runs');
        static::__assert_equals(
            $argv,
            array_slice($command, $image_index + 1),
            'the caller argv is appended verbatim after the image'
        );

        $name = Document_Sandbox::container_name($command);
        static::__assert_not_null($name, 'the container is named, so the timeout path has something to kill');
        static::__assert_true(str_starts_with((string) $name, 'rsx-doc-'), 'sandbox containers are recognisable: ' . $name);
        Document_Sandbox::_testing_set_image_present(true);
        try {
            $second = Document_Sandbox::container_name(static::__with_config(
                ['rsx.libreoffice.sandbox' => 'docker', 'rsx.libreoffice.sandbox_image' => 'example/image:tag'],
                static fn () => Document_Sandbox::command($argv, $work_dir)
            ));
        } finally {
            Document_Sandbox::_testing_set_image_present(null);
        }

        static::__assert_not_equals($name, $second, 'each invocation names its own container');
    }

    // DOCUMENTS-SANDBOX-INVALID-MODE: a typo in the deployment setting would otherwise silently
    // run untrusted documents on the host - the one outcome the operator was avoiding.
    /**
     * A daemon that refuses the memory cap gets every other flag and no --memory: the cap is
     * dropped, the sandbox is not.
     */
    public static function test_a_refused_memory_cap_is_dropped_and_nothing_else_is()
    {
        $work_dir = '/var/www/html/tmp/rsx_sandbox_test_dir';
        $argv = ['pdftotext', $work_dir . '/in.pdf', $work_dir . '/out.txt'];

        Document_Sandbox::_testing_set_memory_cap(false, 'cannot enter cgroupv2 with domain controllers');
        Document_Sandbox::_testing_set_image_present(true);
        try {
            $command = static::__with_config([
                'rsx.libreoffice.sandbox' => 'docker',
                'rsx.libreoffice.sandbox_image' => 'example/image:tag',
                'rsx.libreoffice.sandbox_memory' => '1g',
                'rsx.libreoffice.sandbox_pids' => 256,
            ], static fn () => Document_Sandbox::command($argv, $work_dir));
        } finally {
            Document_Sandbox::_testing_set_memory_cap(null);
            Document_Sandbox::_testing_set_image_present(null);
        }

        static::__assert_false(in_array('--memory', $command, true), 'the refused memory cap is dropped');
        foreach (['--network', '--read-only', '--cap-drop', '--security-opt', '--pids-limit', '--tmpfs', '--user'] as $flag) {
            static::__assert_true(in_array($flag, $command, true), $flag . ' is still applied');
        }
        static::__assert_equals($argv, array_slice($command, -count($argv)), 'the argv follows the image verbatim');
    }

    /** The memory-cap row: OK when applied, WARN with the daemon's words when refused, never FAIL. */
    public static function test_the_memory_cap_row_warns_when_refused()
    {
        $applied = Document_Sandbox::_docker_rows('example/image:tag', true, true, true, true, '', true, '');
        $refused = Document_Sandbox::_docker_rows('example/image:tag', true, true, true, true, '', false, 'cannot enter cgroupv2');

        $row_of = static function (array $rows): array {
            foreach ($rows as $row) {
                if ($row['label'] === 'Document Sandbox: memory cap') {
                    return $row;
                }
            }
            static::__fail('no memory cap row');
        };

        static::__assert_equals('OK', $row_of($applied)['status'], 'an applied cap is OK');
        static::__assert_equals('WARN', $row_of($refused)['status'], 'a refused cap is a WARN, not a FAIL - the sandbox is up');
        static::__assert_contains('cannot enter cgroupv2', $row_of($refused)['detail'], 'the row repeats what the daemon said');
        static::__assert_true(!in_array('FAIL', array_column($refused, 'status'), true), 'nothing in the chain is a FAIL for a refused cap alone');
    }

    public static function test_an_unknown_mode_throws_naming_the_two_values()
    {
        static::__with_config(['rsx.libreoffice.sandbox' => 'contianer'], static function () {
            $e = static::__assert_throws(
                Exception::class,
                static fn () => Document_Sandbox::command(['pdftotext'], '/tmp/wd'),
                "'contianer'"
            );

            static::__assert_contains("'none'", $e->getMessage(), 'the message names the first valid value');
            static::__assert_contains("'docker'", $e->getMessage(), 'the message names the second valid value');
        });
    }

    // ============================================================================================
    // THE HEALTH ROWS
    // ============================================================================================

    // DOCUMENTS-SANDBOX-POSTURE-ROW: no containment is a WARN on a deployed box and an INFO in
    // development, where it is the default.
    public static function test_the_posture_row_warns_on_a_sealed_box_and_informs_in_development()
    {
        foreach ([Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
            $row = Document_Sandbox::_posture_row($mode);
            static::__assert_equals('WARN', $row['status'], "no containment is reported on a {$mode} box");
            static::__assert_contains('LIBREOFFICE_SANDBOX=docker', $row['remediation'], 'the remediation names the switch');
        }

        $dev = Document_Sandbox::_posture_row(Rsx::MODE_DEVELOPMENT);
        static::__assert_equals('INFO', $dev['status'], 'the development default is not a finding');
        static::__assert_contains('none', $dev['detail'], 'the row says which posture this is');
    }

    // DOCUMENTS-SANDBOX-CHAIN-ROWS: four independent links, and ANY of them failing is a FAIL -
    // the exit code is what gates a deploy, which is the point of wiring this to rsx:health.
    public static function test_the_docker_rows_are_ok_only_when_the_whole_chain_is_proved()
    {
        $ok = Document_Sandbox::_docker_rows('example/image:tag', true, true, true, true, '', true);
        static::__assert_count(5, $ok, 'client, daemon, image, run, memory cap');
        foreach ($ok as $row) {
            static::__assert_equals('OK', $row['status'], $row['label'] . ' is OK when its probe succeeded');
        }

        // A link that failed, and every link after it that was therefore never reached.
        $broken = Document_Sandbox::_docker_rows('example/image:tag', true, false, null, null);
        static::__assert_equals('OK', $broken[0]['status'], 'the client was found');
        static::__assert_equals('FAIL', $broken[1]['status'], 'a daemon that cannot be reached gates the deploy');
        static::__assert_contains('docker socket', $broken[1]['remediation'], 'the remediation names the deployment requirement');
        static::__assert_equals('FAIL', $broken[2]['status'], 'a link that was never reached is not OK');
        static::__assert_contains('not probed', $broken[2]['detail'], 'and it says why it has no answer');
        static::__assert_equals('FAIL', $broken[3]['status'], 'nor is the run that proves the chain');

        // A missing image names the image and how to get it.
        $no_image = Document_Sandbox::_docker_rows('example/image:tag', true, true, false, null);
        static::__assert_equals('FAIL', $no_image[2]['status'], 'a missing image gates the deploy');
        static::__assert_contains('example/image:tag', $no_image[2]['detail'], 'the row names the image');
        static::__assert_contains('docker pull example/image:tag', $no_image[2]['remediation'], 'and how to get it');

        // The run row carries what the daemon said - a refused hardening flag IS the diagnosis.
        $refused = Document_Sandbox::_docker_rows('example/image:tag', true, true, true, false, 'cannot enter cgroupv2');
        static::__assert_equals('FAIL', $refused[3]['status'], 'a run that does not work gates the deploy');
        static::__assert_contains('cannot enter cgroupv2', $refused[3]['detail'], "the daemon's own words reach the operator");
    }

    // DOCUMENTS-SANDBOX-SOFFICE-ROW: under the sandbox, the soffice row is about the IMAGE. A
    // host-binary lookup there would FAIL a correctly-configured site that deliberately carries
    // no LibreOffice.
    public static function test_the_soffice_row_is_about_the_image_when_sandboxed()
    {
        $sandboxed = Libreoffice::_availability_row(true, true, 'example/image:tag', null);
        static::__assert_equals('INFO', $sandboxed['status'], 'a sandboxed box needs no host soffice');
        static::__assert_contains('example/image:tag', $sandboxed['detail'], 'the row names where soffice actually is');

        $host = Libreoffice::_availability_row(true, false, 'example/image:tag', '/usr/bin/soffice');
        static::__assert_equals('OK', $host['status'], 'unsandboxed, the host binary is the answer');

        $missing = Libreoffice::_availability_row(true, false, 'example/image:tag', null);
        static::__assert_equals('FAIL', $missing['status'], 'unsandboxed and absent is still a FAIL');

        $disabled = Libreoffice::_availability_row(false, false, 'example/image:tag', null);
        static::__assert_equals('INFO', $disabled['status'], 'a box that renders nothing needs no soffice');
    }

    // ============================================================================================
    // THE CONVERTER IMAGE
    // ============================================================================================

    // DOCUMENTS-SANDBOX-FRAMEWORK-IMAGE: which image the framework is entitled to BUILD. The tag
    // is not part of the answer - a site pinning a release tag is still naming ours - and
    // anything else belongs to the operator, who distributes it.
    public static function test_the_framework_converter_image_is_recognised_at_any_tag()
    {
        static::__assert_true(
            Document_Sandbox::is_framework_image('rspade/rspade-docconvert:latest'),
            'the default image is the framework image'
        );
        static::__assert_true(
            Document_Sandbox::is_framework_image('rspade/rspade-docconvert:2026.09'),
            'a pinned release tag is still the framework image'
        );
        static::__assert_true(
            Document_Sandbox::is_framework_image('rspade/rspade-docconvert'),
            'an untagged spelling is still the framework image'
        );
        static::__assert_false(
            Document_Sandbox::is_framework_image('example/image:tag'),
            "an operator's own image is not the framework's to build"
        );
        static::__assert_false(
            Document_Sandbox::is_framework_image('rspade/rspade-server-dev:latest'),
            'the application image is not the converter image'
        );
    }

    // DOCUMENTS-SANDBOX-IMAGE-ROW: the one row whose SEVERITY depends on whose image it is. An
    // absent FRAMEWORK image resolves itself on the first document, so it must not gate a
    // deploy; an absent operator image never resolves itself, so it must.
    public static function test_the_image_row_warns_for_the_framework_image_and_fails_for_anyone_elses()
    {
        $not_reached = 'not probed - an earlier link in the chain failed';

        $mine = Document_Sandbox::_image_row('rspade/rspade-docconvert:latest', false, $not_reached);
        static::__assert_equals('WARN', $mine['status'], 'the framework builds its own image on first use');
        static::__assert_contains('built on demand', $mine['detail'], 'the row says what happens next');
        static::__assert_contains('minute or two', $mine['detail'], 'and what the first conversion will cost');
        static::__assert_contains(
            'rsx:heal document-sandbox-image',
            $mine['remediation'],
            'and names the lever for an operator who would rather not pay that on the first document'
        );

        $theirs = Document_Sandbox::_image_row('example/image:tag', false, $not_reached);
        static::__assert_equals('FAIL', $theirs['status'], "an image nothing here can produce gates the deploy");
        static::__assert_contains('example/image:tag', $theirs['detail'], 'the row names the image');
        static::__assert_contains('docker pull example/image:tag', $theirs['remediation'], 'and how to get it');

        $present = Document_Sandbox::_image_row('rspade/rspade-docconvert:latest', true, $not_reached);
        static::__assert_equals('OK', $present['status'], 'a present image is a present image');

        // Never reached is still not OK, whoever owns the image.
        foreach (['rspade/rspade-docconvert:latest', 'example/image:tag'] as $image) {
            $row = Document_Sandbox::_image_row($image, null, $not_reached);
            static::__assert_equals('FAIL', $row['status'], 'a link that was never reached is not OK');
            static::__assert_contains('not probed', $row['detail'], 'and it says why it has no answer');
        }
    }

    // DOCUMENTS-SANDBOX-ENSURE-IMAGE-MEMO: ensure_image() is called on EVERY sandboxed
    // invocation, so a known-present image must cost nothing - no inspect, no build. The seam
    // proves the short circuit by naming an image no host has.
    public static function test_ensure_image_short_circuits_when_the_image_is_known_present()
    {
        Document_Sandbox::_testing_set_image_present(true);
        try {
            static::__with_config(
                ['rsx.libreoffice.sandbox' => 'docker', 'rsx.libreoffice.sandbox_image' => 'example/image:tag'],
                static function () {
                    Document_Sandbox::ensure_image();
                    static::__pass('a memoized image is never inspected or built again');
                }
            );
        } finally {
            Document_Sandbox::_testing_set_image_present(null);
        }
    }

    // ============================================================================================
    // THE REAL CONTAINER
    // ============================================================================================

    // DOCUMENTS-SANDBOX-REAL-RENDER: end to end through the sandbox - a real .docx renders inside
    // a throwaway container, a real PDF is extracted by pdftotext inside another, and no work dir
    // and no container is left behind.
    public static function test_a_real_document_renders_and_extracts_inside_the_sandbox()
    {
        $unusable = static::__sandbox_unusable_reason();
        if ($unusable !== null) {
            static::__skip($unusable);
            return;
        }

        $sample = rsx_project_file_path('rsx/resource/sample_documents/sample_memo.docx');
        if (!file_exists($sample)) {
            static::__skip('sample_memo.docx fixture not present');
            return;
        }

        static::__with_config(['rsx.libreoffice.sandbox' => 'docker'], static function () use ($sample) {
            $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
            Session::set_site_id($site_id);

            // Unique bytes, so this attachment gets its OWN blob rather than deduplicating onto
            // whatever state the baseline's copy of the sample document is in.
            $attachment = File_Attachment_Model::create_from_string(
                file_get_contents($sample) . str_repeat(' ', random_int(1, 64)),
                'sandboxed.docx',
                ['site_id' => $site_id]
            );
            static::$created_attachment_ids[] = $attachment->id;

            $storage = File_Storage_Model::find($attachment->file_storage_id);
            $storage->is_indexed = 0;
            $storage->render_status_id = File_Storage_Model::RENDER_STATUS_PENDING;
            $storage->save();

            Document_Render_Service::render_storage($storage);

            $reloaded = File_Storage_Model::find($storage->id);
            static::__assert_equals(
                File_Storage_Model::RENDER_STATUS_RENDERED,
                (int) $reloaded->render_status_id,
                'the document rendered inside the container: ' . (string) $reloaded->render_error
            );

            $rendition_path = File_Preview_Controller::rendition_cache_path($reloaded);
            static::$created_files[] = $rendition_path;
            static::__assert_true(file_exists($rendition_path), 'the PDF rendition is on disk');
            static::__assert_greater_than(0, filesize($rendition_path), 'the rendition has content');

            // The container ran as this process's uid:gid, so the worker can read what it wrote.
            static::__assert_true(is_readable($rendition_path), 'the rendition belongs to the worker, not to root');

            $index = Search_Index_Model::forModel('File_Storage_Model', $reloaded->id)->first();
            static::__assert_not_empty($index, 'an extraction row was written in the same pass');
            static::__assert_not_empty(trim((string) $index->content), 'pdftotext extracted text inside the container');

            // Nothing survives the file: no work dir, no container.
            static::__assert_empty(
                glob(sys_get_temp_dir() . '/rsx_soffice_pdf_*'),
                'the render work dir is gone'
            );
            static::__assert_empty(
                glob(sys_get_temp_dir() . '/rsx_pdftotext_*'),
                'the extraction work dir is gone'
            );

            $names = [];
            $exit_code = 0;
            exec_safe('docker ps -a --format ' . escapeshellarg('{{.Names}}') . ' 2>/dev/null', $names, $exit_code);
            foreach ($names as $name) {
                static::__assert_false(
                    str_starts_with(trim($name), 'rsx-doc-'),
                    'no sandbox container is left behind: ' . $name
                );
            }
        });
    }

    // DOCUMENTS-SANDBOX-REAL-PDFTOTEXT: the pdftotext extractor on its own, sandboxed, over a
    // file it is given directly - it stages the input into the work dir rather than asking the
    // container to see the storage tree.
    public static function test_pdftotext_extracts_inside_the_sandbox()
    {
        $unusable = static::__sandbox_unusable_reason();
        if ($unusable !== null) {
            static::__skip($unusable);
            return;
        }

        $sample = rsx_project_file_path('rsx/resource/sample_documents/sample_report.pdf');
        if (!file_exists($sample)) {
            static::__skip('sample_report.pdf fixture not present');
            return;
        }

        $text = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'docker'],
            static fn () => Pdftotext_Text_Extractor::extract($sample, 'application/pdf')
        );

        static::__assert_not_empty(trim($text), 'the PDF text layer came back from inside the container');
        static::__assert_empty(glob(sys_get_temp_dir() . '/rsx_pdftotext_*'), 'the work dir is gone');
    }
}
