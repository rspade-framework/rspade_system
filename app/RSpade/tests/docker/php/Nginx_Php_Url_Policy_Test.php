<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The container's `.php` URL policy and the image's copy of Proxority, as far as both can be
 * checked without building an image.
 *
 * Every `location ~ \.php$` in resource/docker/nginx/site.conf includes
 * /etc/nginx/rspade/php-urls.conf, which the entrypoint copies at start from one of two
 * variants: php-urls-checkout.conf (`return 404`, an RSpade checkout is mounted) or
 * php-urls-no-checkout.conf (`try_files $uri =404`, a bare docroot's .php files run). The site
 * configuration has to be valid nginx with EITHER variant in place, which is checked here
 * with `nginx -t` against a scratch tree - never the configuration of the box running the test.
 *
 * What a built container does with the variants - the requests that reach the proxy, the
 * scheme a bare .php file sees - is checked by hand (test catalog DOCKER-PROX-04, -05); the
 * checkout case on a live stack is Proxority_Live_Stack_Test's.
 */
class Nginx_Php_Url_Policy_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $root = '';

    public static function setup()
    {
        static::$root = sys_get_temp_dir() . '/rsx-php-url-policy-' . getmypid();
    }

    public static function teardown()
    {
        if (is_dir(static::$root)) {
            (new Process(['rm', '-rf', static::$root]))->run();
        }
    }

    private static function __docker_file(string $relative): string
    {
        return (string) file_get_contents(base_path('app/RSpade/resource/docker/' . $relative));
    }

    /** The configuration lines of a file: comments and blank lines dropped, each trimmed. */
    private static function __directives(string $text): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** DOCKER-PROX-01 - every .php location of every listener defers to the policy file */
    public static function test_every_php_location_includes_the_policy()
    {
        $site = static::__docker_file('nginx/site.conf');

        $count = preg_match_all('/location ~ \\\\\.php\$ \{\s*\n\s*(\S[^\n]*)/', $site, $matches);
        static::__assert_equals(4, $count, 'site.conf has one `location ~ \.php$` in each of :80, :8000, :3301 and :3302');
        foreach ($matches[1] as $first) {
            static::__assert_equals('include /etc/nginx/rspade/php-urls.conf;', trim($first), 'a .php location opens with the policy include, so the policy runs before its handler');
        }
    }

    /** DOCKER-PROX-02 - the two variants say what the policy says, and nothing else */
    public static function test_the_variants_refuse_or_serve()
    {
        static::__assert_equals(['return 404;'], static::__directives(static::__docker_file('nginx/php-urls-checkout.conf')), 'with a checkout every .php URL answers 404');
        static::__assert_equals(['try_files $uri =404;'], static::__directives(static::__docker_file('nginx/php-urls-no-checkout.conf')), 'without one a .php file that exists runs, one that does not is a 404');
    }

    /** DOCKER-PROX-03 - nginx accepts the site configuration with either variant in place */
    public static function test_nginx_accepts_the_site_with_either_variant()
    {
        $nginx = (new ExecutableFinder())->find('nginx', null, ['/usr/sbin', '/usr/local/sbin']);
        if ($nginx === null) {
            static::__skip('nginx is not installed on this box');
        }
        $modules = glob('/etc/nginx/modules-enabled/*.conf') ?: [];
        $brotli = array_filter($modules, fn ($file) => str_contains((string) file_get_contents($file), 'brotli'));
        if ($brotli === []) {
            static::__skip('this box\'s nginx has no brotli module, which site.conf uses');
        }

        $root = static::$root;
        mkdir($root, 0755, true);
        copy('/etc/nginx/fastcgi_params', $root . '/fastcgi_params');
        file_put_contents($root . '/site.conf', str_replace(
            '/etc/nginx/rspade/php-urls.conf',
            $root . '/php-urls.conf',
            static::__docker_file('nginx/site.conf')
        ));
        file_put_contents($root . '/nginx.conf', "pid {$root}/nginx.pid;\nerror_log {$root}/error.log;\n"
            . "include /etc/nginx/modules-enabled/*.conf;\n"
            . "events {}\nhttp {\n    include /etc/nginx/mime.types;\n    access_log off;\n    include {$root}/site.conf;\n}\n");

        foreach (['checkout', 'no-checkout'] as $variant) {
            copy(base_path('app/RSpade/resource/docker/nginx/php-urls-' . $variant . '.conf'), $root . '/php-urls.conf');

            // No -p: the module paths in modules-enabled are relative to nginx's own prefix, while a
            // relative include (fastcgi_params) resolves beside the -c file.
            $process = new Process([$nginx, '-t', '-c', $root . '/nginx.conf', '-e', $root . '/error.log']);
            $process->setTimeout(null);
            $process->run();

            static::__assert_equals(0, $process->getExitCode(), 'nginx -t with php-urls-' . $variant . '.conf: ' . trim($process->getErrorOutput()));
        }
    }

    /** DOCKER-PROX-06 - the image carries the variants, the checkout one active, and its own Proxority */
    public static function test_the_image_installs_the_variants_and_proxority()
    {
        $dockerfile = static::__docker_file('Dockerfile');
        $dev_stage = strpos($dockerfile, "\nFROM base AS dev");

        foreach ([
            'COPY nginx/php-urls-checkout.conf    /etc/nginx/rspade/php-urls-checkout.conf',
            'COPY nginx/php-urls-no-checkout.conf /etc/nginx/rspade/php-urls-no-checkout.conf',
            'COPY nginx/php-urls-checkout.conf    /etc/nginx/rspade/php-urls.conf',
            'COPY proxority/ /opt/rspade/proxority/',
            'COPY proxority-cli.sh /usr/local/bin/proxority',
        ] as $line) {
            $at = strpos($dockerfile, $line);
            static::__assert_true($at !== false && $dev_stage !== false && $at < $dev_stage, 'the base stage (dev and prod) has: ' . $line);
        }

        static::__assert_true(
            str_contains(static::__docker_file('supervisor/conf.d/proxority.conf'), "\ncommand=bash /opt/rspade/proxority/proxority-run.sh\n"),
            'supervisor runs the image\'s copy of Proxority, never one from the checkout'
        );

        $entrypoint = static::__docker_file('entrypoint.sh');
        static::__assert_true(
            preg_match('/if \[ "\$HAS_FRAMEWORK" = "1" \]; then\s*\n\s*cp -f \/etc\/nginx\/rspade\/php-urls-checkout\.conf \/etc\/nginx\/rspade\/php-urls\.conf\s*\n\s*else\s*\n\s*cp -f \/etc\/nginx\/rspade\/php-urls-no-checkout\.conf \/etc\/nginx\/rspade\/php-urls\.conf/', $entrypoint) === 1,
            'the entrypoint activates the checkout variant when HAS_FRAMEWORK=1 and the no-checkout one otherwise'
        );
    }
}
