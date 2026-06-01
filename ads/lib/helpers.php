<?php
/**
 * Helper Functions для Meta Ads API
 */

/**
 * Преобразовать маркер токена в реальный Access Token
 *
 * @param string $tokenMarker - 'app_token', 'client_token' или реальный токен
 * @return string - реальный Access Token
 */
function resolveAccessToken($tokenMarker) {
    if ($tokenMarker === 'app_token') {
        return META_APP_ID . '|' . META_APP_SECRET;
    } elseif ($tokenMarker === 'client_token') {
        return META_CLIENT_TOKEN;
    }

    // Если это уже реальный токен - вернуть как есть
    return $tokenMarker;
}
