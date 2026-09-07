<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Integrations\Jqhtml;

use Illuminate\View\Component;
use RuntimeException;
use App\RSpade\Core\Naming\Rsx_Identifier;
use App\RSpade\Integrations\Jqhtml\Jqhtml;

/**
 * Generic Blade component for rendering jqhtml components
 *
 * Usage: <x-jqhtml component="User_Card" :args="['name' => 'Jim']" />
 */
#[Instantiatable]
class Jqhtml_View_Component extends Component
{
    public string $component;

    public array $args;

    /**
     * Create a new component instance
     *
     * @param string $component The jqhtml component name (e.g., 'User_Card')
     * @param array $args Component arguments
     */
    public function __construct(string $component, array $args = [])
    {
        // Validate the component name shape (Rsx_Identifier is the rule's home)
        if (!Rsx_Identifier::is_class_name($component)) {
            throw new RuntimeException(
                "JQHTML component name '{$component}' " . Rsx_Identifier::CLASS_NAME_RULE . '. ' .
                'This is a hard requirement of the jqhtml library.'
            );
        }

        $this->component = $component;
        $this->args = $args;
    }

    /**
     * Get the view / contents that represent the component
     *
     * @return \Illuminate\Contracts\View\View|string
     */
    public function render()
    {
        // Use the existing Jqhtml helper to render the component
        return Jqhtml::component($this->component, $this->args);
    }
}
