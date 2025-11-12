<?php

namespace App\Services;

use App\Domain\DTO\OauthDTO;
use App\Exceptions\AppException;
use App\Models\OAuthState;
use Google\Service\Calendar;
use Google\Service\Oauth2;
use Google_Client;
use Google_Service_Oauth2;

class GoogleOAuthService
{
    protected \Google_Client $client;

    public function __construct()
    {
        $this->client = new Google_Client();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(route('google.oauth.callback'));
    }

    public function redirect(int $userId): string
    {
        $this->client->addScope([Google_Service_Oauth2::USERINFO_EMAIL, Calendar::CALENDAR_EVENTS_READONLY]);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setIncludeGrantedScopes(true);

        $state = bin2hex(random_bytes(16));
        $this->client->setState($state);

        OAuthState::updateOrCreate(['user_id' => $userId], ['state' => $state]);

        return $this->client->createAuthUrl();
    }

    public function callback(OAuthState $state, string $code): OauthDTO
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token["error"])) {
            throw new AppException($token["error"], 'GOOGLE_GENERIC_ERROR');
        }

        $this->client->setAccessToken($token);

        $oauth2 = new Oauth2($this->client);
        $userInfo = $oauth2->userinfo->get();

        $email = $userInfo->email;

        return new OauthDTO($token['access_token'], $token['refresh_token'], $token['expires_in'], $email);
    }

    public function refreshAccessToken(string $refreshToken): string
    {
        $token = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($token["error"])) {
            throw new AppException($token["error"], 'GOOGLE_GENERIC_ERROR');
        }

        return $token['access_token'];
    }
}
