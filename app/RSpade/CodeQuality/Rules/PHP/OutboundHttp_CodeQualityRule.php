<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Naming\Rsx_Paths;
/**
 * PHP-OUTBOUND-HTTP-01 - application code that makes an outbound HTTP request says whether
 * its URL can come from a user.
 *
 * A request this server makes is made from INSIDE its network. When the URL is one a user
 * typed - an import link, a "fetch from URL" field, a webhook tester - the user can point it
 * at http://127.0.0.1:6379 (the local Redis), http://169.254.169.254 (a cloud provider's
 * metadata service and its credentials) or any private neighbour: server-side request
 * forgery. Nothing fails at runtime; the request simply succeeds against the wrong target.
 * Rsx_Url_Fetch::to_temp_file() is the framework's destination-checked fetch (rsx:man
 * url_fetch); a call to a FIXED, trusted endpoint (a payment provider's API) needs no
 * check and says so with a marker.
 *
 * Flagged, in rsx/ only (framework code is reviewed against the same contract by hand):
 *   - the Illuminate Http facade: any Http::<method>(...) chain;
 *   - new GuzzleHttp\Client (the client is where a Guzzle request starts);
 *   - curl_init() / curl_exec();
 *   - get_headers() always (it has no local meaning);
 *   - file_get_contents() / fopen() / copy() / readfile() whose FIRST argument is not
 *     provably local: a string literal is local unless it starts with a network scheme
 *     (http, https, ftp, ftps); any other expression is local only when it visibly builds a
 *     project path (__DIR__, __FILE__, base_path(), storage_path(), tmp_path(),
 *     Rsx_Project_Paths::, Rsx_File_Paths::, sys_get_temp_dir(), tempnam(),
 *     ->getPathname(), ->getRealPath()). When unsure, it is flagged.
 *
 * The exemption is a line marker, `@PHP-OUTBOUND-HTTP-01-EXCEPTION <reason>`, on the call's
 * line or in the comment directly above it. A marker with no reason exempts nothing.
 *
 * rsx:check tier, not manifest-time: whether a URL can come from a user is a judgment, and a
 * false positive must never stop a build.
 */
class OutboundHttp_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'PHP-OUTBOUND-HTTP-01';

    /** Global functions that always reach the network. */
    private const ALWAYS_OUTBOUND = ['curl_init', 'curl_exec', 'get_headers'];

    /** Global functions that reach the network when their first argument is a URL. */
    private const PATH_OR_URL = ['file_get_contents', 'fopen', 'copy', 'readfile'];

    /** Spellings in a first argument that show it is a path in this project. */
    private const LOCAL_MARKERS = [
        '__DIR__', '__FILE__', 'base_path', 'storage_path', 'tmp_path', 'Rsx_Project_Paths',
        'Rsx_File_Paths', 'sys_get_temp_dir', 'tempnam', 'getPathname', 'getRealPath',
    ];

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Outbound HTTP Destination Check';
    }

    public function get_description(): string
    {
        return 'Requires application code making an outbound HTTP request to use Rsx_Url_Fetch for a URL a user can supply, or to mark a fixed trusted destination';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (!Rsx_Paths::is_application($file_path) || str_contains($file_path, '/vendor/')) {
            return;
        }

        // Comments must be visible to read the markers; $contents has them blanked.
        $source = $this->source()->content($file_path);
        if ($source === '') {
            $source = $contents;
        }
        $lines = explode("\n", $source);

        foreach ($this->__find_calls($source) as $call) {
            if (self::__is_excepted($lines, $call['line'])) {
                continue;
            }

            $this->add_violation(
                $file_path,
                $call['line'],
                "Outbound HTTP request ({$call['what']}) with no stated trust in its destination.

A request this server makes leaves from INSIDE its network. If the URL can come from a
user, the user can aim it at http://127.0.0.1:<port> (Redis, MySQL, the lock daemon),
http://169.254.169.254/ (cloud metadata and its credentials) or a private neighbour -
server-side request forgery. Nothing fails; the request just succeeds against the wrong
target.",
                trim($lines[$call['line'] - 1] ?? ''),
                "If this URL can come from a user, fetch it with the destination-checked helper:

    use App\\RSpade\\Lib\\Support\\UrlFetch\\Rsx_Url_Fetch;

    \$fetched = Rsx_Url_Fetch::to_temp_file(\$url, max_bytes: 10 * 1024 * 1024);
    // \$fetched->path, ->content_type, ->filename; catch Rsx_Url_Fetch_Exception for a field error

(File_Attachment_Model::create_from_url() already uses it.)

If this is a FIXED, trusted destination (a provider's API, a path built in code), mark the
call on its line or in the comment directly above it, with the reason:

    // @PHP-OUTBOUND-HTTP-01-EXCEPTION payment provider API, fixed host

A marker without a reason exempts nothing. Contract: php artisan rsx:man url_fetch.",
                'high'
            );
        }
    }

    /**
     * Every flagged call: [line, what].
     *
     * @return array<int, array{line: int, what: string}>
     */
    private function __find_calls(string $source): array
    {
        $tokens = $this->source()->token_array($source);
        $count = count($tokens);
        $guzzle_imported = (bool) preg_match('/^\s*use\s+\\\\?GuzzleHttp\\\\Client\s*;/mi', $source);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            // Http::<anything> - the facade, spelled bare or fully qualified.
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && self::__basename($token[1]) === 'Http') {
                $next = self::__significant_index($tokens, $i + 1, 1);
                $previous = self::__significant_index($tokens, $i - 1, -1);
                $after_new_or_member = $previous !== null && is_array($tokens[$previous])
                    && in_array($tokens[$previous][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_USE, T_NAMESPACE], true);
                if (!$after_new_or_member && $next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_COLON) {
                    $found[] = ['line' => $token[2], 'what' => 'Http facade'];
                }
                continue;
            }

            // new GuzzleHttp\Client
            if ($token[0] === T_NEW) {
                $name_index = self::__significant_index($tokens, $i + 1, 1);
                if ($name_index !== null && is_array($tokens[$name_index])) {
                    $name = ltrim($tokens[$name_index][1], '\\');
                    if (strcasecmp($name, 'GuzzleHttp\\Client') === 0 || ($guzzle_imported && $name === 'Client')) {
                        $found[] = ['line' => $token[2], 'what' => 'new GuzzleHttp\\Client'];
                    }
                }
                continue;
            }

            if (!in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            $is_always = in_array($name, self::ALWAYS_OUTBOUND, true);
            $is_path_or_url = in_array($name, self::PATH_OR_URL, true);
            if (!$is_always && !$is_path_or_url) {
                continue;
            }

            // A method, a static method or a declaration is not the global function.
            $previous = self::__significant_index($tokens, $i - 1, -1);
            if ($previous !== null && is_array($tokens[$previous])
                && in_array($tokens[$previous][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            $open = self::__significant_index($tokens, $i + 1, 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            if ($is_always || !self::__first_argument_is_local($tokens, $open, $count)) {
                $found[] = ['line' => $token[2], 'what' => $name . '()'];
            }
        }

        return $found;
    }

    /** Is the first argument after the "(" at $open provably a local path? */
    private static function __first_argument_is_local(array $tokens, int $open, int $count): bool
    {
        $depth = 0;
        $argument = [];

        for ($i = $open + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === ')' || $token === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $argument[] = $token;
        }

        if ($argument === []) {
            return false;
        }

        // One plain string literal: local unless it names a network scheme.
        if (count($argument) === 1 && is_array($argument[0]) && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return !preg_match('#^[\'"]\s*(https?|ftps?)://#i', $argument[0][1]);
        }

        // Anything else: local only when it visibly builds a project path.
        foreach ($argument as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                if (preg_match('#^[\'"]?\s*(https?|ftps?)://#i', $token[1])) {
                    return false;
                }
                continue;
            }
            if (in_array(self::__basename($token[1]), self::LOCAL_MARKERS, true)) {
                return true;
            }
        }

        return false;
    }

    /** A marker with a reason on the call's line or in the comment lines directly above. */
    private static function __is_excepted(array $lines, int $line): bool
    {
        $pattern = '/@' . preg_quote(self::RULE_ID, '/') . '-EXCEPTION\s+\S/';

        if (preg_match($pattern, $lines[$line - 1] ?? '')) {
            return true;
        }

        for ($index = $line - 2; $index >= 0; $index--) {
            $text = ltrim($lines[$index]);
            if (!preg_match('#^(//|\*|/\*|\#)#', $text)) {
                return false;
            }
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    private static function __basename(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private static function __significant_index(array $tokens, int $start, int $step): ?int
    {
        for ($i = $start; $i >= 0 && $i < count($tokens); $i += $step) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
