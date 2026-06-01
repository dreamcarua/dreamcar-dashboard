<?php
// === FinanceAuth.php ===
// finance/core/FinanceAuth.php
// НАЗНАЧЕНИЕ: Проверка доступа к финансовому модулю на основе finance_role из сессии
// СВЯЗИ: core/Session.php (уже загружен через Auth.php)
// РАЗМЕР: ~130 строк

declare(strict_types=1);

class FinanceAuth
{
    // Разрешенные роли финансового модуля
    private const ROLES = ['admin', 'finance_manager', 'manager'];

    /**
     * Проверяет доступ к финансовому модулю.
     * Для API-запросов возвращает JSON 403.
     * Для обычных запросов редиректит на UTM Dashboard.
     */
    public static function checkAccess(): void
    {
        // Сессия должна быть уже запущена через Auth::checkAccess()
        $role = self::getFinanceRole();

        if (empty($role) || !in_array($role, self::ROLES, true)) {
            $isApi = self::isApiRequest();
            if ($isApi) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Немає доступу до фінансового модуля'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // Редирект на UTM Dashboard с сообщением
            header('Location: ../index.php?finance_access=denied');
            exit;
        }
    }

    /**
     * Требует finance_role = 'admin'.
     * Для API возвращает JSON 403, иначе редирект.
     */
    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            $isApi = self::isApiRequest();
            if ($isApi) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Потрiбен рiвень доступу admin'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: ../index.php?finance_access=denied');
            exit;
        }
    }

    /**
     * Возвращает finance_role текущего пользователя из сессии.
     */
    public static function getFinanceRole(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        return $_SESSION['user']['finance_role'] ?? null;
    }

    /**
     * Может ли пользователь видеть раздел USDT.
     */
    public static function canViewUsdt(): bool
    {
        return self::isAdmin();
    }

    /**
     * Может ли пользователь добавлять/редактировать данные.
     */
    public static function canWrite(): bool
    {
        $role = self::getFinanceRole();
        return in_array($role, ['admin', 'finance_manager'], true);
    }

    /**
     * Является ли пользователь admin финансового модуля.
     */
    public static function isAdmin(): bool
    {
        return self::getFinanceRole() === 'admin';
    }

    /**
     * Возвращает имя текущего пользователя.
     */
    public static function getUsername(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return 'unknown';
        }
        return $_SESSION['user']['username'] ?? 'unknown';
    }

    /**
     * Определяет является ли запрос API-запросом (AJAX/JSON).
     */
    private static function isApiRequest(): bool
    {
        // Запросы к finance/api/
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($uri, '/finance/api/')) {
            return true;
        }
        // AJAX заголовок
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strtolower($xrw) === 'xmlhttprequest') {
            return true;
        }
        // JSON accept
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        return false;
    }
}
