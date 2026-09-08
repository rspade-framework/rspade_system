<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Model_Fetch_Lineage - which class in a model's ancestry actually declares its ORM surface.
 *
 * WHY THIS EXISTS. The manifest indexes attributes PER FILE, and a file record lists only
 * the methods its own class DECLARES. Everything that reads `#[Ajax_Endpoint_Model_Fetch]`
 * therefore used to ask one file record whether the model has a fetch surface - which is the
 * right question only while every model is a single file.
 *
 * It is not. A core model is an abstract base carrying every member plus a three-line
 * concrete an application replaces, and an application's own override is a class that
 * extends the base and declares only what it changes. In both shapes `fetch()` is declared
 * one link up, and a per-file answer says the model has no fetch surface at all - the ORM
 * endpoint answers "missing Ajax_Endpoint_Model_Fetch attribute" for a model whose fetch()
 * is right there and running.
 *
 * So the surface is resolved the way `get_relationships()` already resolves `#[Relationship]`
 * and the auth-check registry already resolves `#[Auth_Check]`: by climbing the lineage and
 * taking the NEAREST declaration. An override that redeclares `fetch()` wins over the base,
 * which is exactly what an override is for.
 *
 * SECURITY NOTE. This widens WHERE a declaration may live; it never widens what a
 * declaration means. The `#[Auth]` gates recorded for the surface are still the ones the
 * declaring method and its declaring class carry, and the model's own body still applies
 * every record-level rule. A model with no attributed `fetch()` anywhere in its lineage
 * still has no fetch surface.
 *
 * @see \App\RSpade\Core\Auth\Auth_ManifestSupport - records the surface for the concrete.
 * @see \App\RSpade\Core\Database\Orm_Controller - the dispatcher that reads it.
 * @see rsx:man model_fetch
 */
class Model_Fetch_Lineage
{
    /**
     * The nearest ancestor (starting with the class itself) that declares a STATIC method of
     * this name carrying the given attribute.
     *
     * @param string $class_name  Simple class name of the model.
     * @param string $method_name e.g. 'fetch' or 'portal_fetch'.
     * @param string $attribute   Attribute simple name the declaration must carry.
     * @return array{class: string, file: string, method: array}|null
     */
    public static function static_declaration(string $class_name, string $method_name, string $attribute): ?array
    {
        return static::__climb(
            static::__runtime_resolver(),
            $class_name,
            $method_name,
            $attribute,
            'public_static_methods'
        );
    }

    /**
     * The nearest ancestor (starting with the class itself) that declares an INSTANCE method
     * of this name carrying the given attribute. Fetchable relationships live here.
     *
     * @return array{class: string, file: string, method: array}|null
     */
    public static function instance_declaration(string $class_name, string $method_name, string $attribute): ?array
    {
        return static::__climb(
            static::__runtime_resolver(),
            $class_name,
            $method_name,
            $attribute,
            'public_instance_methods'
        );
    }

    /**
     * The same climb over a map the caller already holds - `class simple name => file
     * record`, each record carrying its own `file` key. The manifest BUILD uses this: the
     * index it is assembling is the only correct answer at that moment, and it must not be
     * read back through the accessors of a half-written one.
     *
     * @param array<string, array> $records_by_class
     * @param string $map_key 'public_static_methods' or 'public_instance_methods'
     * @return array{class: string, file: string, method: array}|null
     */
    public static function declaration_in(
        array $records_by_class,
        string $class_name,
        string $method_name,
        string $attribute,
        string $map_key
    ): ?array {
        return static::__climb(
            static fn (string $name) => $records_by_class[$name] ?? null,
            $class_name,
            $method_name,
            $attribute,
            $map_key
        );
    }

    /**
     * Resolve one simple class name to its indexed FILE record through the Manifest
     * accessors, loading the cold half when the record lives there.
     */
    private static function __runtime_resolver(): callable
    {
        return static function (string $name): ?array {
            $class_record = Manifest::php_class_metadata($name);

            if ($class_record === null) {
                return null;
            }

            $file = $class_record['file'] ?? null;

            if ($file === null) {
                return null;
            }

            return Manifest::get_file($file);
        };
    }

    /**
     * The climb. Stops at the first class the resolver does not know (Eloquent's Model has
     * no manifest entry, which is the natural terminator) and on a cycle.
     *
     * @return array{class: string, file: string, method: array}|null
     */
    private static function __climb(
        callable $resolver,
        string $class_name,
        string $method_name,
        string $attribute,
        string $map_key
    ): ?array {
        $seen = [];
        $current = $class_name;

        while ($current !== null && $current !== '' && !isset($seen[$current])) {
            $seen[$current] = true;

            $record = $resolver($current);

            if ($record === null) {
                return null;
            }

            $method = $record[$map_key][$method_name] ?? null;

            if ($method !== null && isset($method['attributes'][$attribute])) {
                return [
                    'class' => $current,
                    'file' => $record['file'] ?? '',
                    'method' => $method,
                ];
            }

            $current = $record['extends'] ?? null;
        }

        return null;
    }
}
