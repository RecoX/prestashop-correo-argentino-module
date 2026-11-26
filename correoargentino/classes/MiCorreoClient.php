<?php
/**
 * MiCorreoClient — HTTP wrapper for the Correo Argentino MiCorreo API v1.
 *
 * Handles:
 *  - Basic auth token acquisition (POST /token)
 *  - JWT caching and auto-refresh via PS Configuration
 *  - GET /agencies
 *  - POST /rates
 *  - POST /shipping/import
 *  - POST /users/validate
 *
 * No external dependencies — uses PHP curl only.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MiCorreoClient
{
    const BASE_TEST = 'https://apitest.correoargentino.com.ar/micorreo/v1';
    const BASE_PROD = 'https://api.correoargentino.com.ar/micorreo/v1';

    const CFG_JWT         = 'CORREOARG_JWT';
    const CFG_JWT_EXPIRES = 'CORREOARG_JWT_EXPIRES';
    const CFG_USER_TOKEN  = 'CORREOARG_USER_TOKEN';
    const CFG_PASS_TOKEN  = 'CORREOARG_PASSWORD_TOKEN';

    /** @var string */
    private $baseUrl;

    /** @var string|null  Current JWT (may be loaded lazily) */
    private $token = null;

    /**
     * @param string $env  'TEST' or 'PROD'
     */
    public function __construct($env = 'TEST')
    {
        $this->baseUrl = ($env === 'PROD') ? self::BASE_PROD : self::BASE_TEST;
    }

    // -------------------------------------------------------------------------
    // Public API methods
    // -------------------------------------------------------------------------

    /**
     * Obtain a JWT via Basic auth and persist it in PS Configuration.
     *
     * @param string|null $userToken     Override stored userToken (used during config save)
     * @param string|null $passwordToken Override stored passwordToken
     * @return string  The JWT
     * @throws RuntimeException on HTTP error
     */
    public function getToken($userToken = null, $passwordToken = null)
    {
        $user = $userToken     ?: Configuration::get(self::CFG_USER_TOKEN);
        $pass = $passwordToken ?: Configuration::get(self::CFG_PASS_TOKEN);

        $response = $this->_request('POST', '/token', [], true, $user, $pass);

        if (empty($response['token'])) {
            throw new RuntimeException('getToken: respuesta sin token. ' . json_encode($response));
        }

        $this->token = $response['token'];
        Configuration::updateValue(self::CFG_JWT, $this->token);
        Configuration::updateValue(self::CFG_JWT_EXPIRES, $response['expires'] ?? '');

        return $this->token;
    }

    /**
     * POST /users/validate — returns customerId for a MiCorreo account.
     *
     * @param string $email
     * @param string $password
     * @return array  ['customerId' => '...', 'createdAt' => '...']
     * @throws RuntimeException
     */
    public function validateUser($email, $password)
    {
        $this->_ensureToken();

        return $this->_request('POST', '/users/validate', [
            'email'    => $email,
            'password' => $password,
        ]);
    }

    /**
     * POST /rates — quote a shipment.
     *
     * @param array $params {
     *   customerId:            string,
     *   postalCodeOrigin:      string,
     *   postalCodeDestination: string,
     *   dimensions:            array {weight, height, width, length},
     *   deliveredType?:        string 'D'|'S'  (omit for both)
     * }
     * @return array  API response with 'rates' key
     * @throws RuntimeException
     */
    public function getRates(array $params)
    {
        $this->_ensureToken();

        $dimensions = $params['dimensions'];
        $body = [
            'customerId'            => $params['customerId'],
            'postalCodeOrigin'      => $params['postalCodeOrigin'],
            'postalCodeDestination' => $params['postalCodeDestination'],
            'dimensions'            => [
                'weight' => (int) $dimensions['weight'],
                'height' => (int) $dimensions['height'],
                'width'  => (int) $dimensions['width'],
                'length' => (int) $dimensions['length'],
            ],
        ];

        if (!empty($params['deliveredType'])) {
            $body['deliveredType'] = $params['deliveredType'];
        }

        return $this->_request('POST', '/rates', $body);
    }

    /**
     * POST /shipping/import — create a shipment in MiCorreo.
     *
     * @param array $params  Full ShippingImport payload (see API docs)
     * @return array  ['createdAt' => '...']
     * @throws RuntimeException
     */
    public function importShipping(array $params)
    {
        $this->_ensureToken();

        return $this->_request('POST', '/shipping/import', $params);
    }

    /**
     * GET /agencies — list branches for a province.
     *
     * @param string $customerId
     * @param string $provinceCode  Single letter, e.g. 'B'
     * @return array  Array of agency objects
     * @throws RuntimeException
     */
    public function getAgencies($customerId, $provinceCode)
    {
        $this->_ensureToken();

        $query = http_build_query([
            'customerId'   => $customerId,
            'provinceCode' => $provinceCode,
        ]);

        return $this->_request('GET', '/agencies?' . $query, []);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure a valid JWT is loaded. Refreshes if expired or missing.
     */
    private function _ensureToken()
    {
        if ($this->token && $this->_isTokenValid()) {
            return;
        }

        // Try loading from Configuration
        $stored = Configuration::get(self::CFG_JWT);
        if ($stored && $this->_isTokenValid()) {
            $this->token = $stored;

            return;
        }

        // Need a fresh token
        $this->getToken();
    }

    /**
     * Check whether the stored JWT expiry is still in the future (with 60s buffer).
     */
    private function _isTokenValid()
    {
        $expires = Configuration::get(self::CFG_JWT_EXPIRES);
        if (empty($expires)) {
            return false;
        }

        $expiryTs = strtotime($expires);
        if ($expiryTs === false) {
            return false;
        }

        return ($expiryTs - 60) > time();
    }

    /**
     * Execute an HTTP request against the MiCorreo API.
     *
     * @param string      $method    'GET' or 'POST'
     * @param string      $path      e.g. '/rates'
     * @param array       $data      Body for POST, ignored for GET
     * @param bool        $basicAuth Use Basic auth instead of Bearer
     * @param string|null $basicUser Basic auth username (required when $basicAuth=true)
     * @param string|null $basicPass Basic auth password (required when $basicAuth=true)
     * @return array  Decoded JSON response
     * @throws RuntimeException on HTTP or API error
     */
    private function _request($method, $path, array $data = [], $basicAuth = false, $basicUser = null, $basicPass = null)
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $headers = [];

        if ($basicAuth) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $basicUser . ':' . $basicPass);
        } else {
            $token = $this->token ?: Configuration::get(self::CFG_JWT);
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $json = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $rawResponse = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException('MiCorreoClient curl error: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = isset($decoded['message']) ? $decoded['message'] : $rawResponse;
            throw new RuntimeException(
                sprintf('MiCorreoClient HTTP %d en %s: %s', $httpCode, $path, $message)
            );
        }

        if ($decoded === null) {
            throw new RuntimeException('MiCorreoClient: respuesta no es JSON válido. Raw: ' . $rawResponse);
        }

        return $decoded;
    }
}
