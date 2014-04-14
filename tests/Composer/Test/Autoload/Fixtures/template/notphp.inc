/**
 * AJAX interface for remembering user preferences set on the fly
 *
 * Match /preferences/ajax/
 */
'preferences_ajax' => array
(
    'handler' => array ('midgard_admin_asgard_handler_preferences', 'ajax'),
    'fixed_args' => array('preferences', 'ajax'),
    'variable_args' => 0,
),
