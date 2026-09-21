<?php defined('BASEPATH') or exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info:
|
|	http://codeigniter.com/user_guide/general/hooks.html
|
*/

require_once APPPATH . 'core/Csp_report_only.php';

$hook['post_controller'] = static function (): void {
    $CI = get_instance();
    if (!is_object($CI)) {
        return;
    }

    // CI_Controller exposes services through __get() without implementing
    // __isset(). Read the magic property explicitly and validate the API
    // before passing it to the policy helpers.
    try {
        $output = $CI->output;
    } catch (Throwable) {
        return;
    }
    if (!is_object($output) || !is_callable([$output, 'set_header'])) {
        return;
    }

    $config = Csp_report_only::load();
    if (!is_array($config)) {
        return;
    }

    $policy = Csp_report_only::policyForRequest(
        $_SERVER,
        $config,
        Csp_report_only::responseContentType($output, $_SERVER),
    );
    if (is_array($policy)) {
        $output->set_header($policy['header']);
    }
};

/* End of file hooks.php */
/* Location: ./application/config/hooks.php */
