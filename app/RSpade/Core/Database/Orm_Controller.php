<?php

namespace App\RSpade\Core\Database;

use Exception;
use Illuminate\Http\Request;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Database\Model_Fetch_Lineage;
use App\RSpade\Core\Database\Orm_Fetch_Preload;
use App\RSpade\Core\Manifest\Manifest;

/**
 * ORM Controller for JavaScript model fetch operations
 *
 * Provides Ajax endpoints for the JavaScript ORM (Rsx_Js_Model) to fetch
 * model records. Uses the standard #[Ajax_Endpoint] pattern to ensure
 * proper request/response wrapping.
 *
 * SECURITY NOTICE: This controller is a major data egress point in the system.
 * It will be a target for vulnerability scanning and enumeration attacks.
 * All methods must be written defensively to prevent information disclosure.
 *
 * AUTHORIZATION: the class gate is 'public' because this controller is the generic
 * ORM transport, not a data surface of its own. Every request is gated inside, on
 * the TARGET model member: Auth_Gates::surface_permits() evaluates the #[Auth] gates the
 * model's fetch()/portal_fetch()/relationship declares, and the model's own body
 * still applies its record-level rules. A denial is reported as "not found" like
 * any other unresolvable record (anti-enumeration).
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Orm_Controller extends Rsx_Controller_Abstract
{
    /**
     * Fetch model records by ID.
     *
     * Called by Rsx_Js_Model.fetch() / fetch_or_null() in JavaScript, which batch every
     * lookup issued in one synchronous turn into a single call here. The model must have
     * the #[Ajax_Endpoint_Model_Fetch] attribute on its fetch() method.
     *
     * WIRE CONTRACT
     *   in:  {model: string, ids: int[]}   1..rsx.model_fetch.batch_max_ids ids
     *   out: {records: {"<id>": record, ...}}
     *
     * A requested id is present in `records` when it resolved, and simply ABSENT when it
     * did not. Absence is the ONE answer for every unresolvable id - the row does not
     * exist, the model's own fetch() refused it, or the surface's gates denied this
     * caller - so a prober cannot tell those apart (the anti-enumeration property the
     * single-id endpoint got from its one generic "Record not found"). There is no
     * per-id reason and no per-id error code. The throw-or-null decision is the CLIENT's
     * (fetch() throws, fetch_or_null() returns null); it never rode the wire.
     *
     * BATCH-LEVEL failures are still errors, because they are about the REQUEST rather
     * than about any record:
     * - missing model / missing or malformed ids / over the id cap -> ERROR_VALIDATION
     * - unknown model, non-model class -> ERROR_NOT_FOUND, one identical message
     * - model without the fetch attribute -> ERROR_UNAUTHORIZED (deliberately verbose)
     *
     * SECURITY: This endpoint is a primary target for enumeration attacks, so a name
     * that is not a model answers one message whatever the reason:
     * - Class doesn't exist -> "Model <name> not found"
     * - Class exists but isn't a model -> "Model <name> not found"
     * The ONE deliberate exception: a model whose fetch() lacks #[Ajax_Endpoint_Model_Fetch]
     * answers ERROR_UNAUTHORIZED naming the missing attribute (see the comment at that
     * check) - the model name is already in the client bundle, and the message is what a
     * developer needs to fix the declaration.
     *
     * @param Request $request
     * @param array $params Expected: model (string), ids (array of int)
     * @return mixed The records map, or an error response
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function fetch(Request $request, array $params = [])
    {
        $model_name = $params['model'] ?? null;
        $ids = $params['ids'] ?? null;

        if (!$model_name) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'Model name is required');
        }

        if (!is_array($ids) || empty($ids)) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'ids parameter is required');
        }

        // Distinct ids only. The client already sends distinct ids (it dedups every
        // in-flight lookup), so this is a belt: it keeps the cap honest about how much
        // work was actually requested, and keeps the records map keyed once per id.
        $distinct_ids = [];
        foreach ($ids as $raw_id) {
            if (!is_scalar($raw_id) || !is_numeric($raw_id)) {
                return response_error(
                    \App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION,
                    'ids must contain only numeric record ids'
                );
            }

            $distinct_ids[(int) $raw_id] = (int) $raw_id;
        }
        $distinct_ids = array_values($distinct_ids);

        // REFUSE an oversized request, never truncate it. A silently-partial answer is
        // indistinguishable from "those records do not exist", which is exactly the
        // signal this endpoint's absence-means-nothing contract depends on.
        $cap = (int) config('rsx.model_fetch.batch_max_ids');
        if (count($distinct_ids) > $cap) {
            return response_error(
                \App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION,
                'Too many ids in one fetch request: ' . count($distinct_ids) . ' requested, '
                . "the limit is {$cap} (rsx.model_fetch.batch_max_ids). Split the set into "
                . 'multiple requests.'
            );
        }

        // Look up the PHP model class from manifest
        try {
            $model_metadata = Manifest::php_get_metadata_by_class($model_name);
        } catch (\Exception $e) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model {$model_name} not found");
        }

        $model_class = $model_metadata['fqcn'] ?? null;
        if (!$model_class) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model {$model_name} not found");
        }

        // Verify it's a subclass of Rsx_Model_Abstract (security check)
        // SECURITY: Use same error message as "not found" to prevent enumeration
        if (!Manifest::php_is_subclass_of($model_name, 'Rsx_Model_Abstract')) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model {$model_name} not found");
        }

        // Portal requests resolve to the model's portal_fetch() (portal authorization
        // contract), regular requests to fetch() (staff authorization contract). There
        // is one ORM endpoint; the method name is chosen by request context.
        $fetch_method_name = static::_resolve_fetch_method_name();

        // Check if the fetch method has the Ajax_Endpoint_Model_Fetch attribute
        // SECURITY EXCEPTION: This error reveals the model exists but lacks the attribute.
        // This is acceptable because: (1) attacker already knows model name from client code,
        // (2) detailed message is essential for developers diagnosing configuration issues,
        // (3) knowing a model lacks fetch() doesn't expose exploitable information.
        // THROUGH THE LINEAGE, not one file record: a core model declares fetch() on its
        // abstract base, and an application override declares only what it changes.
        $has_fetch_attribute = Model_Fetch_Lineage::static_declaration(
            $model_name,
            $fetch_method_name,
            'Ajax_Endpoint_Model_Fetch'
        ) !== null;

        if (!$has_fetch_attribute) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED, "Model {$model_name} {$fetch_method_name}() method missing Ajax_Endpoint_Model_Fetch attribute");
        }

        // --- Declarative #[Auth] gates ---
        // The framework authorization seam the generic ORM path previously lacked:
        // gates run BEFORE any model code, ONCE for the whole request (the gate list is
        // user-scoped, so it cannot differ per id). A denial yields an EMPTY records map:
        // every requested id is absent, which is byte-identical to a request whose ids
        // all happen to name no row. That is the same indistinguishability the single-id
        // endpoint bought with its one generic "Record not found". Record-level narrowing
        // stays inside fetch()/portal_can_read().
        if (!Auth_Gates::surface_permits($model_name . '::' . $fetch_method_name)) {
            return ['records' => []];
        }

        // Keyed by record id. PHP coerces the numeric-string key back to an int, which is
        // harmless: record ids are always >= 1, so the key set is never the 0..n-1 run that
        // would make json_encode emit an ARRAY instead of an object. The client aligns on
        // String(id).
        $records = [];

        foreach (static::__serve_records($model_class, $model_name, $fetch_method_name, $distinct_ids) as $id => $record) {
            $records[(string) $id] = $record;
        }

        return ['records' => $records];
    }

    /**
     * Fetch related records via a model's relationship method
     *
     * Called by JavaScript relationship methods in Base_*_Model stubs.
     * Validates source model is fetchable, then calls the relationship,
     * and passes each related record through its own fetch() for security.
     *
     * Flow:
     * 1. Validate source model has fetch attribute
     * 2. Call source model's fetch() to verify access to parent record
     * 3. Call relationship method to get related IDs
     * 4. For each related record, call its model's fetch() for security
     * 5. Return hydrated results (single object or array)
     *
     * @param Request $request
     * @param array $params Expected: model (string), id (int), relationship (string)
     * @return mixed Related model data or false
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function fetch_relationship(Request $request, array $params = [])
    {
        $model_name = $params['model'] ?? null;
        $id = $params['id'] ?? null;
        $relationship_name = $params['relationship'] ?? null;

        // Validate required parameters
        if (!$model_name) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'Model name is required');
        }
        if ($id === null) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'ID parameter is required');
        }
        // The same id rule fetch() applies: one scalar numeric id, cast to int before any
        // model code sees it. An array or an object never reaches (portal_)fetch().
        if (!is_scalar($id) || !is_numeric($id)) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'id must be a numeric record id');
        }
        $id = (int) $id;
        if (!$relationship_name) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'Relationship name is required');
        }

        // Look up the PHP model class from manifest
        try {
            $model_metadata = Manifest::php_get_metadata_by_class($model_name);
        } catch (\Exception $e) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model not found");
        }

        $model_class = $model_metadata['fqcn'] ?? null;
        if (!$model_class) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model not found");
        }

        // Verify it's a subclass of Rsx_Model_Abstract
        if (!Manifest::php_is_subclass_of($model_name, 'Rsx_Model_Abstract')) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Model not found");
        }

        // Portal requests resolve to portal_fetch() (portal authorization contract),
        // regular requests to fetch() (staff authorization contract).
        $fetch_method_name = static::_resolve_fetch_method_name();

        // Check if the fetch method has the Ajax_Endpoint_Model_Fetch attribute
        $has_fetch_attribute = Model_Fetch_Lineage::static_declaration(
            $model_name,
            $fetch_method_name,
            'Ajax_Endpoint_Model_Fetch'
        ) !== null;

        if (!$has_fetch_attribute) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED, "Model {$fetch_method_name}() not available");
        }

        // Verify the relationship exists and is fetchable
        // Check if method has #[Relationship] attribute
        // The lineage again: a relationship declared on the abstract base is the model's.
        $has_relationship_attr = Model_Fetch_Lineage::instance_declaration(
            $model_name,
            $relationship_name,
            'Relationship'
        ) !== null;
        $has_fetch_attr_on_rel = Model_Fetch_Lineage::instance_declaration(
            $model_name,
            $relationship_name,
            'Ajax_Endpoint_Model_Fetch'
        ) !== null;

        if (!$has_relationship_attr) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "No such relationship: {$relationship_name}");
        }

        if (!$has_fetch_attr_on_rel) {
            return response_error(
                \App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED,
                "Relationship '{$relationship_name}' does not have the #[Ajax_Endpoint_Model_Fetch] attribute. " .
                "Add this attribute to the relationship method before it can be fetched from JavaScript."
            );
        }

        // --- Declarative #[Auth] gates ---
        // Two surfaces are about to be used: the source model's (portal_)fetch(), and
        // the relationship method itself. Both are indexed surfaces with their own
        // gate lists (each already merged with the model's class-level #[Auth]), and
        // both must pass before any model code runs. A denial is reported as the
        // generic "not found" the missing-record path uses (anti-enumeration).
        if (!Auth_Gates::surface_permits($model_name . '::' . $fetch_method_name)
            || !Auth_Gates::surface_permits($model_name . '::' . $relationship_name)) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Record not found or access denied");
        }

        // Fetch the source record using its (portal_)fetch() method (security check)
        // This validates that the current user has access to this record.
        // A coded refusal thrown by the body (require_permission(), a not-found) reads as
        // absence, exactly as fetch() serves it: the answer never tells refused from absent.
        try {
            $fetch_result = $model_class::$fetch_method_name($id);
        } catch (\Throwable $e) {
            if (!\App\RSpade\Core\Ajax\Ajax::is_coded_failure($e)) {
                throw $e;
            }

            $fetch_result = null;
        }

        if (!$fetch_result) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Record not found or access denied");
        }

        // fetch() may return an Eloquent model OR an augmented array.
        // If it's an array, we need to verify access was granted and re-fetch
        // the actual Eloquent model to call relationship methods on it.
        //
        // INEFFICIENCY NOTE: when fetch() returns an array, this source record costs two
        // queries - one inside fetch() for the access check, one here for the Eloquent
        // instance the relationship methods need. The batch preload does NOT help: it can
        // only serve rows it already loaded, and loading this one row would itself be the
        // extra query. It is a fixed two-query cost for ONE record, not a per-id fan-out
        // (the plural branch below, which was the real cost, is preloaded).
        if (is_array($fetch_result)) {
            // Verify the array contains a valid id matching our request
            // This confirms fetch() actually returned data for this record
            // (not, for example, false cast to array or invalid data)
            if (!isset($fetch_result['id']) || $fetch_result['id'] != $id) {
                return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Record not found or access denied");
            }

            // Access granted - now fetch the actual Eloquent model for relationships
            $source_record = $model_class::find($id);
            if (!$source_record) {
                // Shouldn't happen if fetch() succeeded, but defensive check
                return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, "Record not found");
            }
        } else {
            // fetch() returned an Eloquent model directly - use it
            $source_record = $fetch_result;
        }

        // Call the relationship method
        $relation = $source_record->$relationship_name();

        // Determine if this is a singular or plural relationship
        $is_singular = $relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphOne
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo;

        // Get just the IDs first (efficient single query)
        if ($is_singular) {
            // For singular relationships, get the foreign key value directly
            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {
                if (!$source_record->{$relation->getForeignKeyName()} || !$source_record->{$relation->getMorphType()}) {
                    return null;
                }

                // The stored _type is a type-ref INTEGER. Stock Eloquent resolves it to the
                // related class through the morph map (Type_Ref_Registry::register_morph_map),
                // and loads the row under the related model's own global scopes - the column
                // value itself is never used as a class name here.
                $related = $relation->getResults();
                if (!$related) {
                    return null;
                }
                $related_id = $related->id;
                $related_class = get_class($related);
            } else {
                // The relation's own Eloquent query, so the related model's global scopes
                // (soft deletes, the site scope) apply exactly as they do everywhere else.
                $related_id = $relation->first()?->id;
                if (!$related_id) {
                    return null;
                }
                $related_class = get_class($relation->getRelated());
            }

            // Get simple class name for fetch
            $related_class_parts = explode('\\', $related_class);
            $related_model_name = end($related_class_parts);

            // Is the related model fetchable? Through the LINEAGE, exactly as for the source
            // model: a core model declares fetch() on its abstract base, so its concrete's
            // own file record never carries the attribute.
            $related_fqcn = static::__fetchable_model_fqcn($related_model_name, $fetch_method_name);

            if ($related_fqcn === null) {
                return null;
            }

            // The related record is served by ITS OWN (portal_)fetch() surface, which
            // is reached here by a direct static call rather than by re-entering this
            // endpoint - so that surface's gates are evaluated here. A denial reads as
            // "no related record", identical to an unfetchable relation above.
            if (!Auth_Gates::surface_permits($related_model_name . '::' . $fetch_method_name)) {
                return null;
            }

            // Through the security filter, served exactly as fetch() serves it: a refusal
            // or an absent row is null, never false.
            return static::__serve_records($related_fqcn, $related_model_name, $fetch_method_name, [$related_id])[$related_id] ?? null;
        } else {
            // For plural relationships, get the IDs efficiently - but never more than
            // the endpoint is willing to serve. Each id below costs a full fetch()
            // round trip, so an unbounded relation (a client with 80k contacts) would
            // turn one lazy `await record.things()` into 80k queries and a memory
            // fatal. One id past the cap is fetched purely to detect the overflow.
            $cap = (int) config('rsx.model_fetch.max_relationship_records');
            // QUALIFIED: a many-to-many or through relation joins a pivot table that carries an
            // `id` of its own, and Eloquent's pluck() does not qualify the column.
            $related_ids = $relation->limit($cap + 1)->pluck($relation->getRelated()->getQualifiedKeyName())->toArray();

            if (count($related_ids) > $cap) {
                // Loud, not truncated: silently returning the first N would hand the
                // caller a partial set it has no way to detect.
                throw new \RuntimeException(
                    "Relationship '{$relationship_name}' on {$model_name} exceeds the "
                    . "{$cap}-record lazy-fetch cap (rsx.model_fetch.max_relationship_records). "
                    . 'A relation this size needs its own paginated #[Ajax_Endpoint], not a lazy fetch.'
                );
            }

            if (empty($related_ids)) {
                return [];
            }

            // Get the related model class
            $related_class = get_class($relation->getRelated());
            $related_class_parts = explode('\\', $related_class);
            $related_model_name = end($related_class_parts);

            // Is the related model fetchable? Through the lineage, as above.
            $related_fqcn = static::__fetchable_model_fqcn($related_model_name, $fetch_method_name);

            if ($related_fqcn === null) {
                return [];
            }

            // Same gate evaluation as the singular branch: the related model's own
            // fetch surface is invoked directly, so its gates are enforced here. A
            // denial yields an empty set, identical to an unfetchable relation.
            if (!Auth_Gates::surface_permits($related_model_name . '::' . $fetch_method_name)) {
                return [];
            }

            // Every related id still goes through its own (portal_)fetch() - that is the
            // authorization boundary - served exactly as fetch() serves it.
            $results = array_values(static::__serve_records($related_fqcn, $related_model_name, $fetch_method_name, $related_ids));

            return $results;
        }
    }

    /**
     * Serve records through a model's (portal_)fetch(): THE one way every ORM path answers a
     * set of ids, so the same record has the same payload whether it was asked for by id or
     * reached through a relationship.
     *
     * ONE query preloads every id (Orm_Fetch_Preload): each fetch() body's `static::find($id)`
     * is served from it, so N ids cost one SELECT. A body whose lookup is constrained or
     * scope-stripped misses the preload and runs its own query.
     *
     * An id is ABSENT from the answer when the body returned false or null, or THREW A CODED
     * REFUSAL (require_permission(), a not-found - Ajax::is_coded_failure()). One id's refusal
     * is that id's answer and never fails its siblings, and a refusal is indistinguishable
     * from a missing row. Anything else a body throws is a fault and fails the request loudly.
     *
     * @param string $fqcn The model's FQCN
     * @param string $model_name Its simple name, for the messages
     * @param string $fetch_method_name 'fetch' or 'portal_fetch'
     * @param array<int> $ids Distinct record ids
     * @return array<int, mixed> id => the body's result, in $ids order, absent ids left out
     */
    private static function __serve_records(string $fqcn, string $model_name, string $fetch_method_name, array $ids): array
    {
        $records = [];

        try {
            Orm_Fetch_Preload::populate($fqcn, $fqcn::whereIn('id', $ids)->get());

            foreach ($ids as $id) {
                try {
                    $result = $fqcn::$fetch_method_name($id);
                } catch (\Throwable $e) {
                    if (!\App\RSpade\Core\Ajax\Ajax::is_coded_failure($e)) {
                        throw $e;
                    }

                    continue;
                }

                if ($result === false || $result === null) {
                    continue;
                }

                // The JavaScript ORM hydrates by __MODEL; an array without it would arrive as
                // a plain object that reads correctly field by field and fails far away.
                if (is_array($result) && !isset($result['__MODEL'])) {
                    throw new \RuntimeException(
                        "Model {$model_name}::{$fetch_method_name}() returned an array without __MODEL property. " .
                        "JavaScript ORM requires __MODEL for class hydration. " .
                        "Fix: Call \$model->toArray() on the Eloquent model first, then augment the array before returning. " .
                        "Example: \$data = \$model->toArray(); \$data['computed_field'] = 'value'; return \$data;"
                    );
                }

                $records[$id] = $result;
            }
        } finally {
            Orm_Fetch_Preload::clear();
        }

        return $records;
    }

    /**
     * The FQCN of a related model whose (portal_)fetch() is a fetch surface, or null when
     * the model is not indexed or its lineage declares no #[Ajax_Endpoint_Model_Fetch]
     * on that method. Null reads as "no related record" at both call sites.
     *
     * @param string $model_name Simple class name of the related model
     * @param string $fetch_method_name 'fetch' or 'portal_fetch'
     * @return string|null
     */
    private static function __fetchable_model_fqcn(string $model_name, string $fetch_method_name): ?string
    {
        $record = Manifest::php_class_metadata($model_name);

        if ($record === null) {
            return null;
        }

        $declaration = Model_Fetch_Lineage::static_declaration(
            $model_name,
            $fetch_method_name,
            'Ajax_Endpoint_Model_Fetch'
        );

        if ($declaration === null) {
            return null;
        }

        return $record['fqcn'];
    }

    /**
     * Resolve which model fetch method this ORM request must call.
     *
     * Portal requests use portal_fetch() (the portal authorization contract, gated
     * on Portal_Session + portal_can_read()); all other requests use fetch() (the
     * staff authorization contract). There is exactly one ORM endpoint - the method
     * name is selected by request context, not by a separate controller.
     *
     * @return string 'portal_fetch' for portal requests, 'fetch' otherwise
     */
    private static function _resolve_fetch_method_name(): string
    {
        return \App\RSpade\Core\Portal\Rsx_Portal::is_portal_request() ? 'portal_fetch' : 'fetch';
    }
}
