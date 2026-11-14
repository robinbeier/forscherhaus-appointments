<?php use JetBrains\PhpStorm\NoReturn;

defined('BASEPATH') or exit('No direct script access allowed');

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

/**
 * Api library.
 *
 * Handles API related functionality.
 *
 * @package Libraries
 */
class Api
{
    private const MAX_WITH_TOKENS = 16;

    private const MAX_RAW_WITH_TOKENS = 64;

    private const MAX_WITH_TOKEN_LENGTH = 64;

    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * @var int
     */
    protected int $default_length = 20;

    /**
     * @var EA_Model
     */
    protected EA_Model $model;

    protected Request_normalizer $request_normalizer;

    /**
     * Api constructor.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->library('accounts');
        $this->CI->load->library('request_normalizer');

        $this->request_normalizer = $this->CI->request_normalizer;
    }

    /**
     * Load and use the provided model class.
     *
     * @param string $model
     */
    public function model(string $model): void
    {
        // Keep the API model surface explicit. Apart from preventing arbitrary
        // loader paths, literal calls let the component-boundary checker prove
        // each API dependency without trusting caller input.
        switch ($model) {
            case 'admins_model':
                $this->CI->load->model('admins_model');
                $this->model = $this->CI->admins_model;
                return;

            case 'appointments_model':
                $this->CI->load->model('appointments_model');
                $this->model = $this->CI->appointments_model;
                return;

            case 'blocked_periods_model':
                $this->CI->load->model('blocked_periods_model');
                $this->model = $this->CI->blocked_periods_model;
                return;

            case 'customers_model':
                $this->CI->load->model('customers_model');
                $this->model = $this->CI->customers_model;
                return;

            case 'providers_model':
                $this->CI->load->model('providers_model');
                $this->model = $this->CI->providers_model;
                return;

            case 'secretaries_model':
                $this->CI->load->model('secretaries_model');
                $this->model = $this->CI->secretaries_model;
                return;

            case 'service_categories_model':
                $this->CI->load->model('service_categories_model');
                $this->model = $this->CI->service_categories_model;
                return;

            case 'services_model':
                $this->CI->load->model('services_model');
                $this->model = $this->CI->services_model;
                return;

            case 'settings_model':
                $this->CI->load->model('settings_model');
                $this->model = $this->CI->settings_model;
                return;

            case 'unavailabilities_model':
                $this->CI->load->model('unavailabilities_model');
                $this->model = $this->CI->unavailabilities_model;
                return;

            default:
                throw new InvalidArgumentException('Unsupported API model.');
        }
    }

    /**
     * Authorize the API request (Basic Auth or Bearer Token supported).
     */
    public function auth(): void
    {
        try {
            // Bearer token.
            $api_token = setting('api_token');

            if (empty($api_token) || $api_token !== $this->get_bearer_token()) {
                // Basic auth.
                $username = $_SERVER['PHP_AUTH_USER'] ?? null;

                $password = $_SERVER['PHP_AUTH_PW'] ?? null;

                $user_data = $this->CI->accounts->check_login($username, $password);

                if (empty($user_data['role_slug']) || $user_data['role_slug'] !== DB_SLUG_ADMIN) {
                    throw new RuntimeException(
                        'The provided credentials do not match any admin user!',
                        401,
                        'Unauthorized',
                    );
                }
            }
        } catch (Throwable) {
            $this->request_authentication();
        }

        // Preserve the 401 contract for unauthenticated writes before checking
        // the body type of an authenticated mutation.
        $this->rejectNonJsonMutation();
    }

    /**
     * Browser-simple form submissions must not reach authenticated API writes.
     *
     * The API still supports Basic authentication for existing server clients,
     * but routed POST/PUT writes require JSON so a cross-site HTML form cannot
     * manufacture an authenticated mutation. A bodyless DELETE remains valid.
     */
    private function rejectNonJsonMutation(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        $action = strtolower((string) ($this->CI->router->method ?? ''));
        $action_method = ['store' => 'POST', 'update' => 'PUT', 'destroy' => 'DELETE'][$action] ?? null;

        // Let the controller return its existing 405 for a wrong method.
        if ($method !== $action_method) {
            return;
        }

        $content_type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if (self::acceptsMutationContentType($method, $content_type, $content_length)) {
            return;
        }

        json_response(
            [
                'success' => false,
                'message' => 'API mutation requests require application/json.',
            ],
            415,
        );

        $this->CI->output->_display();

        exit();
    }

    private static function acceptsMutationContentType(string $method, string $content_type, int $content_length): bool
    {
        $content_type = strtolower(trim($content_type));

        // A bodyless DELETE is legitimate. POST/PUT without a declared body
        // type still need JSON: a chunked body can omit Content-Length.
        if (
            $method === 'DELETE' &&
            $content_type === '' &&
            $content_length === 0 &&
            !isset($_SERVER['HTTP_TRANSFER_ENCODING'])
        ) {
            return true;
        }

        return (bool) preg_match('/^application\\/json(?:\\s*;|$)/', $content_type);
    }

    /**
     * Returns the bearer token value.
     *
     * @return string|null
     */
    protected function get_bearer_token(): ?string
    {
        $headers = $this->get_authorization_header();

        // HEADER: Get the access token from the header

        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Returns the authorization header.
     *
     * @return string|null
     */
    protected function get_authorization_header(): ?string
    {
        $headers = null;

        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER['Authorization']);
        } else {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                // Nginx or fast CGI
                $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
            } elseif (function_exists('apache_request_headers')) {
                $requestHeaders = apache_request_headers();

                // Server-side fix for bug in old Android versions (a nice side effect of this fix means we don't care
                // about capitalization for Authorization).
                $requestHeaders = array_combine(
                    array_map('ucwords', array_keys($requestHeaders)),
                    array_values($requestHeaders),
                );

                if (isset($requestHeaders['Authorization'])) {
                    $headers = trim($requestHeaders['Authorization']);
                }
            }
        }

        return $headers;
    }

    /**
     * Sets request authentication headers.
     */
    #[NoReturn]
    public function request_authentication(): void
    {
        header('WWW-Authenticate: Basic realm="Easy!Appointments"');
        header('HTTP/1.0 401 Unauthorized');
        exit('You are not authorized to use the API.');
    }

    /**
     * Get the search keyword value of the current request.
     *
     * @return string|null
     */
    public function request_keyword(): ?string
    {
        return $this->request_normalizer->normalizeString(request('q'), null, true);
    }

    /**
     * Get the limit value of the current request.
     *
     * @return int|null
     */
    public function request_limit(): ?int
    {
        $length = $this->request_normalizer->normalizeInt(
            request('length', $this->default_length),
            $this->default_length,
        );

        if ($length === null || $length < 0) {
            return $this->default_length;
        }

        return $length;
    }

    /**
     * Get the limit value of the current request.
     *
     * @return int|null
     */
    public function request_offset(): ?int
    {
        $page = $this->request_normalizer->normalizePositiveInt(request('page', 1), 1) ?? 1;
        $length = $this->request_limit() ?? $this->default_length;

        if ($length <= 0) {
            return 0;
        }

        return ($page - 1) * $length;
    }

    /**
     * Get the order by value of the current request.
     *
     * @return string|null
     */
    public function request_order_by(): ?string
    {
        $sort_tokens = $this->normalizeCsvTokens(request('sort'));

        if (empty($sort_tokens)) {
            return null;
        }

        $order_by = [];

        foreach ($sort_tokens as $sort_token) {
            $api_field = substr($sort_token, 1);

            $direction_operator = substr($sort_token, 0, 1);

            if (!in_array($direction_operator, ['-', '+'])) {
                $direction_operator = '+';
                $api_field = $sort_token;
            }

            $db_field = $this->model->db_field($api_field);

            $direction = $direction_operator === '-' ? 'DESC' : 'ASC';

            $order_by[] = $db_field . ' ' . $direction;
        }

        return implode(', ', $order_by);
    }

    /**
     * Get the chosen "fields" array of the current request.
     *
     * @return array|null
     */
    public function request_fields(): ?array
    {
        $fields = $this->normalizeCsvTokens(request('fields'));

        if (empty($fields)) {
            return null;
        }

        return $fields;
    }

    /**
     * Get the provided "with" array of the current request.
     *
     * @return array|null
     */
    public function request_with(): ?array
    {
        $rawWith = request('with');

        if ($this->rawCsvTokenCount($rawWith) > self::MAX_RAW_WITH_TOKENS) {
            throw new InvalidArgumentException(
                sprintf('The with parameter must contain at most %d relation tokens.', self::MAX_RAW_WITH_TOKENS),
            );
        }

        $with = $this->normalizeCsvTokens($rawWith);

        if (empty($with)) {
            return null;
        }

        $unique = [];
        $seen = [];

        foreach ($with as $token) {
            if (strlen($token) > self::MAX_WITH_TOKEN_LENGTH) {
                throw new InvalidArgumentException('The with parameter contains a relation name that is too long.');
            }

            if (isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $unique[] = $token;
        }

        if (count($unique) > self::MAX_WITH_TOKENS) {
            throw new InvalidArgumentException(
                sprintf('The with parameter must contain at most %d unique relations.', self::MAX_WITH_TOKENS),
            );
        }

        return $unique;
    }

    /**
     * Count raw CSV/list entries before normalization and deduplication.
     *
     * @param mixed $value
     */
    private function rawCsvTokenCount(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_array($value)) {
            return count($value);
        }

        if (is_object($value)) {
            return 0;
        }

        return substr_count((string) $value, ',') + 1;
    }

    /**
     * Normalize CSV/query-array input to a non-empty token list.
     *
     * @param mixed $value
     *
     * @return array<int, string>
     */
    protected function normalizeCsvTokens(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $this->request_normalizer->normalizeStringList($value);
        }

        $normalized = $this->request_normalizer->normalizeString($value, null, true);

        if ($normalized === null) {
            return [];
        }

        return $this->request_normalizer->normalizeStringList(explode(',', $normalized));
    }
}
