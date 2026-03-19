<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

if (!function_exists('request')) {
    /**
     * Gets the value of a request variable.
     *
     * Example:
     *
     * $first_name = request('first_name', 'John');
     *
     * @param string|null $key Request variable key.
     * @param mixed|null $default Default value in case the requested variable has no value.
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    function request(?string $key = null, $default = null): mixed
    {
        /** @var EA_Controller $CI */
        $CI = &get_instance();

        if (empty($key)) {
            $payload = $CI->input->post_get($key);

            if (empty($payload)) {
                $payload = $CI->input->json($key);
            }

            return $payload;
        }

        return $CI->input->post_get($key) ?? ($CI->input->json($key) ?? $default);
    }
}

if (!function_exists('response')) {
    /**
     * Return a new response from the application.
     *
     * Example:
     *
     * response('This is the response content', 200, []);
     *
     * @param string $content
     * @param int $status
     * @param array $headers
     */
    function response(string $content = '', int $status = 200, array $headers = []): void
    {
        /** @var EA_Controller $CI */
        $CI = &get_instance();

        foreach ($headers as $header) {
            $CI->output->set_header($header);
        }

        $CI->output->set_status_header($status)->set_output($content);
    }
}

if (!function_exists('json_response')) {
    /**
     * Return a new response from the application.
     *
     * Example:
     *
     * json_response([
     *  'message' => 'This is a JSON property.'
     * ]);
     *
     * @param array $content
     * @param int $status
     * @param array $headers
     */
    function json_response(array $content = [], int $status = 200, array $headers = []): void
    {
        /** @var EA_Controller $CI */
        $CI = &get_instance();

        foreach ($headers as $header) {
            $CI->output->set_header($header);
        }

        $CI->output
            ->set_status_header($status)
            ->set_content_type('application/json')
            ->set_output(json_encode($content));
    }
}

if (!function_exists('json_exception')) {
    /**
     * Return a new json exception from the application.
     *
     * Example:
     *
     * json_exception($exception); // Add this in a catch block to return the exception information.
     *
     * @param Throwable $e
     */
    function json_exception(Throwable $e): void
    {
        $response = [
            'success' => false,
            'message' => $e->getMessage(),
            'trace' => trace($e),
        ];

        $logger = load_class('Log', 'core');
        $log_summary = json_exception_log_summary($e);

        if (is_object($logger) && method_exists($logger, 'write_log')) {
            $logger->write_log('error', $log_summary);
        } else {
            log_message('error', $log_summary);
        }

        unset($response['trace']); // Do not send the trace to the browser as it might contain sensitive info

        json_response($response, 500);
    }
}

if (!function_exists('json_exception_log_summary')) {
    /**
     * Build a compact log summary for JSON exceptions without embedding the full trace payload.
     *
     * @param Throwable $e
     */
    function json_exception_log_summary(Throwable $e): string
    {
        $sanitize_for_log = static function (string $value, int $max_length = 512): string {
            $normalized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
            $normalized = is_string($normalized) ? trim($normalized) : '';

            return substr($normalized, 0, $max_length);
        };

        $summarize_frame = static function (?array $frame) use ($sanitize_for_log): string {
            if (!is_array($frame)) {
                return '';
            }

            $function = trim((string) (($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')));
            $location = trim(
                (string) (($frame['file'] ?? '') . (isset($frame['line']) ? ':' . (string) $frame['line'] : '')),
            );

            if ($function === '' && $location === '') {
                return '';
            }

            return $sanitize_for_log(trim($function . ($location !== '' ? '@' . $location : '')), 256);
        };

        $trace = $e->getTrace();
        $trace_summary = [];

        foreach (array_slice($trace, 0, 3) as $frame) {
            $frame_summary = $summarize_frame($frame);

            if ($frame_summary !== '') {
                $trace_summary[] = $frame_summary;
            }
        }

        $log_parts = [
            'JSON exception:',
            'class=' . get_class($e),
            'code=' . (string) $e->getCode(),
            'message=' . $sanitize_for_log($e->getMessage(), 384),
            'file=' . $sanitize_for_log($e->getFile(), 256),
            'line=' . (string) $e->getLine(),
            'frame_count=' . (string) count($trace),
        ];

        if ($trace_summary !== []) {
            $log_parts[] = 'trace_summary=' . $sanitize_for_log(implode(' <= ', $trace_summary), 384);
        }

        return implode(' ', $log_parts);
    }
}

if (!function_exists('abort')) {
    /**
     * Throw an HttpException with the given data.
     *
     * Example:
     *
     * if ($error) abort(500);
     *
     * @param int $code
     * @param string $message
     * @param array $headers
     *
     * @return void
     */
    function abort(int $code, string $message = '', array $headers = []): void
    {
        /** @var EA_Controller $CI */
        $CI = &get_instance();

        foreach ($headers as $header) {
            $CI->output->set_header($header);
        }

        show_error($message, $code);
    }
}

if (!function_exists('trace')) {
    /**
     * Prepare a well formatted string for an exception
     *
     * @param Throwable $e
     *
     * @return string
     */
    function trace(Throwable $e): string
    {
        $max_frames = 25;
        $max_args_per_frame = 5;
        $max_trace_length = 16000;
        $raw_trace = $e->getTrace();
        $frames = [];

        $summarize_arg = static function (mixed $arg): array {
            if (is_array($arg)) {
                $sample_keys = [];
                $sampled_keys = 0;

                foreach ($arg as $key => $_unused) {
                    $sample_keys[] = (string) $key;
                    $sampled_keys++;

                    if ($sampled_keys >= 3) {
                        break;
                    }
                }

                return [
                    'type' => 'array',
                    'count' => count($arg),
                    'sample_keys' => $sample_keys,
                ];
            }

            if (is_object($arg)) {
                return [
                    'type' => 'object',
                    'class' => get_class($arg),
                ];
            }

            if (is_string($arg)) {
                return [
                    'type' => 'string',
                    'length' => strlen($arg),
                ];
            }

            if (is_int($arg)) {
                return ['type' => 'int'];
            }

            if (is_float($arg)) {
                return ['type' => 'float'];
            }

            if (is_bool($arg)) {
                return ['type' => 'bool'];
            }

            if ($arg === null) {
                return ['type' => 'null'];
            }

            if (is_resource($arg)) {
                return ['type' => 'resource'];
            }

            return ['type' => gettype($arg)];
        };

        foreach (array_slice($raw_trace, 0, $max_frames) as $entry) {
            $frame = [
                'file' => array_key_exists('file', $entry) ? (string) $entry['file'] : null,
                'line' => array_key_exists('line', $entry) ? (int) $entry['line'] : null,
                'class' => array_key_exists('class', $entry) ? (string) $entry['class'] : null,
                'type' => array_key_exists('type', $entry) ? (string) $entry['type'] : null,
                'function' => array_key_exists('function', $entry) ? (string) $entry['function'] : null,
                'arg_count' => 0,
                'args' => [],
                'args_truncated' => false,
            ];

            if (!empty($entry['args']) && is_array($entry['args'])) {
                $frame['arg_count'] = count($entry['args']);
                $frame['args'] = array_map($summarize_arg, array_slice($entry['args'], 0, $max_args_per_frame));
                $frame['args_truncated'] = $frame['arg_count'] > $max_args_per_frame;
            }

            $frames[] = $frame;
        }

        $trace_summary = [
            'exception_class' => get_class($e),
            'exception_code' => (string) $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'frame_count' => count($raw_trace),
            'frames' => $frames,
            'truncated' => count($raw_trace) > $max_frames,
        ];

        $encode_trace = static function (array $payload): string|false {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        };

        $encoded_trace = $encode_trace($trace_summary);

        if (!is_string($encoded_trace)) {
            return sprintf('trace_unavailable:%s@%s:%d', get_class($e), $e->getFile(), $e->getLine());
        }

        if (strlen($encoded_trace) > $max_trace_length) {
            $trace_summary['size_truncated'] = true;

            while (!empty($trace_summary['frames'])) {
                array_pop($trace_summary['frames']);
                $encoded_trace = $encode_trace($trace_summary);

                if (is_string($encoded_trace) && strlen($encoded_trace) <= $max_trace_length) {
                    return $encoded_trace;
                }
            }

            $encoded_trace = $encode_trace([
                'exception_class' => get_class($e),
                'exception_code' => (string) $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'frame_count' => count($raw_trace),
                'frames' => [],
                'truncated' => true,
                'size_truncated' => true,
            ]);

            if (!is_string($encoded_trace)) {
                return sprintf('trace_unavailable:%s@%s:%d', get_class($e), $e->getFile(), $e->getLine());
            }
        }

        return $encoded_trace;
    }
}
