<?php

/**
 * ProxmoxVE PHP API
 *
 * @copyright 2017 Saleh <Saleh7@protonmail.ch>
 * @license http://opensource.org/licenses/MIT The MIT License.
 */

namespace Proxmox;

use \Curl\Curl;

class Request {

    protected static $hostname;
    protected static $username;
    protected static $password;
    protected static $realm;
    protected static $port;
    protected static $ticket;

    /**
     * @var string|null
     */
    protected static $_CSRFPreventionToken = NULL;

    /**
     * @var string|null
     */
    protected static $_PVEAuthCookie = NULL;

    /**
     * @var \Curl\Curl
     */
    protected static $Client;

    /**
     * @param array $configure hostname, username, password, realm, port
     * @param bool  $verifySSL
     * @param bool  $verifyHost
     *
     * @throws \Proxmox\ProxmoxException
     */
    public static function Login(array $configure, bool $verifySSL = FALSE, bool $verifyHost = FALSE) {
        $check = FALSE;
        self::$hostname = !empty($configure['hostname'])  ? $configure['hostname']  : $check = TRUE;
        self::$username = !empty($configure['username'])  ? $configure['username']  : $check = TRUE;
        self::$password = !empty($configure['password'])  ? $configure['password']  : $check = TRUE;
        self::$realm    = !empty($configure['realm'])     ? $configure['realm']     : 'pam'; // pam - pve - ..
        self::$port     = !empty($configure['port'])      ? $configure['port']      : 8006;
        if ($check) {
            throw new ProxmoxException('Require in array [hostname], [username], [password], [realm], [port]');
        }
        self::_Ticket($verifySSL, $verifyHost);
    }

    protected static function _GetClient() : \Curl\Curl {

        if (self::$Client === NULL) {
            self::$Client = new \Curl\Curl();
        }

        return self::$Client;
    }

    public static function TestAuthentication(string $preventionToken, string $authCookie, string $hostname, int $port, bool $verifySSL = FALSE, bool $verifyHost = FALSE) : bool {

        $client = new \Curl\Curl();
        $client->setHeader('CSRFPreventionToken', $preventionToken);
        $client->setCookie('PVEAuthCookie', $authCookie);
        $client->setOpts([
            CURLOPT_SSL_VERIFYPEER => $verifySSL,
            CURLOPT_SSL_VERIFYHOST => $verifyHost
        ]);

        $resp = $client->get('https://' . $hostname . ':' . $port . '/api2/json/cluster');

        return $client->httpStatusCode === 200;
    }

    /**
     * @param string $preventionToken
     * @param string $authCookie
     * @param string $hostname
     * @param int    $port
     */
    public static function SetAuthentication(string $preventionToken, string $authCookie, string $hostname, int $port) : void {

        static::$_CSRFPreventionToken = $preventionToken;
        static::$_PVEAuthCookie = $authCookie;
        static::$hostname = $hostname;
        static::$port = $port;

        $client = static::_GetClient();

        $client->setHeader('CSRFPreventionToken', static::$_CSRFPreventionToken);
        $client->setCookie('PVEAuthCookie', static::$_PVEAuthCookie);

    }

    /**
     * @return string[]
     */
    public static function GetAuthentication() : array {
        return [
            'CSRFPreventionToken' => static::$_CSRFPreventionToken,
            'PVEAuthCookie'       => static::$_PVEAuthCookie,
        ];
    }

    /**
     * Create or verify authentication ticket.
     * POST /api2/json/access/ticket
     */
    protected static function _Ticket($verifySSL, $verifyHost) {

        $client = static::_GetClient();
        $client->setOpts([
            CURLOPT_SSL_VERIFYPEER => $verifySSL,
            CURLOPT_SSL_VERIFYHOST => $verifyHost
        ]);
        $response = $client->post('https://' . self::$hostname . ':' . self::$port . '/api2/json/access/ticket', [
            'username'  => self::$username,
            'password'  => self::$password,
            'realm'     => self::$realm,
        ]);

        if (!isset($response->data)) {

            if ($client->errorCode > 0) {
                throw new ProxmoxException('CURL error: ' . $client->errorMessage . ' (Code ' . $client->errorCode . ')');
            }

            throw new ProxmoxException('Response data empty');
        }

        static::SetAuthentication($response->data->CSRFPreventionToken, $response->data->ticket, self::$hostname, self::$port);

        return TRUE;
    }
    /**
     * Request
     * @param string $path
     * @param array $params
     * @param string $method
     */
    public static function Request($path, array $params = NULL, $method='GET') {
        if (substr($path, 0, 1) != '/') {
            $path = '/' . $path;
        }
        $api = 'https://' . self::$hostname . ':' . self::$port . '/api2/json' . $path;
        switch ($method) {
            case 'GET':

                $response = self::$Client->get($api, $params);

                static::AssertValidResponse($response, $api, $params);

                return $response;
            case 'PUT':

                $response = self::$Client->put($api, $params);

                static::AssertValidResponse($response, $api, $params);

                return $response;
            case 'POST':

                $response = self::$Client->post($api, $params);

                static::AssertValidResponse($response, $api, $params);

                return $response;
            case 'DELETE':

                self::$Client->removeHeader('Content-Length');

                $response = self::$Client->delete($api, $params);

                static::AssertValidResponse($response, $api, $params);

                return $response;
            default:
                throw new ProxmoxException('HTTP Request method not allowed.');
        }
    }

    public static function AssertValidResponse($response, $api, $params) {

        $httpCode = self::$Client->getHttpStatusCode();
        if ($httpCode !== 200) {
            throw new \RuntimeException('Got non-200 HTTP code ' . $httpCode . ' for request ' . $api . ', response:' . PHP_EOL . self::$Client->getRawResponse(), $httpCode);
        }

    }
}
