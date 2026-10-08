<?php

namespace App\RSpade\Core\Ajax\Exceptions;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Exception thrown when a signed-in identity with a login requirement outstanding calls an
 * endpoint that requirement does not list. Carries where the requirement is done, which
 * becomes the error envelope's metadata.destination. See: php artisan rsx:man login_requirements
 */
#[Instantiatable]
class AjaxRequirementPendingException extends \Exception
{
    private string $destination;

    public function __construct(string $message = 'Please finish signing in to continue', string $destination = '', \Throwable $previous = null)
    {
        parent::__construct($message, 403, $previous);

        $this->destination = $destination;
    }

    public function get_destination(): string
    {
        return $this->destination;
    }
}
