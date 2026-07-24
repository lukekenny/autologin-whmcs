<?php

if (!defined("WHMCS")) die("Acesso restrito.");

use WHMCS\Database\Capsule;

/*
 * Auto-login token settings. Change these values to adjust token behaviour.
 * AUTOLOGIN_TOKEN_MAX_LIFETIME is the maximum time an unused token remains valid.
 * AUTOLOGIN_TOKEN_REDEMPTION_GRACE_PERIOD allows repeat redemptions after first use,
 * which prevents email-link scanners from consuming a recipient's link immediately.
 */
define('AUTOLOGIN_TOKEN_MAX_LIFETIME', 86400); // 24 hours, in seconds.
define('AUTOLOGIN_TOKEN_REDEMPTION_GRACE_PERIOD', 60); // 60 seconds, in seconds.

/**
 * Checks for and creates the autologin token table if it does not exist.
 */
function verificarOuCriarTabelaAutologin() {
    if (!Capsule::schema()->hasTable('autologin_tokens')) {
        Capsule::schema()->create('autologin_tokens', function ($table) {
            $table->increments('id');
            $table->integer('client_id')->unsigned();
            $table->string('token', 64)->unique();
            $table->string('destination');
            $table->integer('creation_time');
            $table->integer('first_used_at')->nullable();
        });
        error_log("Table 'autologin_tokens' created successfully.");
    } else {
        error_log("Table 'autologin_tokens' already exists. Checking columns...");

        // Check whether each column exists and create it if it is missing
        if (!Capsule::schema()->hasColumn('autologin_tokens', 'id')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->increments('id');
            });
            error_log("Column 'id' added to table 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'client_id')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->integer('client_id')->unsigned();
            });
            error_log("Column 'client_id' added to table 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'token')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->string('token', 64)->unique();
            });
            error_log("Column 'token' added to table 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'destination')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->string('destination');
            });
            error_log("Column 'destination' added to table 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'creation_time')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->integer('creation_time');
            });
            error_log("Column 'creation_time' added to table 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'first_used_at')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->integer('first_used_at')->nullable();
            });
            error_log("Column 'first_used_at' added to table 'autologin_tokens'.");
        }
    }
}

/**
 * Generates an automatic login link for the client with a stored destination.
 *
 * @param int $clientId The client's ID in WHMCS.
 * @param string $destination Destination page after login: 'clientarea', 'clientarea:invoices', or 'clientarea:submit_ticket'.
 * @param string $customRedirect Custom redirect path.
 * @return string Automatic login URL containing only its token. The destination remains server-side.
 */
function gerarLinkAutoLogin($clientId, $destination = 'clientarea', $customRedirect = '') {
    verificarOuCriarTabelaAutologin(); // Check for and create the table if necessary
    
    if (empty($clientId) || !is_numeric($clientId)) {
        error_log("Invalid client ID when generating autologin link.");
        return ''; // Return an empty string if client_id is invalid
    }

    // If there is a custom redirect path, include it as part of the stored destination.
    if ($customRedirect) {
        $destination = "sso:custom_redirect|" . $customRedirect;
    }

    // Check whether an active token already exists for the client_id and full destination (including redirect)
    $tokenData = Capsule::table('autologin_tokens')
        ->where('client_id', $clientId)
        ->where('destination', $destination) // Check the full destination
        ->first();

    $now = time();
    $isWithinMaximumLifetime = $tokenData && ($now - $tokenData->creation_time < AUTOLOGIN_TOKEN_MAX_LIFETIME);
    $isWithinGracePeriod = $tokenData && (!$tokenData->first_used_at || ($now - $tokenData->first_used_at < AUTOLOGIN_TOKEN_REDEMPTION_GRACE_PERIOD));

    if ($tokenData && $isWithinMaximumLifetime && $isWithinGracePeriod) {
        $token = $tokenData->token;
        error_log("Active token found for client ID: $clientId and destination: $destination; reusing token.");
    } else {
        // Delete tokens that have exceeded their lifetime or post-redemption grace period before creating a replacement.
        if ($tokenData) {
            Capsule::table('autologin_tokens')->where('id', $tokenData->id)->delete();
            error_log("Token expired for destination; creating a new token for destination: $destination.");
        }
        $token = hash('sha256', uniqid(rand(), true));
        Capsule::table('autologin_tokens')->insert([
            'client_id' => $clientId,
            'token' => $token,
            'destination' => $destination, // Store the full destination
            'creation_time' => $now,
            'first_used_at' => null
        ]);
    }

    // The destination is stored with the token, avoiding extra query parameters in email links.
    $whmcsUrl = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
    return $whmcsUrl . "auth.php?token=$token";
}

/**
 * Hook to add various auto-login merge fields to all emails.
 */
function CustomEmail_EmailPreSend($vars) {
    if (isset($vars['relid']) && $vars['relid'] > 0) {
        $clientId = $vars['mergefields']['client_id'];

        // Check whether the ticket URL and invoice ID are available in the merge fields
        $ticketUrl = $vars['mergefields']['ticket_url'] ?? null;
        $invoiceId = $vars['mergefields']['invoice_id'] ?? null;

        // Extract only the desired path from the full ticket URL
        $customRedirectPathTicket = null;
        if ($ticketUrl) {
            $parsedUrl = parse_url($ticketUrl);
            $customRedirectPathTicket = ltrim($parsedUrl['path'], '/') . '?' . $parsedUrl['query'];
        }

        // Build the path for the specific invoice if the ID is available
        $customRedirectPathInvoice = $invoiceId ? "viewinvoice.php?id=" . $invoiceId : null;

        // Generate autologin links for different destinations
        $autoLoginLink = gerarLinkAutoLogin($clientId, 'clientarea');
        $autoLoginLinkSubmitTicket = gerarLinkAutoLogin($clientId, 'clientarea:submit_ticket');
        $autoLoginLinkTicket = gerarLinkAutoLogin($clientId, 'clientarea:tickets');
        $autoLoginLinkInvoices = gerarLinkAutoLogin($clientId, 'clientarea:invoices');

        // Specific links for the ticket and invoice
        $autoLoginLinkSpecificTicket = $customRedirectPathTicket ? gerarLinkAutoLogin($clientId, 'clientarea', $customRedirectPathTicket) : null;
        $autoLoginLinkSpecificInvoice = $customRedirectPathInvoice ? gerarLinkAutoLogin($clientId, 'clientarea', $customRedirectPathInvoice) : null;

        error_log("Autologin merge field added for client ID $clientId.");

        // Return the autologin link merge fields
        return [
            'auto_login_link' => $autoLoginLink,
            'auto_login_link_submit_ticket' => $autoLoginLinkSubmitTicket,
            'auto_login_link_ticket' => $autoLoginLinkTicket,
            'auto_login_link_invoices' => $autoLoginLinkInvoices,
            'auto_login_link_specific_ticket' => $autoLoginLinkSpecificTicket,
            'auto_login_link_specific_invoice' => $autoLoginLinkSpecificInvoice
        ];
    } else {
        error_log("Client ID not found or invalid.");
    }

    return [];
}

add_hook("EmailPreSend", 1, "CustomEmail_EmailPreSend");
