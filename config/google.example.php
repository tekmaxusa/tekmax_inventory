<?php
/**
 * Google OAuth credentials — copy to config/google.php OR set env vars:
 *   GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET
 *
 * Setup:
 * 1. Go to https://console.cloud.google.com/apis/credentials
 * 2. Create OAuth 2.0 Client ID (Web application)
 * 3. Authorized redirect URI:
 *    Local: http://localhost/inventory-system/api/google_auth.php?action=callback
 *    Prod:  https://YOUR_DOMAIN/api/google_auth.php?action=callback
 * 4. Also set APP_URL to your public origin in production (see config/app.php)
 */
declare(strict_types=1);

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: 'your-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: 'your-client-secret');
