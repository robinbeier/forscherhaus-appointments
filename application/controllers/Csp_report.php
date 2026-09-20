<?php defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'core/Csp_report_only.php';

/** Privacy-safe CSP Report-Only receiver. */
class Csp_report extends CI_Controller
{
    public function index(): void
    {
        if (!Csp_report_only::isCollectorRequest($_SERVER)) {
            $this->output->set_status_header(404)->set_output('');
            return;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            $this->output->set_status_header(405)->set_header('Allow: POST')->set_output('');
            return;
        }

        $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
        $contentType = preg_replace('/;.*\z/', '', $contentType) ?: '';
        if (!in_array($contentType, ['application/csp-report', 'application/reports+json'], true)) {
            $this->output->set_status_header(415)->set_output('');
            return;
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > Csp_report_only::MAX_BODY_BYTES) {
            $this->output->set_status_header(413)->set_output('');
            return;
        }

        $body = file_get_contents('php://input', false, null, 0, Csp_report_only::MAX_BODY_BYTES + 1);
        if (!is_string($body) || strlen($body) > Csp_report_only::MAX_BODY_BYTES) {
            $this->output->set_status_header(413)->set_output('');
            return;
        }

        $config = Csp_report_only::load();
        if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
            $this->output->set_status_header(404)->set_output('');
            return;
        }

        $classified = Csp_report_only::classifyReports(json_decode($body, true), $config);
        if ($classified === null) {
            $this->output->set_status_header(204)->set_output('');
            return;
        }

        foreach ($classified as $report) {
            $result = Csp_report_only::record($report, $config);
            if ($result['status'] === 'error') {
                $this->output->set_status_header(503)->set_output('');
                return;
            }
            if ($result['status'] === 'rate_limited') {
                $this->output->set_status_header(429)->set_output('');
                return;
            }
        }

        $this->output->set_status_header(204)->set_output('');
    }
}
