<?php
require_once 'init.php'; // Initialize WHMCS
use WHMCS\Database\Capsule;

/*
 * Auto-login token settings. Change these values to adjust token behaviour.
 * AUTOLOGIN_TOKEN_MAX_LIFETIME is the maximum time an unused token remains valid.
 * AUTOLOGIN_TOKEN_REDEMPTION_GRACE_PERIOD allows repeat redemptions after first use,
 * which prevents email-link scanners from consuming a recipient's link immediately.
 */
define('AUTOLOGIN_TOKEN_MAX_LIFETIME', 86400); // 24 hours, in seconds.
define('AUTOLOGIN_TOKEN_REDEMPTION_GRACE_PERIOD', 60); // 60 seconds, in seconds.

$token = $_GET['token'] ?? null;
$destination = $_GET['destination'] ?? 'clientarea'; // Default to 'clientarea' if no destination is provided
$ssoRedirectPath = $_GET['sso_redirect_path'] ?? null;

// Decode sso_redirect_path to ensure "&amp;" is treated as "&"
$ssoRedirectPath = $ssoRedirectPath ? html_entity_decode($ssoRedirectPath) : null;

// Log the token and other parameters
error_log("Token received: " . ($token ?? 'No token'));
error_log("Destination received: " . ($destination ?? 'No destination'));
error_log("SSO Redirect Path received: " . ($ssoRedirectPath ?? 'No redirect_path'));

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
    $now = time();
    $tokenAge = $now - $tokenData->creation_time;
    $firstUsedAt = $tokenData->first_used_at;
    $timeSinceFirstUse = $firstUsedAt ? $now - $firstUsedAt : null;
    $isWithinMaximumLifetime = $tokenAge < AUTOLOGIN_TOKEN_MAX_LIFETIME;
    $isWithinGracePeriod = !$firstUsedAt || $timeSinceFirstUse < AUTOLOGIN_TOKEN_REDEMPTION_GRACE_PERIOD;

    // Permit unused tokens for their full lifetime and used tokens during the configured grace period.
    if ($isWithinMaximumLifetime && $isWithinGracePeriod) {
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
        error_log("CreateSsoToken API response: " . print_r($response, true));

        if ($response['result'] == 'success') {
            // Record first use and retain the token only for the configured grace period.
            if (!$firstUsedAt) {
                Capsule::table('autologin_tokens')
                    ->where('token', $token)
                    ->whereNull('first_used_at')
                    ->update(['first_used_at' => $now]);
                error_log("Token first used; it remains redeemable for " . AUTOLOGIN_TOKEN_REDEMPTION_GRACE_PERIOD . " seconds.");
            } else {
                error_log("Token redeemed within its grace period.");
            }

            // Redirect the client to the SSO link
            header("Location: " . $response['redirect_url']);
            exit;
        } else {
            error_log("Error generating SSO token: " . $response['message']);
        }
    } else {
        Capsule::table('autologin_tokens')->where('token', $token)->delete();
        if (!$isWithinMaximumLifetime) {
            error_log("Token expired after its maximum lifetime.");
        } else {
            error_log("Token redemption grace period expired.");
        }
    }
} else {
    error_log("Token is invalid or was not found, or the destination is incorrect.");
}

die('Token inválido ou expirado.');