<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\UrlFetch\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Support\UrlFetch\Rsx_Url_Fetch;
use App\RSpade\Lib\Support\UrlFetch\Rsx_Url_Fetch_Exception;
use App\RSpade\Tests\UrlFetch\Php\Url_Fetch_Loopback_Public_Fixture;

/**
 * Rsx_Url_Fetch::to_temp_file() against a local fixture server (php -S over
 * resource/fixture_router.php on a free loopback port), and
 * File_Attachment_Model::create_from_url() end to end.
 *
 * The fixture is on loopback, so a fetch that must REACH it passes allow_private: true, and
 * the redirect-to-private case goes through Url_Fetch_Loopback_Public_Fixture, which counts
 * 127.0.0.1 as public and leaves every other address to the shipped classifier.
 *
 * Each test starts and stops its own server. Default per-test transaction (the
 * create_from_url rows roll back; the blob store is the run's isolated one).
 */
class Url_Fetch_Test extends Rsx_Test_Abstract
{
    private static ?Process $server = null;

    private static string $base = '';

    // ---------------------------------------------------------------------------------
    // The destination policy, live
    // ---------------------------------------------------------------------------------

    public static function test_loopback_url_is_refused_before_any_connection()
    {
        static::__with_server(function () {
            $before = static::__temp_files();
            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => Rsx_Url_Fetch::to_temp_file(static::$base . '/named'), 'loopback');
            static::__assert_equals(Rsx_Url_Fetch_Exception::REFUSED_DESTINATION, $e->reason);
            static::__assert_contains('127.0.0.1', $e->getMessage(), 'the refusal names the host');
            static::__assert_equals($before, static::__temp_files(), 'no temp file is left behind');
        });
    }

    public static function test_redirect_from_an_allowed_host_to_a_private_one_is_refused()
    {
        static::__with_server(function () {
            // Proven reachable first: the same fixture class fetches a non-redirecting URL.
            $direct = Url_Fetch_Loopback_Public_Fixture::to_temp_file(static::$base . '/named');
            $direct->delete();

            $url = static::$base . '/redirect?to=' . rawurlencode('http://169.254.169.254/latest/meta-data/');
            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => Url_Fetch_Loopback_Public_Fixture::to_temp_file($url), 'link-local');
            static::__assert_equals(Rsx_Url_Fetch_Exception::REFUSED_DESTINATION, $e->reason);
            static::__assert_contains('169.254.169.254', $e->getMessage());
        });
    }

    public static function test_non_http_schemes_are_refused()
    {
        foreach (['ftp://example.com/x', 'file:///etc/passwd', 'gopher://127.0.0.1:6379/', '/relative/path'] as $url) {
            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => Rsx_Url_Fetch::to_temp_file($url, 10, true));
            static::__assert_equals(Rsx_Url_Fetch_Exception::INVALID_URL, $e->reason, $url);
        }
    }

    // ---------------------------------------------------------------------------------
    // The byte cap
    // ---------------------------------------------------------------------------------

    public static function test_declared_content_length_over_the_cap_is_refused_up_front()
    {
        static::__with_server(function () {
            $before = static::__temp_files();
            // The body is small; only the declared length is over - so the refusal can only
            // have come from the header.
            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => Rsx_Url_Fetch::to_temp_file(static::$base . '/lying?n=10&claim=5000', 100, true), 'declares 5000 bytes');
            static::__assert_equals(Rsx_Url_Fetch_Exception::TOO_LARGE, $e->reason);
            static::__assert_equals($before, static::__temp_files(), 'the temp file is deleted');
        });
    }

    public static function test_body_over_the_cap_aborts_mid_stream_and_deletes_the_temp_file()
    {
        static::__with_server(function () {
            $before = static::__temp_files();
            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => Rsx_Url_Fetch::to_temp_file(static::$base . '/stream?n=400000', 100000, true), 'passed the 100000 bytes');
            static::__assert_equals(Rsx_Url_Fetch_Exception::TOO_LARGE, $e->reason);
            static::__assert_equals($before, static::__temp_files(), 'the temp file is deleted');

            // At the cap exactly is allowed.
            $exact = Rsx_Url_Fetch::to_temp_file(static::$base . '/stream?n=100000', 100000, true);
            static::__assert_equals(100000, $exact->size);
            $exact->delete();
        });
    }

    // ---------------------------------------------------------------------------------
    // Responses
    // ---------------------------------------------------------------------------------

    public static function test_result_carries_path_type_name_and_final_url_across_a_redirect()
    {
        static::__with_server(function () {
            $result = Rsx_Url_Fetch::to_temp_file(static::$base . '/redirect?to=/named', 1024, true);
            try {
                static::__assert_equals("a,b\n1,2\n", file_get_contents($result->path));
                static::__assert_equals('report.csv', $result->filename, 'the Content-Disposition name');
                static::__assert_true(str_starts_with((string) $result->content_type, 'text/csv'), 'the response content type');
                static::__assert_equals(static::$base . '/named', $result->final_url, 'the relative Location was resolved');
                static::__assert_equals(8, $result->size);
            } finally {
                $result->delete();
            }
            static::__assert_false(file_exists($result->path), 'delete() removes the file');
        });
    }

    public static function test_http_error_status_and_endless_redirects_fail_loudly()
    {
        static::__with_server(function () {
            $before = static::__temp_files();
            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => Rsx_Url_Fetch::to_temp_file(static::$base . '/status?code=404', 1024, true), 'HTTP 404');
            static::__assert_equals(404, $e->http_status);

            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => Rsx_Url_Fetch::to_temp_file(static::$base . '/loop', 1024, true), Rsx_Url_Fetch::MAX_REDIRECTS . ' redirects');
            static::__assert_equals(Rsx_Url_Fetch_Exception::TOO_MANY_REDIRECTS, $e->reason);
            static::__assert_equals($before, static::__temp_files(), 'no temp file is left behind');
        });
    }

    public static function test_temp_file_left_in_place_is_deleted_when_the_process_ends()
    {
        static::__with_server(function () {
            $process = new Process(['php', dirname(__DIR__) . '/resource/fetch_and_exit.php', static::$base . '/named']);
            $process->setTimeout(null);
            $process->run();
            static::__assert_equals(0, $process->getExitCode(), 'the fetching process failed: ' . $process->getErrorOutput() . $process->getOutput());

            [$path, $existed] = explode(' ', trim($process->getOutput()));
            static::__assert_equals('1', $existed, 'the temp file existed while the process ran');
            static::__assert_false(file_exists($path), "the temp file {$path} was deleted at process end");
        });
    }

    // ---------------------------------------------------------------------------------
    // create_from_url()
    // ---------------------------------------------------------------------------------

    public static function test_create_from_url_files_the_download_and_leaves_no_temp_file()
    {
        static::__with_server(function () {
            static::__acting_as_site(1);
            $before = static::__temp_files();

            $attachment = File_Attachment_Model::create_from_url(static::$base . '/pixel.png', ['site_id' => 1, 'allow_private' => true]);
            static::__assert_equals('pixel.png', $attachment->file_name);
            static::__assert_equals('image/png', $attachment->mime_type);
            static::__assert_greater_than(0, (int) $attachment->file_size);
            static::__assert_equals($before, static::__temp_files(), 'the downloaded temp file is gone');

            $named = File_Attachment_Model::create_from_url(static::$base . '/named', ['site_id' => 1, 'allow_private' => true, 'filename' => 'chosen.csv']);
            static::__assert_equals('chosen.csv', $named->file_name, 'an explicit filename wins');
        });
    }

    public static function test_create_from_url_refuses_a_private_destination_and_stores_nothing()
    {
        static::__with_server(function () {
            static::__acting_as_site(1);
            $count = File_Attachment_Model::count();

            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => File_Attachment_Model::create_from_url(static::$base . '/pixel.png', ['site_id' => 1]));
            static::__assert_equals(Rsx_Url_Fetch_Exception::REFUSED_DESTINATION, $e->reason);
            static::__assert_equals($count, File_Attachment_Model::count(), 'no attachment row');

            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => File_Attachment_Model::create_from_url(static::$base . '/bytes?n=500', ['site_id' => 1, 'allow_private' => true, 'max_bytes' => 100]));
            static::__assert_equals(Rsx_Url_Fetch_Exception::TOO_LARGE, $e->reason, 'max_bytes reaches the fetch');
        });
    }

    // ---------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------

    /** The files in the fetch directory now (sorted), for before/after comparisons. */
    private static function __temp_files(): array
    {
        $files = glob(Rsx_Project_Paths::url_fetch_dir() . '/*') ?: [];
        sort($files);

        return $files;
    }

    /** Run $body with the fixture server up at static::$base, and stop it afterwards. */
    private static function __with_server(callable $body): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        static::$base = 'http://127.0.0.1:' . $port;
        static::$server = new Process(['php', '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/resource/fixture_router.php']);
        static::$server->setTimeout(null);
        static::$server->start();

        try {
            static::__wait_for_port($port);
            $body();
        } finally {
            if (static::$server->isRunning()) {
                static::$server->signal(SIGTERM);
                static::$server->wait();
            }
            static::$server = null;
        }
    }

    /**
     * Wait until the fixture server accepts connections. php -S is listening within about
     * 0.1 s on an idle box; the bound is 100x a 2 s idle allowance (the house default), and
     * exists only to fail a server that never comes up - by name, never by elapsed time.
     */
    private static function __wait_for_port(int $port): void
    {
        $deadline = microtime(true) + 200;
        do {
            if (!static::$server->isRunning()) {
                static::__fail('the fixture server (php -S on port ' . $port . ') exited: ' . static::$server->getErrorOutput());
            }
            $connection = @stream_socket_client('tcp://127.0.0.1:' . $port);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        static::__fail("the fixture server never accepted a connection on 127.0.0.1:{$port}");
    }
}
