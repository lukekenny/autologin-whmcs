<?php
require_once 'init.php'; // Initialize WHMCS
use WHMCS\Database\Capsule;

$token = $_GET['token'] ?? null;
$destination = $_GET['destination'] ?? 'clientarea'; // Default to 'clientarea' if no destination is provided
$ssoRedirectPath = $_GET['sso_redirect_path'] ?? null;

// Decode sso_redirect_path to ensure "&amp;" is treated as "&"
$ssoRedirectPath = $ssoRedirectPath ? html_entity_decode($ssoRedirectPath) : null;

// Log the token and other parameters
error_log("Token recebido: " . ($token ?? 'Nenhum token'));
error_log("Destination recebido: " . ($destination ?? 'Nenhum destination'));
error_log("SSO Redirect Path recebido: " . ($ssoRedirectPath ?? 'Nenhum redirect_path'));

if (!$token) {
    die('Token inválido.');
}

// Adjust the destination if sso_redirect_path is present
$fullDestination = $destination;
if ($destination === 'sso:custom_redirect' && $ssoRedirectPath) {
    $fullDestination = "sso:custom_redirect|" . $ssoRedirectPath;
}

// Retrieve the token and full destination from the database
$tokenData = Capsule::table('autologin_tokens')
    ->where('token', $token)
    ->where('destination', $fullDestination) // Check the full destination
    ->first();

if (!$tokenData && $destination === 'clientarea') {
    // Attempt to retrieve the clientarea token when no destination is provided
    $tokenData = Capsule::table('autologin_tokens')
        ->where('token', $token)
        ->where('destination', 'clientarea')
        ->first();
}

if ($tokenData) {
    $creationTime = $tokenData->creation_time;
    $expirationTime = 86400; // 24 hours in seconds

    // Check whether the token is still within its validity period
    if (time() - $creationTime < $expirationTime) {
        // Generate the SSO token for the client
        $clientId = $tokenData->client_id;
        
        // Configure the parameters for the API call
        $params = [
            'client_id' => $clientId
        ];
        
        // Add the destination if it is present and is not 'clientarea'
        if ($destination !== 'clientarea') {
            $params['destination'] = $destination;
            error_log("Destination set to: " . $destination);
        }
        
        // Add sso_redirect_path if the destination is sso:custom_redirect
        if ($destination === 'sso:custom_redirect' && $ssoRedirectPath) {
            $params['sso_redirect_path'] = $ssoRedirectPath;
            error_log("SSO Redirect Path set to: " . $ssoRedirectPath);
        }

        // Call the local API to generate the SSO token
        $response = localAPI('CreateSsoToken', $params);
        
        // Log the API response
        error_log("Resposta da API CreateSsoToken: " . print_r($response, true));

        if ($response['result'] == 'success') {
            // Delete the token to ensure it can be used only once
            Capsule::table('autologin_tokens')->where('token', $token)->delete();
            error_log("Token excluído após uso.");

            // Redirect the client to the SSO link
            header("Location: " . $response['redirect_url']);
            exit;
        } else {
            error_log("Erro ao gerar o SSO token: " . $response['message']);
        }
    } else {
        error_log("Token expirado.");
    }
} else {
    error_log("Token inválido ou não encontrado, ou destination incorreto.");
}

die('Token inválido ou expirado.');