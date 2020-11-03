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
     * @var \Curl\Curl
     */
    protected static $Client;

    /**
     * Proxmox Api client
     * @param array $configure   hostname, username, password, realm, port
    */
    public static function Login(array $configure, $verifySSL = FALSE, $verifyHost = FALSE) {
        $check = FALSE;
        self::$hostname = !empty($configure['hostname'])  ? $configure['hostname']  : $check = TRUE;
        self::$username = !empty($configure['username'])  ? $configure['username']  : $check = TRUE;
        self::$password = !empty($configure['password'])  ? $configure['password']  : $check = TRUE;
        self::$realm    = !empty($configure['realm'])     ? $configure['realm']     : 'pam'; // pam - pve - ..
        self::$port     = !empty($configure['port'])      ? $configure['port']      : 8006;
        if ($check) {
            throw new ProxmoxException('Require in array [hostname], [username], [password], [realm], [port]');
        }
        self::ticket($verifySSL, $verifyHost);
    }
    /**
     * Create or verify authentication ticket.
     * POST /api2/json/access/ticket
    */
    protected static function ticket($verifySSL, $verifyHost) {
        self::$Client = new \Curl\Curl();
        self::$Client->setOpts([
            CURLOPT_SSL_VERIFYPEER => $verifySSL,
            CURLOPT_SSL_VERIFYHOST => $verifyHost
        ]);
        $response = self::$Client->post('https://' . self::$hostname . ':' . self::$port . '/api2/json/access/ticket', [
            'username'  => self::$username,
            'password'  => self::$password,
            'realm'     => self::$realm,
        ]);
        if (!$response->data) {
            throw new ProxmoxException('Response empty');
        }
        // set header
        self::$Client->setHeader('CSRFPreventionToken', $response->data->CSRFPreventionToken);
        // set cookie
        self::$Client->setCookie('PVEAuthCookie', $response->data->ticket);
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

        var_dump(self::$Client->getHttpStatusCode());

        $httpCode = self::$Client->getHttpStatusCode();
        if ($httpCode !== 200) {
            throw new \RuntimeException('Got non-200 HTTP code ' . $httpCode . ' for request ' . $api);
        }

    }
}
