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

// The token record is the authoritative source of the post-login destination.
// Do not rely on destination parameters in the public email URL.
error_log("Token received: " . ($token ?? 'No token'));

if (!$token) {
    die('Token inválido.');
}

// Retrieve the token and its stored destination from the database.
$tokenData = Capsule::table('autologin_tokens')
    ->where('token', $token)
    ->first();

if ($tokenData) {
    $now = time();
    $tokenAge = $now - $tokenData->creation_time;
    $firstUsedAt = $tokenData->first_used_at;
    $timeSinceFirstUse = $firstUsedAt ? $now - $firstUsedAt : null;
    $isWithinMaximumLifetime = $tokenAge < AUTOLOGIN_TOKEN_MAX_LIFETIME;
    $isWithinGracePeriod = !$firstUsedAt || $timeSinceFirstUse < AUTOLOGIN_TOKEN_REDEMPTION_GRACE_PERIOD;

    // Permit unused tokens for their full lifetime and used tokens during the configured grace period.
    if ($isWithinMaximumLifetime && $isWithinGracePeriod) {
        // Generate the SSO token for the client.
        $clientId = $tokenData->client_id;
        $storedDestination = $tokenData->destination;
        $params = [
            'client_id' => $clientId
        ];

        // Restore the exact destination from the token record. Custom redirect paths are stored
        // as "sso:custom_redirect|relative/path" so they do not need to appear in email URLs.
        if (strpos($storedDestination, 'sso:custom_redirect|') === 0) {
            list($destination, $ssoRedirectPath) = explode('|', $storedDestination, 2);
            $params['destination'] = $destination;
            $params['sso_redirect_path'] = $ssoRedirectPath;
            error_log("Stored custom SSO redirect path set to: " . $ssoRedirectPath);
        } elseif ($storedDestination !== 'clientarea') {
            $params['destination'] = $storedDestination;
            error_log("Stored destination set to: " . $storedDestination);
        }

        // Call the local API to generate the SSO token.
        $response = localAPI('CreateSsoToken', $params);

        // Log the API response.
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

            // Redirect the client to the SSO link.
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
    error_log("Token is invalid or was not found.");
}

die('Token inválido ou expirado.');