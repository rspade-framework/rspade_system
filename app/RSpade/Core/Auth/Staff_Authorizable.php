<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

/**
 * Staff_Authorizable - record-level visibility for STAFF-realm models.
 *
 * The staff-realm twin of Portal_Authorizable. Where that trait answers "may this
 * PORTAL user read this row", this one answers "may this STAFF user see this row",
 * and it exists for the same reason: the question is asked of many models, by many
 * callers, and every model answering it by hand is how the answer drifts.
 *
 * TWO CALL SHAPES, ONE POLICY. A visibility rule has to be expressible both per-row
 * (a loaded model) and per-set (in SQL, before pagination) or every list endpoint
 * re-implements it:
 *
 *   $model->can_view($user)                  - row check
 *   Model::scope_can_view($query, $user)     - set filter, applied in SQL
 *
 * An adopting model that states a real policy MUST state it in BOTH, expressing the
 * same rule - a can_view() that denies what scope_can_view() returns is a leak with
 * a passing test.
 *
 * THE DEFAULT IS PERMISSIVE, AND THAT IS DELIBERATE. The framework does not know any
 * application's visibility rules, so it cannot supply a meaningful restriction; a
 * fail-closed default would instead mean every model in every app must override the
 * pair before anything renders at all. So the trait supplies "visible", and the
 * SEAM - not the policy - is what core owns. A model with a real rule overrides
 * these methods, and a class's own method always beats a trait's, so adopting the
 * trait never shadows a policy the model already states.
 *
 * NO TYPE HINT ON $user, ON PURPOSE. The trait body references NO class at all, so
 * it carries no core->app dependency (the same rule Portal_Authorizable follows).
 * The user model is an app concern - and is itself commonly class-overridden - so
 * naming it here would bind core to a class that may not exist under that namespace.
 * Callers pass their own user object; implementors are free to type their override.
 *
 * @see \App\RSpade\Core\Portal\Portal_Authorizable The portal-realm equivalent.
 */
trait Staff_Authorizable
{
    /**
     * May this user see this row? Permissive default - see the class docblock.
     *
     * An override resolves the session user when $user is null, and should fail
     * LOUD rather than open if no user can be resolved at all.
     *
     * @param mixed $user The staff user to test, or null to mean "the current one".
     * @return bool
     */
    public function can_view($user = null): bool
    {
        return true;
    }

    /**
     * Set-based companion of can_view(), applied in SQL before pagination. MUST
     * express the same policy as can_view(). Permissive default - see the docblock.
     *
     * @param mixed $query The query builder to constrain.
     * @param mixed $user The staff user to test, or null to mean "the current one".
     * @return mixed The query builder, constrained or not.
     */
    public static function scope_can_view($query, $user = null)
    {
        return $query;
    }
}
