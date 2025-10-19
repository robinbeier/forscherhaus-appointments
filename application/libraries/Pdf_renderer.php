<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.8.0
 * ---------------------------------------------------------------------------- */

use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

/**
 * PDF renderer library.
 *
 * Lightweight wrapper around Dompdf to render and stream HTML templates.
 *
 * @package Libraries
 */
class Pdf_renderer
{
    protected EA_Controller|CI_Controller $CI;

    /**
     * @var string
     */
    protected string $defaultPaper;

    /**
     * @var string
     */
    protected string $defaultOrientation;

    /**
     * @var array
     */
    protected array $dompdfOptions;

    /**
     * Pdf_renderer constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->CI = &get_instance();

        $defaults = [
            'paper' => 'A4',
            'orientation' => 'portrait',
            'options' => [
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled' => false,
                'dpi' => 96,
                'chroot' => FCPATH,
                'fontHeightRatio' => 1.1,
            ],
        ];

        $config = array_replace_recursive($defaults, $config);

        $this->defaultPaper = $config['paper'];
        $this->defaultOrientation = $config['orientation'];
        $this->dompdfOptions = $config['options'];
    }

    /**
     * Render a view into a PDF binary string.
     *
     * @param string $view
     * @param array $data
     * @param array $options
     *
     * @return string
     */
    public function render_view(string $view, array $data = [], array $options = []): string
    {
        $html = $this->CI->load->view($view, $data, true);

        $renderOptions = $options;
        $this->dumpDebugHtmlIfRequested($html, $renderOptions);

        return $this->render_html($html, $renderOptions);
    }

    /**
     * Render an HTML string into a PDF binary string.
     *
     * @param string $html
     * @param array $options
     *
     * @return string
     */
    public function render_html(string $html, array $options = []): string
    {
        $dompdf = $this->buildDompdf($html, $options);

        return $dompdf->output();
    }

    /**
     * Stream an HTML view as PDF to the browser.
     *
     * @param string $view
     * @param array $data
     * @param string $filename
     * @param array $options
     */
    public function stream_view(string $view, array $data, string $filename, array $options = []): void
    {
        $html = $this->CI->load->view($view, $data, true);

        $renderOptions = $options;
        $this->dumpDebugHtmlIfRequested($html, $renderOptions);

        $this->stream_html($html, $filename, $renderOptions);
    }

    /**
     * Stream an HTML string as PDF to the browser.
     *
     * @param string $html
     * @param string $filename
     * @param array $options
     */
    public function stream_html(string $html, string $filename, array $options = []): void
    {
        $dompdf = $this->buildDompdf($html, $options);

        $attachment = array_key_exists('attachment', $options) ? (bool) $options['attachment'] : true;

        $dompdf->stream($filename, [
            'Attachment' => $attachment,
        ]);
    }

    /**
     * Dump the generated HTML to a file when requested via debug option.
     */
    protected function dumpDebugHtmlIfRequested(string $html, array &$options): void
    {
        if (empty($options['debug_dump_path'])) {
            return;
        }

        $path = (string) $options['debug_dump_path'];
        unset($options['debug_dump_path']);

        if ($path === '') {
            return;
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            log_message('error', 'Pdf_renderer debug dump failed to create directory: ' . $directory);

            return;
        }

        try {
            if (@file_put_contents($path, $html) === false) {
                log_message('error', 'Pdf_renderer debug dump failed to write file: ' . $path);
            }
        } catch (Throwable $exception) {
            log_message('error', 'Pdf_renderer debug dump failed: ' . $exception->getMessage());
        }
    }

    /**
     * Convert an image file into a base64 data URL.
     *
     * @param string $path
     *
     * @return string|null
     */
    public function image_to_data_url(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $data = @file_get_contents($path);

        if ($data === false) {
            return null;
        }

        $mime = mime_content_type($path);

        if (!$mime) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            $mime = match ($extension) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml',
                default => 'application/octet-stream',
            };
        }

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    /**
     * Build a Dompdf instance configured with the provided HTML.
     *
     * @param string $html
     * @param array $options
     *
     * @return Dompdf
     */
    protected function buildDompdf(string $html, array $options = []): Dompdf
    {
        $dompdf = new Dompdf($this->createOptions($options));

        $dompdf->loadHtml($html);

        $paper = $options['paper'] ?? $this->defaultPaper;
        $orientation = $options['orientation'] ?? $this->defaultOrientation;

        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return $dompdf;
    }

    /**
     * Create Dompdf options from the configured defaults.
     *
     * @param array $options
     *
     * @return Options
     */
    protected function createOptions(array $options = []): Options
    {
        $config = array_replace_recursive($this->dompdfOptions, $options['options'] ?? []);

        $dompdfOptions = new Options();

        if (array_key_exists('tempDir', $config) && is_string($config['tempDir'])) {
            $dompdfOptions->setTempDir($config['tempDir']);
        }

        if (array_key_exists('chroot', $config) && is_array($config['chroot'])) {
            $dompdfOptions->setChroot($config['chroot']);
        } elseif (array_key_exists('chroot', $config) && is_string($config['chroot'])) {
            $dompdfOptions->setChroot([$config['chroot']]);
        }

        if (array_key_exists('defaultFont', $config)) {
            $dompdfOptions->setDefaultFont($config['defaultFont']);
        }

        if (array_key_exists('isRemoteEnabled', $config)) {
            $dompdfOptions->setIsRemoteEnabled((bool) $config['isRemoteEnabled']);
        }

        if (array_key_exists('isHtml5ParserEnabled', $config)) {
            $dompdfOptions->setIsHtml5ParserEnabled((bool) $config['isHtml5ParserEnabled']);
        }

        if (array_key_exists('isPhpEnabled', $config)) {
            $dompdfOptions->setIsPhpEnabled((bool) $config['isPhpEnabled']);
        }

        if (array_key_exists('dpi', $config)) {
            $dompdfOptions->setDpi((int) $config['dpi']);
        }

        if (array_key_exists('fontHeightRatio', $config)) {
            $dompdfOptions->setFontHeightRatio((float) $config['fontHeightRatio']);
        }

        if (array_key_exists('enableFontSubsetting', $config)) {
            $dompdfOptions->setIsFontSubsettingEnabled((bool) $config['enableFontSubsetting']);
        }

        return $dompdfOptions;
    }
}
