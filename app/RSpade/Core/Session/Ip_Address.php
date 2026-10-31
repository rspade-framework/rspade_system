<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;

/**
 * Ip_Address - every client IP address the framework records, stored once.
 *
 * _ip_addresses holds one row per distinct address. Every system table that records where
 * something came from stores the id of that row, never a copy of the string:
 *
 *     _sessions.ip_address_id                    the address that created the session row
 *     _login_history.ip_address_id               the address a sign-in came from
 *     _portal_login_history.ip_address_id        the address a portal sign-in came from
 *     _site_sign_ins.ip_address_id               the address a sign-in entered a site from
 *     _audit_sessions.ip_address_id              the address of an acting context
 *     _file_attachments.created_by_ip_address_id the address an upload came from
 *     _api_request_log.ip_address_id             the address of an external API call
 *     _transactions.ip_address_id                the address a unit of work came from
 *
 * GEOCODING. Each row carries city, state, country, latitude and longitude. They exist so
 * an address is located once and every record that references it shares the answer. The
 * framework does not ship a geocoder yet: today every one of those fields is null. A
 * resolver that fills them is coming; when it lands, describe() and join() answer its
 * results with no change to their shape.
 *
 * Rows are never deleted - a row is where an address's geocode lives, and it stays
 * referenced by audit sessions, which are permanent.
 *
 * _ip_addresses is a SYSTEM table: application code never names it. It reads an address
 * through this class (describe(), address()), through the framework's own answers (the
 * session listings, Login_History, Session::get_audit_session()), or, in bulk, by joining
 * it onto a query of its own with join().
 */
class Ip_Address
{
    /**
     * What a joined address answers, by published name: the column behind each, on the
     * row join() adds. The names are describe()'s keys.
     */
    private const JOINED_FIELDS = [
        'ip_address',
        'city',
        'state',
        'country',
        'latitude',
        'longitude',
    ];

    /**
     * Addresses already resolved by this process: address => id.
     *
     * @var array<string, int>
     */
    private static array $ids = [];

    /**
     * Addresses already read by this process: id => address (null: no such row).
     *
     * @var array<int, string|null>
     */
    private static array $addresses = [];

    /**
     * The id of an address in _ip_addresses, recording it on first sight - or null for no
     * address at all (null, '', and 'CLI' - the placeholder for "no request").
     *
     * One statement finds or creates: a concurrent request with the same address lands on
     * the unique key and both read the same id.
     *
     * @param string|null $ip_address
     * @return int|null
     */
    public static function id(?string $ip_address): ?int
    {
        if ($ip_address === null || $ip_address === '' || $ip_address === 'CLI') {
            return null;
        }

        if (isset(self::$ids[$ip_address])) {
            return self::$ids[$ip_address];
        }

        DB::statement(
            'INSERT INTO _ip_addresses (ip_address) VALUES (?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$ip_address]
        );

        $id = (int) DB::getPdo()->lastInsertId();

        self::$ids[$ip_address] = $id;
        self::$addresses[$id] = $ip_address;

        return $id;
    }

    /**
     * The address behind an _ip_addresses id, or null for no id.
     *
     * @param int|null $ip_address_id
     * @return string|null
     */
    public static function address(?int $ip_address_id): ?string
    {
        if (empty($ip_address_id)) {
            return null;
        }

        if (!array_key_exists($ip_address_id, self::$addresses)) {
            $row = DB::selectOne('SELECT ip_address FROM _ip_addresses WHERE id = ?', [$ip_address_id]);
            self::$addresses[$ip_address_id] = $row === null ? null : $row->ip_address;
        }

        return self::$addresses[$ip_address_id];
    }

    /**
     * One address with what is known about it:
     *
     *     ['ip_address' => '203.0.113.7', 'city' => null, 'state' => null,
     *      'country' => null, 'latitude' => null, 'longitude' => null]
     *
     * The geocode fields are null until a geocoder has located the address (none ships
     * yet - see the class docblock). Null when there is no such row.
     *
     * @param int $ip_address_id
     * @return array|null
     */
    public static function describe(int $ip_address_id): ?array
    {
        $row = DB::selectOne('SELECT * FROM _ip_addresses WHERE id = ?', [$ip_address_id]);

        if ($row === null) {
            return null;
        }

        $float = fn ($value) => $value === null ? null : (float) $value;

        return [
            'ip_address' => $row->ip_address,
            'city' => $row->city,
            'state' => $row->state,
            'country' => $row->country,
            'latitude' => $float($row->latitude),
            'longitude' => $float($row->longitude),
        ];
    }

    /**
     * Join the address onto a query of your own, so a list can show, search and sort by
     * an address and where it is.
     *
     *     $query = Visit_Log_Model::query();
     *     Ip_Address::join($query, 'visit_logs.ip_address_id', 'visitor');
     *
     *     $query->where(Ip_Address::column('visitor', 'ip_address'), 'like', $like)
     *           ->orderBy(Ip_Address::column('visitor', 'country'));
     *
     *     foreach ($query->limit(25)->get() as $log) {
     *         $log->visitor_ip_address;   $log->visitor_city;   $log->visitor_country;
     *     }
     *
     * $column is YOUR column holding an Ip_Address::id() value. $prefix names this join:
     * it is the table alias and the prefix of every selected field, so one query may join
     * several addresses under different prefixes. The join is a LEFT join, so a row with
     * no address is still listed (its values are null). Every published field is added to
     * the select as `<prefix>_<name>` - describe()'s keys. A query that selected nothing
     * explicitly keeps its own table's columns.
     *
     * The table behind it is the framework's and is never named in application code: a
     * condition or an ordering on an address field is written with column().
     *
     * For audit sessions, Audit_Session::join() already answers the address as
     * audit_ip_address.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param string $column The qualified column holding the address id ('table.column')
     * @param string $prefix The alias for this join and the prefix of its selected fields
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder The same query
     */
    public static function join($query, string $column, string $prefix)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException(
                "Ip_Address::join(): '{$column}' is not a qualified column. Name the table and the column holding the address id, e.g. 'visit_logs.ip_address_id'."
            );
        }

        self::__validate_prefix($prefix);

        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;

        // Adding a select to a query that had none would REPLACE its implicit `*`.
        if (empty($base->columns)) {
            $query->select($base->from . '.*');
        }

        $query->leftJoin('_ip_addresses as ' . $prefix, $prefix . '.id', '=', $column);

        foreach (self::JOINED_FIELDS as $name) {
            $query->addSelect($prefix . '.' . $name . ' as ' . $prefix . '_' . $name);
        }

        return $query;
    }

    /**
     * The expression for one address field in a query join() was applied to under
     * $prefix - for a where(), an orderBy() or a groupBy(). $name is one of describe()'s
     * keys.
     *
     * @param string $prefix
     * @param string $name
     * @return string
     */
    public static function column(string $prefix, string $name): string
    {
        self::__validate_prefix($prefix);

        if (!in_array($name, self::JOINED_FIELDS, true)) {
            throw new \InvalidArgumentException(
                "Ip_Address::column(): no address field '{$name}'. Fields: " . implode(', ', self::JOINED_FIELDS) . '.'
            );
        }

        return $prefix . '.' . $name;
    }

    /**
     * Forget every id and address this process has resolved.
     *
     * A row minted inside a database transaction that then rolls back no longer exists,
     * and the memo would go on answering its id. Called on every rollback
     * (Transaction_Rollback_Cache_Reset) and between tests.
     *
     * FRAMEWORK INTERNAL.
     *
     * @return void
     */
    public static function _reset(): void
    {
        self::$ids = [];
        self::$addresses = [];
    }

    /**
     * @param string $prefix
     * @return void
     */
    private static function __validate_prefix(string $prefix): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException(
                "Ip_Address: '{$prefix}' is not a usable prefix. Use a plain identifier, e.g. 'visitor'."
            );
        }
    }
}
