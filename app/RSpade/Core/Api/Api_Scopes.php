<?php

namespace App\RSpade\Core\Api;

use InvalidArgumentException;

/**
 * Api_Scopes - the rule language that narrows ONE API key below its holder's authority.
 *
 * An API key otherwise carries its user's entire authority. A scope rule set subtracts from
 * that: `_api_keys.scopes` holds newline-delimited `Grant|Deny METHOD path-pattern` lines,
 * and this class is the whole of their meaning - parsing, canonicalisation, matching and the
 * decision. It is static and pure: no database, no session, no configuration.
 *
 * THE PATH IS THE SCOPE NAMESPACE. There is no derived scope vocabulary to keep in step with
 * the routes and no per-endpoint annotation to forget, so a new endpoint is covered - or not
 * covered - by patterns that already exist. Every pattern carries its `/api/vN/` prefix, so a
 * v2 rename can never silently re-scope a v1 key.
 *
 *   Grant GET  /api/v1/billing/*
 *   Grant POST /api/v1/billing/*
 *   Deny  POST /api/v1/invoices/**
 *
 * Rules:
 *   - keyword is Grant or Deny (case-insensitive on read, canonicalised on save);
 *   - method is GET or POST (the only verbs the API dispatches);
 *   - the pattern is matched against the FULL request path, `/api/vN/` retained;
 *   - `*` matches exactly one segment; `**` matches zero or more and may only be the LAST
 *     segment; every other segment is a literal and must match exactly;
 *   - blank lines and `#` comments are ignored.
 *
 * NULL or blank scopes means UNRESTRICTED - every key minted before this feature existed is
 * untouched. The presence of ANY rule flips the key into whitelist mode: deny by default.
 *
 * SPECIFICITY DECIDES, NOT ORDER. The most specific matching rule wins - more literal
 * segments first, then fewer wildcard tokens - and a tie between a Grant and a Deny goes to
 * Deny. Order-independence is the point: combining two rule sets is set union, so a UI that
 * lets an operator tick two presets cannot produce an authority that depends on which one
 * they ticked first. That is a silently-wrong-authority bug with nothing to error on.
 *
 * SCOPES SUBTRACT ONLY. The scope check runs BEFORE the #[Auth] gates and never replaces
 * them: effective authority is the user's live permissions INTERSECTED with the key's rules.
 * A rule can never grant what the user lacks, and a user who loses a permission narrows every
 * key they hold immediately - nothing here is frozen at mint time or cached.
 */
class Api_Scopes
{
    /**
     * The two verbs the API dispatcher will ever route (Api_Endpoint_ManifestSupport enforces
     * the same set at scan time), so a rule naming any other verb is dead text and is refused.
     */
    private const ALLOWED_METHODS = ['GET', 'POST'];

    /**
     * Parse a scopes text into its rules.
     *
     * Blank lines and lines whose first non-space character is `#` are skipped. Every other
     * line must be exactly three whitespace-separated tokens: keyword, method, pattern.
     *
     * @return array<int, array{action: string, method: string, pattern: string}>
     *         action is 'grant' or 'deny'; method is upper-case; pattern is verbatim.
     *
     * @throws InvalidArgumentException naming the offending line, on any malformed rule.
     */
    public static function parse(string $text): array
    {
        $rules = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);

        foreach ($lines as $index => $raw_line) {
            $line = trim($raw_line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $line_number = $index + 1;
            $tokens = preg_split('/\s+/', $line);

            if (count($tokens) !== 3) {
                throw new InvalidArgumentException(
                    "Invalid API scope rule on line {$line_number} ('{$line}'): "
                    . 'expected exactly three tokens - Grant|Deny METHOD /api/vN/path.'
                );
            }

            [$keyword, $method, $pattern] = $tokens;

            $action = strtolower($keyword);
            if ($action !== 'grant' && $action !== 'deny') {
                throw new InvalidArgumentException(
                    "Invalid API scope rule on line {$line_number} ('{$line}'): "
                    . "unknown keyword '{$keyword}' - must be Grant or Deny."
                );
            }

            $method = strtoupper($method);
            if (!in_array($method, self::ALLOWED_METHODS, true)) {
                throw new InvalidArgumentException(
                    "Invalid API scope rule on line {$line_number} ('{$line}'): "
                    . "unsupported method '{$method}' - must be "
                    . implode(' or ', self::ALLOWED_METHODS) . '.'
                );
            }

            static::_validate_pattern($pattern, $line_number, $line);

            $rules[] = ['action' => $action, 'method' => $method, 'pattern' => $pattern];
        }

        return $rules;
    }

    /**
     * The canonical storage form of a scopes text: one rule per line, `Grant GET /path`,
     * keyword capitalised and method upper-cased, comments and blank lines gone.
     *
     * Exact duplicates are collapsed, first occurrence keeping its place. Two presets that
     * both grant the same thing are set union, and the stored text says so once.
     *
     * @return string|null null when the text carries no rules at all (unrestricted).
     *
     * @throws InvalidArgumentException on any malformed rule (see parse()).
     */
    public static function canonicalise(string $text): ?string
    {
        $rules = static::parse($text);
        if (empty($rules)) {
            return null;
        }

        $lines = [];
        foreach ($rules as $rule) {
            $keyword = $rule['action'] === 'grant' ? 'Grant' : 'Deny';
            $line = $keyword . ' ' . $rule['method'] . ' ' . $rule['pattern'];
            $lines[$line] = true;
        }

        return implode("\n", array_keys($lines));
    }

    /**
     * Does this scopes value leave the key at its holder's full authority?
     *
     * NULL and blank are unrestricted by contract. A stored value that carries only comments
     * is unrestricted for the same reason - it declares no rule. A stored value that is
     * MALFORMED throws rather than quietly answering either way: a key whose rules cannot be
     * read is a data fault, not a permission.
     *
     * @throws InvalidArgumentException on a malformed stored value.
     */
    public static function is_unrestricted(?string $scopes): bool
    {
        if ($scopes === null || trim($scopes) === '') {
            return true;
        }

        return empty(static::parse($scopes));
    }

    /**
     * May a key carrying $scopes call $method $path?
     *
     * $path is the request path with its `/api/vN/` prefix and WITHOUT a query string - what
     * Api_Dispatcher has already resolved by the time the scope check runs.
     *
     * Unrestricted scopes answer true. Otherwise: collect every rule whose method equals
     * $method and whose pattern matches $path, keep the most specific ones, and answer false
     * if any of those is a Deny. No matching rule at all is false - deny by default.
     *
     * @throws InvalidArgumentException on a malformed stored value.
     */
    public static function decide(?string $scopes, string $method, string $path): bool
    {
        if (static::is_unrestricted($scopes)) {
            return true;
        }

        return static::_decide_rules(
            static::parse((string) $scopes),
            strtoupper($method),
            static::_segments($path),
            false
        );
    }

    /**
     * Does one pattern match one concrete request path?
     *
     * `*` consumes exactly one segment, `**` (last segment only) consumes the rest including
     * none, everything else is compared literally and case-sensitively.
     */
    public static function matches(string $pattern, string $path): bool
    {
        return static::_segments_match(
            static::_segments($pattern),
            static::_segments($path),
            false
        );
    }

    /**
     * The specificity of a pattern, as the tuple the decision sorts on.
     *
     * More literal segments beats fewer; on a tie, fewer wildcard tokens beats more. So
     * `/api/v1/invoices/42/void` (5 literals, 0 wildcards) outranks `/api/v1/invoices/*`
     * (3 literals, 1 wildcard), which outranks `/api/v1/**` (2 literals, 1 wildcard).
     *
     * @return array{literals: int, wildcards: int}
     */
    public static function specificity(string $pattern): array
    {
        $literals = 0;
        $wildcards = 0;

        foreach (static::_segments($pattern) as $segment) {
            if ($segment === '*' || $segment === '**') {
                $wildcards++;
                continue;
            }
            $literals++;
        }

        return ['literals' => $literals, 'wildcards' => $wildcards];
    }

    /**
     * Which declared endpoints does a rule set actually reach?
     *
     * $api_routes is the baked api route table - rows carrying at least 'pattern' and
     * 'methods' (Manifest::get_routes() rows of type 'api', or the api_endpoints catalog).
     * The answer is every [method, pattern] pair the rules grant, which is what a key-mint UI
     * shows an operator instead of asking them to read globs.
     *
     * ROUTE PATTERNS CONTAIN `:param` TOKENS, AND THEY ARE MATCHED AS OPAQUE SEGMENTS. A
     * route's `:id` stands for every value it could ever take, so it is matched by a rule's
     * `*` AND by any literal rule segment - `/api/v1/invoices/42/void` reaches
     * `/api/v1/invoices/:id/void`, because 42 is one of the values `:id` covers. Two LITERAL
     * segments still only match when equal. The consequence is deliberate: this answers
     * "which endpoints can this key reach at all", not "which URLs", so a rule naming one
     * record still lists the endpoint that serves it.
     *
     * @return array<int, array{method: string, pattern: string}> in route-table order.
     *
     * @throws InvalidArgumentException on a malformed stored value.
     */
    public static function targets_matching(?string $scopes, array $api_routes): array
    {
        $unrestricted = static::is_unrestricted($scopes);
        $rules = $unrestricted ? [] : static::parse((string) $scopes);

        $targets = [];

        foreach ($api_routes as $route) {
            $pattern = $route['pattern'] ?? null;
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            $segments = static::_segments($pattern);

            foreach ((array) ($route['methods'] ?? []) as $method) {
                $method = strtoupper((string) $method);

                if (!$unrestricted && !static::_decide_rules($rules, $method, $segments, true)) {
                    continue;
                }

                $targets[] = ['method' => $method, 'pattern' => $pattern];
            }
        }

        return $targets;
    }

    // ============================================================================================
    // INTERNALS
    // ============================================================================================

    /**
     * Apply the specificity + Deny-tie + deny-by-default decision to already-parsed rules.
     *
     * $route_tokens is true when $target_segments came from a ROUTE pattern rather than a
     * request path, which makes a `:param` segment match any rule segment - see
     * targets_matching().
     */
    private static function _decide_rules(array $rules, string $method, array $target_segments, bool $route_tokens): bool
    {
        $best_literals = -1;
        $best_wildcards = 0;
        $decision = false;

        foreach ($rules as $rule) {
            if ($rule['method'] !== $method) {
                continue;
            }

            if (!static::_segments_match(static::_segments($rule['pattern']), $target_segments, $route_tokens)) {
                continue;
            }

            $specificity = static::specificity($rule['pattern']);
            $literals = $specificity['literals'];
            $wildcards = $specificity['wildcards'];

            $more_specific = $literals > $best_literals
                || ($literals === $best_literals && $wildcards < $best_wildcards);

            if ($more_specific) {
                $best_literals = $literals;
                $best_wildcards = $wildcards;
                $decision = $rule['action'] === 'grant';
                continue;
            }

            // Equal specificity: a Deny always wins the tie, so a carve-out written at the
            // same precision as the grant it carves out of is honored.
            if ($literals === $best_literals && $wildcards === $best_wildcards && $rule['action'] === 'deny') {
                $decision = false;
            }
        }

        // $best_literals is still -1 when nothing matched: deny by default.
        return $best_literals >= 0 && $decision;
    }

    /**
     * Split a path or pattern into its segments, discarding the leading empty one.
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
     * Segment-by-segment match. See matches() for the concrete-path meaning and
     * targets_matching() for what $route_tokens changes.
     */
    private static function _segments_match(array $pattern_segments, array $target_segments, bool $route_tokens): bool
    {
        $pattern_count = count($pattern_segments);
        $target_count = count($target_segments);

        for ($i = 0; $i < $pattern_count; $i++) {
            $segment = $pattern_segments[$i];

            // '**' is only ever the last segment (parse() enforces it) and swallows whatever
            // remains, including nothing at all.
            if ($segment === '**') {
                return true;
            }

            if ($i >= $target_count) {
                return false;
            }

            $target = $target_segments[$i];

            if ($segment === '*') {
                continue;
            }

            // A route's ':param' is an opaque stand-in for every value it can take, so a
            // literal rule segment reaches it.
            if ($route_tokens && str_starts_with($target, ':')) {
                continue;
            }

            if ($segment !== $target) {
                return false;
            }
        }

        return $pattern_count === $target_count;
    }

    /**
     * Validate one rule's pattern, throwing with the offending line on any violation.
     */
    private static function _validate_pattern(string $pattern, int $line_number, string $line): void
    {
        if (!preg_match('#^/api/v[0-9]+/#', $pattern)) {
            throw new InvalidArgumentException(
                "Invalid API scope rule on line {$line_number} ('{$line}'): "
                . "pattern '{$pattern}' must begin /api/vN/ (e.g. /api/v1/contacts/**)."
            );
        }

        $segments = static::_segments($pattern);
        $last = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException(
                    "Invalid API scope rule on line {$line_number} ('{$line}'): "
                    . "pattern '{$pattern}' has an empty segment."
                );
            }

            if ($segment === '**') {
                if ($index !== $last) {
                    throw new InvalidArgumentException(
                        "Invalid API scope rule on line {$line_number} ('{$line}'): "
                        . "pattern '{$pattern}' may only use ** as its last segment."
                    );
                }
                continue;
            }

            // A wildcard is a whole segment or nothing: 'foo*' would read as a literal and
            // silently match nothing, which is the failure this rejects.
            if ($segment !== '*' && str_contains($segment, '*')) {
                throw new InvalidArgumentException(
                    "Invalid API scope rule on line {$line_number} ('{$line}'): "
                    . "pattern '{$pattern}' uses * inside the segment '{$segment}' - "
                    . 'a wildcard is a whole segment (* or **).'
                );
            }
        }
    }
}
