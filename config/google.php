<?php
/**
 * Google OAuth — set credentials via environment variables (preferred)
 * or copy values into this file for local-only use.
 *
 * NEVER commit real Client Secrets. Rotate any secret that was previously committed.
 *
 * Setup:
 * 1. https://console.cloud.google.com/apis/credentials
 * 2. OAuth 2.0 Client ID (Web application)
 * 3. Authorized redirect URI must match google_redirect_uri()
 *    Local:  http://localhost/inventory-system/api/google_auth.php?action=callback
 *    Prod:   https://YOUR_DOMAIN/.../api/google_auth.php?action=callback
 */
declare(strict_types=1);

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
