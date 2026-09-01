<?php

namespace App\RSpade\Core\Api;

use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Api\Api_Scope_Validation_Exception;

/**
 * Api_Scopes - the path-pattern language that narrows ONE API key below its holder.
 *
 * An API key otherwise carries its user's entire authority. A scope set subtracts from that:
 * `_api_keys.scopes` holds one scope per line, and this class is the whole of their meaning -
 * validation, normalization, matching and the decision. It is static and pure: no database,
 * no session, no configuration.
 *
 * A SCOPE IS A BARE PATH PATTERN, AND IT IS A GRANT. There is no keyword, no method and no
 * deny form:
 *
 *     /api/v1/clients/#/view
 *     /api/v1/contacts/*
 *     /api/?/me
 *
 * THE PATH IS THE SCOPE NAMESPACE. There is no derived scope vocabulary to keep in step with
 * the routes and no per-endpoint annotation to forget, so a new endpoint is covered - or not
 * covered - by patterns that already exist. Every scope carries its `/api/<version>/` prefix,
 * so a v2 rename can never silently re-scope a v1 key.
 *
 * THE GRAMMAR
 *   - a scope starts with `/` and its first two segments are `api` and a VERSION - a `vN`
 *     literal, or `?` or `#`;
 *   - `?`, `#` and `*` are the ONLY wildcards, and each occupies a WHOLE segment: `/foo/bar*`,
 *     `/foo/?bar` and `/foo/b#r` are validation errors, because a half-segment wildcard reads
 *     as a literal and silently matches nothing;
 *   - `?` matches exactly one non-empty segment of any shape;
 *   - `#` matches exactly one all-digits segment;
 *   - `*` may appear ONLY as the last segment, and matches the prefix itself AND everything
 *     below it: `/api/v1/files/*` reaches `/api/v1/files`, `/api/v1/files/abc` and
 *     `/api/v1/files/abc/text`;
 *   - every other segment is a literal, compared exactly and CASE-SENSITIVELY - which is what
 *     the router itself does, so a scope cannot admit a path the router would not route;
 *   - a trailing slash is meaningless on BOTH sides, and a query string is not part of a path
 *     at all: both are stripped before anything is compared.
 *
 * NULL OR BLANK IS UNRESTRICTED - the key carries its holder's full authority. The presence of
 * ANY line flips the key into whitelist mode: DENY BY DEFAULT, so a scope set never needs to
 * write a blanket refusal, and there is no way to express one.
 *
 * A MALFORMED STORED LINE IS IGNORED FOR MATCHING AND STILL COUNTS AS A REGISTERED SCOPE. A
 * key whose only scope is malformed therefore denies everything: it FAILS CLOSED, which is the
 * only safe reading of "somebody wrote a rule here and it cannot be understood". Every write
 * path validates, so the only way to get one is to edit the column by hand - and every request
 * that evaluates one logs a warning naming the key, the text and the reason.
 *
 * SCOPES SUBTRACT ONLY. The scope check never replaces the #[Auth] gates: effective authority
 * is the user's live permissions INTERSECTED with the key's scopes. A scope can never grant
 * what the user lacks, and a user who loses a permission narrows every key they hold
 * immediately - nothing here is frozen at mint time or cached.
 */
class Api_Scopes
{
    /**
     * Validate one scope against the grammar. Returns nothing; the failure IS the exception.
     *
     * The message names the rule that was broken, in the words of whoever typed the scope -
     * an application endpoint hands it straight to response_form_error().
     *
     * @throws Api_Scope_Validation_Exception
     */
    public static function validate(string $scope): void
    {
        $normalized = static::normalize($scope);

        if ($normalized === '') {
            throw new Api_Scope_Validation_Exception('a scope cannot be blank');
        }

        if (!str_starts_with($normalized, '/')) {
            throw new Api_Scope_Validation_Exception(
                "a scope must start with /api/<version>/ : '{$scope}'"
            );
        }

        $segments = static::_segments($normalized);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new Api_Scope_Validation_Exception(
                    "a scope has an empty segment: '{$scope}'"
                );
            }

            // A wildcard is a whole segment or it is not a wildcard at all. 'bar*' would read
            // as the literal 'bar*', match no path ever, and look like a working rule.
            if (strlen($segment) > 1 && preg_match('/[?#*]/', $segment)) {
                throw new Api_Scope_Validation_Exception(
                    "a wildcard must be a whole segment: '{$scope}'"
                );
            }
        }

        // Checked before the api/version rules so a misplaced star is reported as a misplaced
        // star, rather than as whatever segment it happened to displace.
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '*' && $index !== $last) {
                throw new Api_Scope_Validation_Exception(
                    "'*' may only be the last segment: '{$scope}'"
                );
            }
        }

        if (count($segments) < 3) {
            throw new Api_Scope_Validation_Exception(
                "a scope must start with /api/<version>/ : '{$scope}'"
            );
        }

        if ($segments[0] !== 'api') {
            throw new Api_Scope_Validation_Exception(
                "a scope must start with /api/<version>/ : '{$scope}'"
            );
        }

        // The version may be wildcarded (a key that follows the API across versions) but a
        // LITERAL version must look like one, or the scope is a typo nobody would notice.
        $version = $segments[1];
        if ($version !== '?' && $version !== '#' && !preg_match('/^v[0-9]+$/', $version)) {
            throw new Api_Scope_Validation_Exception(
                "the version segment must be vN, ? or # : '{$scope}'"
            );
        }
    }

    /**
     * The storage form of one scope: trimmed, query string discarded, trailing slash gone.
     *
     * Normalization NEVER validates - it is what both sides of a comparison go through, and a
     * request path is normalized with the same function.
     */
    public static function normalize(string $scope): string
    {
        $value = trim($scope);

        // A QUERY STRING CAN ONLY BEGIN IN THE LAST SEGMENT - that is what makes it
        // separable from the '?' wildcard at all. So the query is stripped there and nowhere
        // else: a mid-path '?bar' stays put and reaches validate(), which refuses it as a
        // half-segment wildcard, while a final '?page=2' (with or without the slash before
        // it) is discarded. A last segment that is exactly '?' is the wildcard, untouched.
        $slash = strrpos($value, '/');
        $tail_at = $slash === false ? 0 : $slash + 1;
        $tail = substr($value, $tail_at);

        if ($tail !== '?') {
            $query = strpos($tail, '?');
            if ($query !== false) {
                $value = rtrim(substr($value, 0, $tail_at + $query));
            }
        }

        if ($value !== '/' && str_ends_with($value, '/')) {
            $value = rtrim($value, '/');
        }

        return $value;
    }

    /**
     * Does one scope match one concrete request path?
     *
     * Both sides are normalized first, so a trailing slash and a query string on either side
     * change nothing. See the class docblock for the segment rules.
     */
    public static function matches(string $scope, string $path): bool
    {
        return static::_segments_match(
            static::_segments(static::normalize($scope)),
            static::_segments(static::normalize($path)),
            false
        );
    }

    /**
     * Split a stored scopes value into the scopes that are usable and the ones that are not.
     *
     * Blank lines are skipped and carry no meaning. Every other line is normalized and
     * validated: a good one lands in 'valid' (deduped, in first-seen order), a bad one lands
     * in 'malformed' keyed by its RAW text with the validator's reason as the value.
     *
     * READING NEVER THROWS. The caller decides what a malformed line means - Api_Dispatcher
     * ignores it for matching, logs it, and still counts it as a registered scope.
     *
     * @return array{valid: array<int, string>, malformed: array<string, string>}
     */
    public static function parse_all(?string $stored): array
    {
        $valid = [];
        $malformed = [];

        if ($stored === null) {
            return ['valid' => [], 'malformed' => []];
        }

        foreach (preg_split('/\r\n|\r|\n/', $stored) as $raw_line) {
            $line = trim($raw_line);

            if ($line === '') {
                continue;
            }

            try {
                static::validate($line);
            } catch (Api_Scope_Validation_Exception $e) {
                $malformed[$line] = $e->getMessage();
                continue;
            }

            $valid[static::normalize($line)] = true;
        }

        return ['valid' => array_keys($valid), 'malformed' => $malformed];
    }

    /**
     * How many scopes are registered on a stored value - valid and malformed alike.
     *
     * A malformed scope COUNTS, because it is what makes the key deny-by-default. A listing
     * that reported only the usable ones would show '0 scopes' for a key that can call
     * nothing, which is the most misleading answer available.
     */
    public static function count_scopes(?string $stored): int
    {
        $parsed = static::parse_all($stored);

        return count($parsed['valid']) + count($parsed['malformed']);
    }

    /**
     * Does this stored value leave the key at its holder's full authority?
     *
     * NULL and blank are unrestricted by contract. Anything else - including a value carrying
     * nothing but malformed lines - is a scoped key.
     */
    public static function is_unrestricted(?string $scopes): bool
    {
        if ($scopes === null || trim($scopes) === '') {
            return true;
        }

        return static::count_scopes($scopes) === 0;
    }

    /**
     * The canonical storage text for a scope set, or null when it carries no scope at all.
     *
     * Every line is validated, so this is the function every WRITE path goes through, and the
     * FIRST bad scope refuses the whole set - a partially-stored scope set would be a
     * credential narrower or wider than anybody asked for.
     *
     * @throws Api_Scope_Validation_Exception naming the offending scope
     */
    public static function canonicalize(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $scopes = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $raw_line) {
            $line = trim($raw_line);

            if ($line === '') {
                continue;
            }

            static::validate($line);

            $scopes[static::normalize($line)] = true;
        }

        if (empty($scopes)) {
            return null;
        }

        return implode("\n", array_keys($scopes));
    }

    /**
     * May a key carrying $scopes call $path?
     *
     * $path is the request path WITHOUT its query string - what Api_Dispatcher has already
     * resolved by the time the scope check runs (a query string would be stripped anyway).
     *
     * Unrestricted scopes answer true. Otherwise the answer is "does any VALID scope match",
     * and a malformed scope is skipped - so a key whose only scope is malformed answers false.
     *
     * $api_key_id turns on the malformed-scope warning. It is passed by every caller that
     * evaluates a STORED credential, and deliberately omitted by the ones answering about a
     * scope set an operator is still typing - a half-written scope is the expected state of a
     * preview panel and is not an incident.
     */
    public static function decide(?string $scopes, string $path, ?int $api_key_id = null): bool
    {
        if (static::is_unrestricted($scopes)) {
            return true;
        }

        $parsed = static::parse_all($scopes);

        if ($api_key_id !== null) {
            static::_warn_malformed($api_key_id, $parsed['malformed']);
        }

        $path_segments = static::_segments(static::normalize($path));

        foreach ($parsed['valid'] as $scope) {
            if (static::_segments_match(static::_segments($scope), $path_segments, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a scope set reach a declared ROUTE PATTERN (as opposed to a concrete path)?
     *
     * ROUTE PATTERNS CONTAIN `:param` TOKENS, AND THEY ARE MATCHED AS OPAQUE SEGMENTS. A
     * route's `:id` stands for every value it could ever take, so it is reached by `?`, by `#`
     * and by any literal scope segment - `/api/v1/clients/42/view` reaches
     * `/api/v1/clients/:id/view`, because 42 is one of the values `:id` covers. Two LITERAL
     * segments still only match when equal. The consequence is deliberate: this answers "which
     * endpoints can this key reach at all", not "which URLs", so a scope naming one record
     * still lists the endpoint that serves it.
     */
    public static function reaches_route(?string $scopes, string $route_pattern): bool
    {
        if (static::is_unrestricted($scopes)) {
            return true;
        }

        $route_segments = static::_segments(static::normalize($route_pattern));

        foreach (static::parse_all($scopes)['valid'] as $scope) {
            if (static::_segments_match(static::_segments($scope), $route_segments, true)) {
                return true;
            }
        }

        return false;
    }

    // ============================================================================================
    // INTERNALS
    // ============================================================================================

    /**
     * One Log::warning per malformed scope, naming the key so an operator can find the row.
     *
     * Warned rather than thrown: the request has already been decided (fail closed), and the
     * caller needs to see WHY their key stopped working, not a 500.
     */
    private static function _warn_malformed(int $api_key_id, array $malformed): void
    {
        foreach ($malformed as $text => $reason) {
            Log::warning("API key #{$api_key_id}: ignoring malformed scope '{$text}' - {$reason}");
        }
    }

    /**
     * Split a normalized path or scope into its segments, discarding the leading empty one.
     */
    private static function _segments(string $path): array
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return [];
        }

        return explode('/', $path);
    }

    /**
     * Segment-by-segment match. $route_tokens makes a ':param' target segment opaque - see
     * reaches_route().
     */
    private static function _segments_match(array $scope_segments, array $target_segments, bool $route_tokens): bool
    {
        $scope_count = count($scope_segments);
        $target_count = count($target_segments);

        for ($i = 0; $i < $scope_count; $i++) {
            $segment = $scope_segments[$i];

            // '*' is only ever the last segment (validate() enforces it) and covers the prefix
            // itself as well as everything below it, so it matches here whether or not the
            // target has any segments left.
            if ($segment === '*') {
                return true;
            }

            if ($i >= $target_count) {
                return false;
            }

            $target = $target_segments[$i];

            if ($target === '') {
                return false;
            }

            // A route's ':param' is an opaque stand-in for every value it can take, so every
            // scope segment reaches it.
            $opaque = $route_tokens && str_starts_with($target, ':');

            if ($segment === '?') {
                continue;
            }

            if ($segment === '#') {
                if (!$opaque && !ctype_digit($target)) {
                    return false;
                }
                continue;
            }

            if (!$opaque && $segment !== $target) {
                return false;
            }
        }

        return $scope_count === $target_count;
    }
}
