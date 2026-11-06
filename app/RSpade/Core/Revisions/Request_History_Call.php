<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Locks\Site_Lock_Directives;
use App\RSpade\Core\Revisions\Request_History;
use App\RSpade\Core\Revisions\Request_History_Answer;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Revisions\Transaction_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Request_History_Call - one Ajax call's or external API request's part in the request
 * history (Request_History, the contract).
 *
 * The dispatcher builds one per request, after the input is known and before the site lock
 * boundary, and drives it:
 *
 *   $history->run(fn () => Site_Lock::_run_request($mode, $label, function () use ($history) {
 *       $history->open();                           // replay / refusal: throws Request_History_Answer
 *       $result = ... the handler ...;
 *       $history->record($status, $body, $success); // inside the writer's transaction
 *       return $result;
 *   }));
 *
 * run() holds the named site + actor + reference lock around a #[Manual_Lock] request (taken
 * before the boundary, so before any transaction: Site_Lock rule 1); an automatic writer is
 * serialized by its site write lock, under which open() runs. A reader call goes through
 * none of this (the dispatcher only echoes its reference).
 */
#[Instantiatable]
class Request_History_Call
{
    /** The writer row this request records on (the eager mint), captured by open(). */
    private ?Transaction_Model $row = null;

    /** The actor the reference is kept under, resolved by run() / open(): ['type', 'id'] or null. */
    private ?array $actor = null;

    private bool $actor_resolved = false;

    /** [duration_ms, completed_at (ISO)] once record() wrote them. */
    private ?array $recorded = null;

    /**
     * @param string|null $mode The surface's site lock mode (Site_Lock_Directives)
     * @param int $site_id The site the request's lock names (0 = none)
     * @param string|null $ref A VALID reference, or null when none was sent
     * @param string $fingerprint Request_History::fingerprint() of the request's params
     * @param string|null $payload The redacted payload (Request_Payload_Redactor)
     * @param int $started_ns hrtime(true) at the start of the call
     */
    public function __construct(
        public readonly ?string $mode,
        public readonly int $site_id,
        public readonly ?string $ref,
        public readonly string $fingerprint,
        public readonly ?string $payload,
        public readonly int $started_ns
    ) {
    }

    /** Is this a writer request (an automatic writer, or a #[Manual_Lock] that may become one)? */
    public function is_writer(): bool
    {
        return $this->mode === Site_Lock_Directives::WRITER || $this->mode === Site_Lock_Directives::MANUAL;
    }

    /**
     * Run the request's site lock boundary - inside the named site + actor + reference
     * lock for a #[Manual_Lock] request that honours a reference.
     *
     * @param callable $boundary
     * @return mixed What $boundary returned
     */
    public function run(callable $boundary): mixed
    {
        if ($this->mode !== Site_Lock_Directives::MANUAL || !$this->__honours_ref()) {
            return $boundary();
        }

        $token = RsxLocks::named_write_lock(Request_History::NAMED_LOCK_PREFIX . hash('sha256', json_encode([
            $this->site_id,
            $this->actor['type'],
            $this->actor['id'],
            $this->ref,
        ])));

        try {
            return $boundary();
        } finally {
            RsxLocks::release_lock($token);
        }
    }

    /**
     * Inside the boundary, before the handler: capture the writer's row and, for a
     * reference this request honours, look it up - a hit is answered from the history
     * (thrown as a Request_History_Answer, so the writer rolls back and nothing is written).
     *
     * @throws Request_History_Answer
     */
    public function open(): void
    {
        if (!$this->is_writer()) {
            return;
        }

        if ($this->mode === Site_Lock_Directives::WRITER) {
            $this->row = Revision::current_transaction();
        }

        if (!$this->__honours_ref()) {
            return;
        }

        $type_id = Type_Ref_Registry::find_id_by_class_name($this->actor['type']);

        if ($type_id === null) {
            return;
        }

        $site_id = $this->site_id === 0 ? null : $this->site_id;

        $found = DB::table('_transactions')
            ->select(['id', 'endpoint', 'request_fingerprint', 'response_status', 'response_body', 'duration_ms', 'completed_at'])
            ->where('actor_type', $type_id)
            ->where('actor_id', $this->actor['id'])
            ->where('request_ref', $this->ref)
            ->when($site_id === null, fn ($query) => $query->whereNull('site_id'), fn ($query) => $query->where('site_id', $site_id))
            ->first();

        if ($found === null) {
            return;
        }

        if ($found->endpoint !== Revision::_current_endpoint()) {
            throw Request_History_Answer::reused(true);
        }

        if ($found->request_fingerprint !== $this->fingerprint) {
            throw Request_History_Answer::reused(false);
        }

        if ($found->response_body === null) {
            throw Request_History_Answer::expired((string) Rsx_Time::to_iso($found->completed_at));
        }

        throw Request_History_Answer::replay(
            (int) $found->response_status,
            $found->response_body,
            $found->duration_ms === null ? null : (int) $found->duration_ms,
            $found->completed_at
        );
    }

    /**
     * Record the request on its writer row, inside the request's transaction (the writer's,
     * or a #[Manual_Lock] request's when one began): fingerprint, payload and timing always;
     * the reference, status and body only for a SUCCESS this request honours a reference for
     * and whose body is a JSON object. A #[Manual_Lock] request that took no write lock is
     * not a writer request and records nothing.
     *
     * @param int $status The HTTP status the response is sent with (Ajax: 200)
     * @param string|null $body The response body as sent, JSON (Ajax: the envelope's outcome)
     * @param bool $success Did the request succeed (Ajax: a value, not a coded result; API: 2xx)
     */
    public function record(int $status, ?string $body, bool $success): void
    {
        if (!$this->is_writer()) {
            return;
        }

        if ($this->mode === Site_Lock_Directives::MANUAL && !Site_Lock::_manual_request_wrote()) {
            return;
        }

        $row = $this->row;

        if ($row === null) {
            Revision::transaction_id();
            $row = Revision::current_transaction();
        }

        $duration_ms = (int) round((hrtime(true) - $this->started_ns) / 1_000_000);
        $completed_at = Rsx_Time::now_iso();

        $row->request_fingerprint = $this->fingerprint;
        $row->request_payload = $this->payload;
        $row->duration_ms = $duration_ms;
        $row->completed_at = Rsx_Time::to_database($completed_at);

        if ($success && $body !== null && $this->__honours_ref() && $this->__is_json_object($body)
            && $this->__same_actor(Rsx_Model_Abstract::_resolve_context_actor())) {
            // The site the reference is kept under is the site the request's lock named.
            $row->site_id = $this->site_id === 0 ? null : $this->site_id;
            $row->request_ref = $this->ref;
            $row->response_status = $status;
            $row->response_body = $body;
        }

        $row->save();

        $this->recorded = [$duration_ms, $completed_at];
    }

    /**
     * The timing record() wrote - [duration_ms, completed_at (ISO)] - or null. A recorded
     * response carries exactly these, so its replay carries the same values.
     *
     * @return array|null
     */
    public function recorded_timing(): ?array
    {
        return $this->recorded;
    }

    /**
     * Does this request keep its reference: a writer, a valid reference, and an actor to
     * keep it under (a request with nobody signed in has no identity a replay could be
     * confined to).
     */
    private function __honours_ref(): bool
    {
        if (!$this->is_writer() || $this->ref === null) {
            return false;
        }

        if (!$this->actor_resolved) {
            $this->actor = Rsx_Model_Abstract::_resolve_context_actor();
            $this->actor_resolved = true;
        }

        return $this->actor !== null;
    }

    /** Is $actor the identity the reference was looked up under (a sign-in mid-request is not)? */
    private function __same_actor(?array $actor): bool
    {
        return $actor !== null && $actor['type'] === $this->actor['type'] && (int) $actor['id'] === (int) $this->actor['id'];
    }

    private function __is_json_object(string $body): bool
    {
        return json_decode($body, false) instanceof \stdClass;
    }
}
