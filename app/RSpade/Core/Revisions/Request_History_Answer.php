<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Revisions\Request_History;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Request_History_Answer - a writer request answered from the request history instead of
 * running: a REPLAY of the stored response, or a refusal (409 request_ref_reused, 410
 * request_response_expired).
 *
 * Thrown by Request_History_Call::open() from INSIDE the site lock boundary, so a writer's
 * transaction (and the _transactions row its eager mint wrote) rolls back: nothing is
 * written. It is an answer, never a fault - the dispatchers catch it before their fault
 * handling and render it: api_response() for the external API, ajax_fields() for an Ajax
 * envelope (the dispatcher adds the side channels).
 */
#[Instantiatable]
class Request_History_Answer extends RuntimeException
{
    public const REPLAY = 'replay';
    public const REUSED = 'reused';
    public const EXPIRED = 'expired';

    /**
     * @param string $kind REPLAY | REUSED | EXPIRED
     * @param string $message The refusal's human-readable message (REUSED / EXPIRED)
     * @param int|null $status The stored response status (REPLAY)
     * @param string|null $body The stored response body, JSON of an object (REPLAY)
     * @param int|null $duration_ms The original request's duration
     * @param string|null $completed_at The original request's completion, ISO UTC
     */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $body = null,
        public readonly ?int $duration_ms = null,
        public readonly ?string $completed_at = null
    ) {
        parent::__construct($message);
    }

    /** The error code a refusal answers with (Ajax and API alike); null for a replay. */
    public function error_code(): ?string
    {
        return match ($this->kind) {
            self::REUSED => Request_History::CODE_REF_REUSED,
            self::EXPIRED => Request_History::CODE_RESPONSE_EXPIRED,
            default => null,
        };
    }

    /**
     * The fields a replay adds to the stored body: _replayed and the ORIGINAL timing.
     *
     * @return array
     */
    public function replay_fields(): array
    {
        return [
            '_replayed' => true,
            '_duration_ms' => $this->duration_ms,
            '_completed_at' => $this->completed_at,
        ];
    }

    /**
     * The external API response: the stored status and body plus replay_fields(), or the
     * refusal's {"error": {...}} (409, or 410 naming the original completed_at).
     *
     * @return JsonResponse
     */
    public function api_response(): JsonResponse
    {
        if ($this->kind === self::REPLAY) {
            $body = json_decode((string) $this->body, false, 512, JSON_THROW_ON_ERROR);

            foreach ($this->replay_fields() as $key => $value) {
                $body->$key = $value;
            }

            return new JsonResponse($body, (int) $this->status);
        }

        return Api_Dispatcher::_error(
            $this->error_code(),
            $this->getMessage(),
            $this->kind === self::EXPIRED ? 410 : 409,
            null,
            $this->kind === self::EXPIRED ? ['completed_at' => $this->completed_at] : []
        );
    }

    /**
     * The Ajax envelope fields, before the side channels: the stored outcome
     * ({_success: true, _ajax_return_value}) plus replay_fields(), or a coded failure
     * ({_success: false, error_code, reason, metadata}).
     *
     * @return array
     */
    public function ajax_fields(): array
    {
        if ($this->kind === self::REPLAY) {
            // Decoded as objects below the top level, so an empty object in the stored
            // value is sent as {} again, never as [].
            $stored = (array) json_decode((string) $this->body, false, 512, JSON_THROW_ON_ERROR);

            return $stored + $this->replay_fields();
        }

        return [
            '_success' => false,
            'error_code' => $this->error_code(),
            'reason' => $this->getMessage(),
            'metadata' => $this->kind === self::EXPIRED ? ['completed_at' => $this->completed_at] : [],
        ];
    }

    /**
     * The refusal for a reference whose stored response was pruned.
     *
     * @param string $completed_at The original completion, ISO UTC
     * @return self
     */
    public static function expired(string $completed_at): self
    {
        return new self(
            self::EXPIRED,
            "This request already ran (completed {$completed_at}); its stored response is no longer kept, "
            . 'so it cannot be answered again. Nothing was run.',
            null,
            null,
            null,
            $completed_at
        );
    }

    /**
     * The refusal for a reference that names a different request.
     *
     * @param bool $other_endpoint The reference was used on a different endpoint
     * @return self
     */
    public static function reused(bool $other_endpoint): self
    {
        return new self(
            self::REUSED,
            $other_endpoint
                ? 'This _request_ref was already used for a request to a different endpoint. Nothing was run; send a new reference for a new request.'
                : 'This _request_ref was already used for a request with different parameters. Nothing was run; send a new reference for a new request.'
        );
    }

    /**
     * A replay of a stored response.
     *
     * @param int $status
     * @param string $body
     * @param int|null $duration_ms
     * @param string|null $completed_at Database form; normalised to ISO
     * @return self
     */
    public static function replay(int $status, string $body, ?int $duration_ms, ?string $completed_at): self
    {
        return new self(
            self::REPLAY,
            'Answered from the request history',
            $status,
            $body,
            $duration_ms,
            $completed_at === null ? null : Rsx_Time::to_iso($completed_at)
        );
    }
}
