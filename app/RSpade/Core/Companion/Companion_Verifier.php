<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Companion\Companion_Store;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Companion_Verifier - decides whether a signed request may enter the companion realm.
 *
 * AUTHORIZATION IN THIS REALM IS THE SIGNATURE. There is no session, no user and no #[Auth]
 * gate: a request is the companion it claims to be because it signed with a secret only the
 * daemon's child holds. Secrets are derived, never stored: PHP recomputes an instance's secret
 * from the instance id and start time, and the Redis instance record says that instance is
 * still alive. An exited instance's secret stops verifying because its record is gone.
 *
 * Two keys, domain-separated, both from the RAW APP_KEY string (the same characters rsx-lockd
 * reads, never a base64-decoded form):
 *
 *   instance secret   HMAC-SHA256(APP_KEY, "companion|<name>|<instance_id>|<started_at>")
 *   daemon key        HMAC-SHA256(APP_KEY, "companiond")
 *
 * THE SIGNATURE covers everything the server acts on, so nothing can be retargeted in flight:
 * canonical_string(). The checks run cheapest and non-writing first, the nonce last, so an
 * unauthenticated caller can never write to Redis:
 *
 *   headers well formed -> timestamp window -> instance live -> signature -> contract -> nonce
 *
 * A failure answers ['code', 'message', 'status']; success answers null. Every refusal is
 * expected traffic, so none of them is an exception. Refused with `unavailable`, Redis being
 * unreachable is fail-closed: the nonce and the record cannot be checked, so nothing runs.
 *
 * The wire contract (rsx:man companion_apps) states all of this for the daemon's and SDK's side.
 */
class Companion_Verifier
{
    public const HEADER_NAME = 'X-Rsx-Companion';
    public const HEADER_INSTANCE = 'X-Rsx-Companion-Instance';
    public const HEADER_STARTED_AT = 'X-Rsx-Companion-Started-At';
    public const HEADER_CONTRACT = 'X-Rsx-Companion-Contract';
    public const HEADER_SITE = 'X-Rsx-Companion-Site';
    public const HEADER_TIMESTAMP = 'X-Rsx-Companion-Timestamp';
    public const HEADER_NONCE = 'X-Rsx-Companion-Nonce';
    public const HEADER_SIGNATURE = 'X-Rsx-Companion-Signature';

    /**
     * The timestamp window, either side of now: rsx-lockd's window. Clock skew is an NTP
     * problem, never a reason to widen it.
     */
    public const WINDOW_SECONDS = 60;

    /**
     * How long a nonce is remembered. It must outlive the longest a request can stay valid: a
     * request dated WINDOW_SECONDS ahead is accepted for up to 119 s, so a TTL equal to the
     * window would forget its nonce while it can still be replayed.
     */
    public const NONCE_TTL_SECONDS = 180;

    /**
     * The status an instance can make calls in. A paused instance finishes what it has in
     * flight and a stopping one persists its checkpoint; neither is refused.
     */
    public const CALLABLE_STATUSES = ['starting', 'running', 'paused', 'stopping'];

    /** The canonical string's placeholder for a field a request does not carry. */
    private const ABSENT = '-';

    /** The canonical string's name field on a daemon call: domain separation. */
    private const DAEMON_DOMAIN = 'companiond';

    /**
     * An instance's secret. The daemon derives the same value and passes it by environment.
     */
    public static function instance_secret(string $name, string $instance_id, string $started_at): string
    {
        return hash_hmac('sha256', "companion|{$name}|{$instance_id}|{$started_at}", static::__app_key());
    }

    /**
     * The daemon's own key, for its calls to /_companion/_daemon/ and its control socket.
     */
    public static function daemon_key(): string
    {
        return hash_hmac('sha256', self::DAEMON_DOMAIN, static::__app_key());
    }

    /**
     * The string a request's signature is the HMAC-SHA256 of.
     *
     * Every field is from a pipe-free alphabet, enforced before this is built, so the join is
     * unambiguous. $uri is the raw request URI, exactly as sent.
     */
    public static function canonical_string(
        string $method,
        string $uri,
        string $name,
        string $instance_id,
        string $started_at,
        string $contract_key,
        string $site_id,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return implode('|', [
            $method, $uri, $name, $instance_id, $started_at, $contract_key, $site_id, $timestamp, $nonce,
            hash('sha256', $body),
        ]);
    }

    /**
     * Verify an instance's call to one of its endpoints. $name is the URL's companion.
     *
     * @return array{code: string, message: string, status: int}|null Null when it may proceed
     */
    public static function verify_instance(Request $request, string $name): ?array
    {
        $header_name = (string) $request->header(self::HEADER_NAME, '');
        $instance_id = (string) $request->header(self::HEADER_INSTANCE, '');
        $started_at = (string) $request->header(self::HEADER_STARTED_AT, '');
        $contract_key = (string) $request->header(self::HEADER_CONTRACT, '');
        $site_id = (string) $request->header(self::HEADER_SITE, '');
        $timestamp = (string) $request->header(self::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->header(self::HEADER_NONCE, '');
        $signature = (string) $request->header(self::HEADER_SIGNATURE, '');

        $well_formed = $header_name === $name
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $instance_id)
            && preg_match('/^[0-9]{1,20}$/', $started_at)
            && preg_match('/^[a-f0-9]{1,128}$/', $contract_key)
            && ($site_id === '' || preg_match('/^[0-9]{1,20}$/', $site_id))
            && static::__nonce_and_time_well_formed($timestamp, $nonce)
            && preg_match('/^[a-f0-9]{64}$/', $signature);

        if (!$well_formed) {
            return static::__refusal('invalid_signature', 'The request is not signed correctly', 401);
        }

        if (!static::__in_window($timestamp)) {
            return static::__refusal('stale_timestamp', 'The request timestamp is outside the window', 401);
        }

        try {
            $record = Companion_Store::read_instance($instance_id);
        } catch (Throwable $e) {
            return static::__unavailable($e);
        }

        if ($record === null
            || ($record['name'] ?? null) !== $name
            || ($record['started_at'] ?? null) !== $started_at
            || !in_array($record['status'] ?? null, self::CALLABLE_STATUSES, true)) {
            return static::__refusal('instance_not_live', 'This instance is not live', 401);
        }

        $expected = hash_hmac(
            'sha256',
            static::canonical_string(
                $request->getRealMethod(),
                $request->getRequestUri(),
                $name,
                $instance_id,
                $started_at,
                $contract_key,
                $site_id === '' ? self::ABSENT : $site_id,
                $timestamp,
                $nonce,
                (string) $request->getContent()
            ),
            static::instance_secret($name, $instance_id, $started_at)
        );

        if (!hash_equals($expected, $signature)) {
            return static::__refusal('invalid_signature', 'The request is not signed correctly', 401);
        }

        // A companion gone from the code has no contract any more: the instance is stale in
        // exactly the way this code means, and the answer is to exit.
        if ($contract_key !== (Manifest::get_companions()[$name]['endpoints_hash'] ?? null)) {
            return static::__refusal('contract_mismatch', 'This instance was built against another contract', 409);
        }

        return static::__claim_nonce('instance:' . $instance_id, $nonce);
    }

    /**
     * Verify a call from rsx-companiond itself: the daemon key, the window, the nonce. No
     * instance exists yet when it asks, so there is no record and no contract to check.
     *
     * @return array{code: string, message: string, status: int}|null Null when it may proceed
     */
    public static function verify_daemon(Request $request): ?array
    {
        $timestamp = (string) $request->header(self::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->header(self::HEADER_NONCE, '');
        $signature = (string) $request->header(self::HEADER_SIGNATURE, '');

        if (!static::__nonce_and_time_well_formed($timestamp, $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return static::__refusal('invalid_signature', 'The request is not signed correctly', 401);
        }

        if (!static::__in_window($timestamp)) {
            return static::__refusal('stale_timestamp', 'The request timestamp is outside the window', 401);
        }

        $expected = hash_hmac(
            'sha256',
            static::canonical_string(
                $request->getRealMethod(),
                $request->getRequestUri(),
                self::DAEMON_DOMAIN,
                self::ABSENT,
                self::ABSENT,
                self::ABSENT,
                self::ABSENT,
                $timestamp,
                $nonce,
                (string) $request->getContent()
            ),
            static::daemon_key()
        );

        if (!hash_equals($expected, $signature)) {
            return static::__refusal('invalid_signature', 'The request is not signed correctly', 401);
        }

        return static::__claim_nonce('daemon', $nonce);
    }

    /**
     * The nonce is LAST: only a request that proved itself may write to Redis. add_value() is
     * one atomic SET NX, so two racing replays cannot both pass, and it THROWS when the store
     * is unavailable, which this answers by refusing - a nonce that cannot be checked is not
     * a nonce that was not seen.
     */
    private static function __claim_nonce(string $scope, string $nonce): ?array
    {
        $expires_at_ms = (time() + self::NONCE_TTL_SECONDS) * 1000;

        try {
            $fresh = Rsx_Counter::add_value("companion_nonce:{$scope}:{$nonce}", '1', $expires_at_ms);
        } catch (Throwable $e) {
            return static::__unavailable($e);
        }

        if (!$fresh) {
            return static::__refusal('replayed_nonce', 'This nonce has already been used', 401);
        }

        return null;
    }

    private static function __nonce_and_time_well_formed(string $timestamp, string $nonce): bool
    {
        return preg_match('/^[0-9]{1,20}$/', $timestamp) && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce);
    }

    private static function __in_window(string $timestamp): bool
    {
        return abs(time() - (int) $timestamp) <= self::WINDOW_SECONDS;
    }

    private static function __refusal(string $code, string $message, int $status): array
    {
        return ['code' => $code, 'message' => $message, 'status' => $status];
    }

    /**
     * Redis could not answer. Expected during a maintenance window, and the SDK retries it.
     */
    private static function __unavailable(Throwable $e): array
    {
        Log::warning('Companion request refused, the verification store is unavailable: ' . $e->getMessage());

        return static::__refusal('unavailable', 'The application cannot verify requests right now', 503);
    }

    private static function __app_key(): string
    {
        $key = (string) env('APP_KEY');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set - companion requests cannot be verified');
        }

        return $key;
    }
}
