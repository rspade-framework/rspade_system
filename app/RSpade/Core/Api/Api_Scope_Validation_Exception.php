<?php

namespace App\RSpade\Core\Api;

use RuntimeException;

/**
 * Api_Scope_Validation_Exception - one API key scope does not satisfy the grammar.
 *
 * Thrown by Api_Scopes::validate() and therefore by every WRITE path that stores a scope:
 * Api_Key_Model::generate(), Api_Key_Model::set_scopes(), the rsx:api:* --scope flag and the
 * application's own key-mint endpoints. Nothing is written when it is thrown - a scope set
 * that cannot be read must never become a credential somebody believes is narrow.
 *
 * The MESSAGE is the human-readable rule that was broken ("a wildcard must be a whole
 * segment: '/api/v1/foo/bar*'"), written for whoever typed the scope rather than for a
 * developer reading a stack trace. An application endpoint hands it straight to
 * response_form_error() and the form shows it beside the field.
 *
 * READING a stored scope never throws. A malformed row that somehow reached the column
 * (hand-edited SQL, a restored dump) is IGNORED for matching and still COUNTS as a
 * registered scope, so the key denies everything and fails closed - see Api_Scopes.
 */
class Api_Scope_Validation_Exception extends RuntimeException
{
}
