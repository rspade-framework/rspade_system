<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

/**
 * Source fixture for the API-GET-PURE-01 scan rule (Api_Scan_Validation_Test).
 *
 * The rule reads a handler's BODY from disk, so it cannot be provoked from a synthetic
 * manifest entry alone - the entry must point at a real file. This class is that file: it
 * carries no #[Api_Endpoint] of its own (the test's synthetic manifest supplies the
 * attributes), so nothing here is ever routed, dispatched or executed.
 */
class Api_Get_Pure_Fixture
{
    /**
     * A GET handler that writes. The rule must refuse this one.
     */
    public static function mutating_get(array $params = [])
    {
        $record = static::__record($params);
        $record->save();

        return $record;
    }

    /**
     * A GET handler that writes and says why.
     *
     * @API-GET-PURE-01-EXCEPTION Records the read receipt this endpoint exists to hand back.
     */
    public static function excepted_get(array $params = [])
    {
        $record = static::__record($params);
        $record->save();

        return $record;
    }

    /**
     * A GET handler claiming the exception with no rationale. The rule must refuse this one.
     *
     * @API-GET-PURE-01-EXCEPTION
     */
    public static function bare_tag_get(array $params = [])
    {
        $record = static::__record($params);
        $record->save();

        return $record;
    }

    /**
     * A pure GET handler. The write tokens below are a comment and a string, which is the
     * point: neither may trip the rule.
     */
    public static function pure_get(array $params = [])
    {
        // This endpoint never calls ->save( on anything.
        $note = 'the caller may follow up with ->delete( on the POST endpoint';

        return ['record' => static::__record($params), 'note' => $note];
    }

    /**
     * A pure GET handler named after a reserved word. The lexer does not make "list" a
     * T_STRING, which once hid every list handler from the rule.
     */
    public static function list(array $params = [])
    {
        return ['records' => [static::__record($params)]];
    }

    /**
     * A writing GET handler named after a reserved word. The rule must refuse this one.
     */
    public static function print(array $params = [])
    {
        $record = static::__record($params);
        $record->save();

        return $record;
    }

    /**
     * A GET handler writing through a relation pivot, spelled in odd case. Refused.
     */
    public static function pivot_get(array $params = [])
    {
        $record = static::__record($params);
        $record->Tags()->ATTACH($params['tag_id'] ?? 0);

        return $record;
    }

    /**
     * Stand-in for whatever a real handler would load.
     */
    private static function __record(array $params)
    {
        return (object) ['id' => $params['id'] ?? null];
    }
}
