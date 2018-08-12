<?php

namespace Drupal\gcss\Controller;

use Drupal\Core\Controller\ControllerBase;
use Google_Client;
use Google_Service_ShoppingContent;

/**
 * Provides route responses for the Example module.
 */
class Oauth2callback extends ControllerBase
{
    /**
     * Returns a simple page.
     *
     * @return array
     *   A simple renderable array.
     */
    public function oauth2callback()
    {
        global $base_url;

        $config = \Drupal::config('gcss.settings');
        $client_auth_file_name = $config->get('gcss_client_auth_config_file_name') ?: $base_url . '/client_secrets.json';
        $client_oauthCallbackUrl = $config->get('gcss_oauth2_callback_url');
        $authFilePath = $base_url . '/' . $client_auth_file_name;
        // Start a session to persist credentials.
        session_start();

        // Create the client object and set the authorization configuration
        // from the client_secrets.json you downloaded from the Developers Console.
        $client = new Google_Client();
        //$client->setAuthConfig("$authFilePath");
        $client->setRedirectUri("$client_oauthCallbackUrl");
        $client->setScopes(Google_Service_ShoppingContent::CONTENT);
        
        // Handle authorization flow from the server.
        if (!isset($_GET['code'])) {
            $auth_url = $client->createAuthUrl();
            header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
        } else {
            $client->authenticate($_GET['code']);
            $_SESSION['access_token'] = $client->getAccessToken();
            $redirect_uri = 'http://' . $_SERVER['HTTP_HOST'] . '/';
            header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
        }

        return true;
    }

}