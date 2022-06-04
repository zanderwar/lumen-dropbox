<?php

namespace Zanderwar\Dropbox;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Str;

use Zanderwar\Dropbox\Facades\Dropbox as Api;
use Zanderwar\Dropbox\Models\DropboxToken;
use Zanderwar\Dropbox\Resources\Files;

class Dropbox
{
    protected static string $baseUrl = 'https://api.dropboxapi.com/2/';
    protected static string $contentUrl = 'https://content.dropboxapi.com/2/';
    protected static string $authorizeUrl = 'https://www.dropbox.com/oauth2/authorize';
    protected static string $tokenUrl = 'https://api.dropbox.com/oauth2/token';
    protected static ?string $state = null;

    public function __construct()
    {
    }

    /**
     * __call catches all requests when no found method is requested
     *
     * @param  $function - the verb to execute
     * @param  $args - array of arguments
     * @return array JSON response of the request
     */
    public function __call($function, $args)
    {
        $options = ['get', 'post', 'patch', 'put', 'delete'];
        $path = (isset($args[0])) ? $args[0] : null;
        $data = (isset($args[1])) ? $args[1] : null;
        $customHeaders = (isset($args[2])) ? $args[2] : null;
        $useToken = (isset($args[3])) ? $args[3] : true;

        if (in_array($function, $options)) {
            return self::guzzle($function, $path, $data, $customHeaders, $useToken);
        }
        //request verb is not in the $options array
        throw new Exception($function.' is not a valid HTTP Verb');
    }

    public function files()
    {
        return new Files();
    }

    /**
     * Make a connection or return a token where it's valid
     *
     * @throws Exception
     * @return \Illuminate\Http\RedirectResponse|\Laravel\Lumen\Http\Redirector
     */
    public function connect(string $state = null)
    {
        //when no code param redirect to Microsoft
        if (! request()->has('code')) {
            $url = self::$authorizeUrl.'?'.http_build_query($params = [
                    'response_type' => 'code',
                    'client_id' => config('dropbox.clientId'),
                    'redirect_uri' => config('dropbox.redirectUri'),
                    'scope' => config('dropbox.scopes'),
                    'token_access_type' => config('dropbox.accessType'),
                    'state' => $state
                ]);

            return redirect($url);
        }

        if (request()->has('code')) {
            $landingUri = config('dropbox.landingUri');
            if (Str::contains($landingUri, '{state}')) {
                if (! request()->has('state')) {
                    throw new \RuntimeException('Must use the state parameter method to connect when your landing URL includes the magic {state} parameter.');
                }

                $landingUri = str_replace('{state}', request()->input('state'), $landingUri);
            }

            // With the authorization code, we can retrieve access tokens and other data.
            try {
                $params = [
                    'grant_type' => 'authorization_code',
                    'code' => request('code'),
                    'redirect_uri' => config('dropbox.redirectUri'),
                    'client_id' => config('dropbox.clientId'),
                    'client_secret' => config('dropbox.clientSecret')
                ];

                $token = $this->dopost(self::$tokenUrl, $params);
                $result = $this->storeToken($token);

                //get user details
                $me = Api::post('users/get_current_account');

                //find record and add email - not required but useful none the less
                $t = DropboxToken::findOrFail($result->id);
                $t->email = $me['email'];
                $t->save();

                return redirect($landingUri);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }

        throw new \RuntimeException('Something went wrong...');
    }

    /**
     * @return false
     */
    public function isConnected()
    {
        return ! ($this->getTokenData() == null);
    }

    /**
     * Disables the access token used to authenticate the call, redirects back to the provided path
     *
     * @param string $redirectPath
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return void
     */
    public function disconnect(string $redirectPath = '/')
    {
        $id = auth()->id();

        $client = new Client();
        $response = $client->post(self::$baseUrl.'auth/token/revoke', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->getAccessToken()
            ]
        ]);

        //delete token from db
        $token = DropboxToken::where('user_id', $id)->first();
        if ($token !== null) {
            $token->delete();
        }

        header('Location: '.url($redirectPath));
        exit();
    }

    /**
     * Return authenticated access token or request new token when expired
     *
     * @param  null $returnNullNoAccessToken when set to true return null
     * @return ?string Access token
     */
    public function getAccessToken($returnNullNoAccessToken = null)
    {
        //use token from .env if exists
        if (config('dropbox.accessToken') !== '') {
            return config('dropbox.accessToken');
        }

        //use id if passed otherwise use logged in user
        $id = auth()->id();
        $token = DropboxToken::where('user_id', $id)->first();

        // Check if tokens exist otherwise run the oauth request
        if (! isset($token->access_token)) {
            //don't redirect simply return null when no token found with this option
            if ($returnNullNoAccessToken == true) {
                return null;
            }

            header('Location: '.config('dropbox.redirectUri'));
            exit();
        }

        if (isset($token->refresh_token)) {
            // Check if token is expired
            // Get current time + 5 minutes (to allow for time differences)
            $now = time() + 300;
            if ($token->expires_in <= $now) {
                // Token is expired (or very close to it) so let's refresh
                $params = [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $token->refresh_token,
                    'client_id' => config('dropbox.clientId'),
                    'client_secret' => config('dropbox.clientSecret')
                ];

                $tokenResponse = $this->dopost(self::$tokenUrl, $params);
                $token->access_token = $tokenResponse['access_token'];
                $token->expires_in = Carbon::now()->addseconds($tokenResponse['expires_in']);
                $token->save();

                return $token->access_token;
            }
        }

        // Token is still valid, just return it
        return $token->access_token;
    }

    /**
     * @return false|DropboxToken
     */
    public function getTokenData()
    {
        //use token from .env if exists
        if (config('dropbox.accessToken') !== '') {
            return false;
        }

        $id = auth()->id();
        return DropboxToken::where('user_id', $id)->first();
    }

    protected function forceStartingSlash($string)
    {
        if (substr($string, 0, 1) !== "/") {
            $string = "/$string";
        }

        return $string;
    }

    /**
     * Store token
     *
     * @param $token
     * @return object
     */
    protected function storeToken($token)
    {
        $id = auth()->id();

        $data = [
            'user_id' => $id,
            'access_token' => $token['access_token'],
            'expires_in' => Carbon::now()->addSeconds($token['expires_in']),
            'token_type' => $token['token_type'],
            'uid' => $token['uid'],
            'account_id' => $token['account_id'],
            'scope' => $token['scope']
        ];

        if (isset($token['refresh_token'])) {
            $data['refresh_token'] = $token['refresh_token'];
        }

        //cretate a new record or if the user id exists update record
        return DropboxToken::updateOrCreate(['user_id' => $id], $data);
    }

    /**
     * run guzzle to process requested url
     *
     * @param  string $type
     * @param  string $request
     * @param  array $data
     * @param null|array $customHeaders
     * @param bool $useToken
     * @throws Exception
     * @return array  object
     */
    protected function guzzle($type, $request, $data = [], array $customHeaders = null, $useToken = true)
    {
        try {
            $client = new Client();

            $headers = [
                'content-type' => 'application/json'
            ];

            if ($useToken == true) {
                $headers['Authorization'] = 'Bearer '.$this->getAccessToken();
            }

            if ($customHeaders !== null) {
                foreach ($customHeaders as $key => $value) {
                    $headers[$key] = $value;
                }
            }

            $response = $client->$type(self::$baseUrl.$request, [
                'headers' => $headers,
                'body' => json_encode($data)
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            throw new Exception($e->getResponse()->getBody()->getContents());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    protected static function dopost($url, $params)
    {
        try {
            $client = new Client();
            $response = $client->post($url, ['form_params' => $params]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            return json_decode($e->getResponse()->getBody()->getContents(), true);
        }
    }
}
