<?php
/**
 * This file is part of the SharePoint OAuth App Client library.
 *
 * @author     Quetzy Garcia <qgarcia@wearearchitect.com>
 * @copyright  2014-2015 Architect 365
 * @link       http://architect365.co.uk
 *
 * For the full copyright and license information,
 * please view the LICENSE.md file that was distributed
 * with this source code.
 */

namespace WeAreArchitect\SharePoint;

use Exception;
use Serializable;

use Carbon\Carbon;
use \Firebase\JWT\JWT;

class SPAccessToken extends SPObject implements Serializable
{
    /**
     * Access token
     *
     * @access  protected
     * @var     string
     */
    protected $token;

    /**
     * Expire date
     *
     * @access  protected
     * @var     \Carbon\Carbon
     */
    protected $expires;

    /**
     * {@inheritdoc}
     */
    protected function hydrate($data, $exceptions = true)
    {
        if (array_key_exists('expires_on', $data)) {
            $data['expires_on'] = Carbon::now()->addSeconds($data['expires_on']);
        }

        return parent::hydrate($data, $exceptions);
    }

    /**
     * SharePoint Access Token constructor
     *
     * @access  public
     * @param   array  $json  JSON response from the SharePoint REST API
     * @param   array  $extra Extra SharePoint Access Token properties to map
     * @throws  SPException
     * @return  SPAccessToken
     */
    public function __construct(array $json, array $extra = [])
    {
        parent::__construct([
            'token'   => 'access_token',
            'expires' => 'expires_on',
        ], $extra);

        $this->hydrate($json);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        return [
            'token'   => $this->token,
            'expires' => $this->expires,
            'extra'   => $this->extra,
        ];
    }

    /**
     * Serialize SharePoint Access Token
     *
     * @access  public
     * @return  string
     */
    public function serialize()
    {
        return serialize([
            $this->token,
            $this->expires->getTimestamp(),
            $this->expires->getTimezone()->getName(),
        ]);
    }

    /**
     * Recreate SharePoint Access Token
     *
     * @access  public
     * @param   string $serialized
     * @return  void
     */
    public function unserialize($serialized)
    {
        list($this->token, $timestamp, $timezone) = unserialize($serialized);

        $this->expires = Carbon::createFromTimeStamp($timestamp, $timezone);
    }

    /**
     * SharePoint Access Token string value
     *
     * @access  public
     * @return  string
     */
    public function __toString()
    {
        return $this->token;
    }

    /**
     * Create a SharePoint Access Token (User-only Policy)
     *
     * @static
     * @access  public
     * @param   SPSite $site         SharePoint Site
     * @param   string $contextToken Context Token
     * @param   array  $extra        Extra SharePoint Access Token properties to map
     * @throws  SPException
     * @return  SPAccessToken
     */
    public static function createUOP(SPSite $site, $contextToken, array $extra = [])
    {
        $config = $site->getConfig();

        if (empty($config['secret'])) {
            throw new SPException('The Secret is empty/not set');
        }

        try {
            JWT::$leeway = 60; // $leeway in seconds
            $jwt = JWT::decode($contextToken, base64_decode($config['secret']), array('HS256'));
        } catch (Exception $e) {
            throw new SPException('Unable to decode the Context Token', 0, $e);
        }

        // get URL hostname
        $hostname = parse_url($site->getUrl(), PHP_URL_HOST);

        // build resource
        $resource = str_replace('@', '/'.$hostname.'@', $jwt->appctxsender);

        // decode application context
        $oauth2 = json_decode($jwt->appctx);

        $json = $site->request($oauth2->SecurityTokenServiceUri, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],

            // the POST body must be passed as a query string
            'body'    => http_build_query([
                'grant_type'    => 'refresh_token',
                'client_id'     => $jwt->aud,
                'client_secret' => $config['secret'],
                'refresh_token' => $jwt->refreshtoken,
                'resource'      => $resource,
            ])
        ], 'POST');
        var_dump($json);
        echo($json['access_token']);
        return new static($json, $extra);
    }

    public function getCurrentUser(SPSite $site)
    {
        $hostname = parse_url($site->getUrl(), PHP_URL_HOST);
        $response = $site->request('https://' . $hostname . '/_api/web/currentuser', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
            ]], 'GET', false);
        $responseBody = $response->getBody()->getContents();
        $currentUser = new \SimpleXMLElement($responseBody);
        $email = $currentUser->content->children('m', true)->properties->children('d', true)->Email;
        $name = $currentUser->content->children('m', true)->properties->children('d', true)->Title;
        return ['email' => $email, 'name' => $name];
    }

    /**
     * Create a SharePoint Access Token (App-only Policy)
     *
     * @static
     * @access  public
     * @param   SPSite $site  SharePoint Site
     * @param   array  $extra Extra SharePoint Access Token properties to map
     * @throws  SPException
     * @return  SPAccessToken
     */
    public static function createAOP(SPSite $site, array $extra = [])
    {
        $config = $site->getConfig();

        if (empty($config['secret'])) {
            throw new SPException('The Secret is empty/not set');
        }

        if (empty($config['acs'])) {
            throw new SPException('The Azure Access Control Service URL is empty/not set');
        }

        if (! filter_var($config['acs'], FILTER_VALIDATE_URL)) {
            throw new SPException('The Azure Access Control Service URL is invalid');
        }

        if (empty($config['client_id'])) {
            throw new SPException('The Client ID is empty/not set');
        }

        if (empty($config['resource'])) {
            throw new SPException('The Resource is empty/not set');
        }

        $json = $site->request($config['acs'], [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],

            // the POST body must be passed as a query string
            'body'    => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $config['client_id'],
                'client_secret' => $config['secret'],
                'resource'      => $config['resource'],
            ]),
        ], 'POST');

        return new static($json, $extra);
    }

    /**
     * Check if the SharePoint Access Token has expired
     *
     * @access  public
     * @return  bool
     */
    public function hasExpired()
    {
        return $this->expires->isPast();
    }

    /**
     * Get the SharePoint Access Token expire date
     *
     * @access  public
     * @return  Carbon
     */
    public function expireDate()
    {
        return $this->expires;
    }
}
