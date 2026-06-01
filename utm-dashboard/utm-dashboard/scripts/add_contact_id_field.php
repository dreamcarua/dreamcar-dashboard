<?php
// === add_contact_id_field.php ===
// НАЗНАЧЕНИЕ: Добавить поле contact_id в таблицу crm_deals
// ИСПОЛЬЗОВАНИЕ: Открыть в браузере один раз

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Logger.php';

$logger = new Logger();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавление поля contact_id</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #1a1a2e; color: #eee; }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        .info { color: #60a5fa; }
        pre { background: #16213e; padding: 15px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>

<?php
echo "<h1>Добавление поля contact_id в таблицу crm_deals</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    $stmt = $pdo->query("SHOW COLUMNS FROM crm_deals LIKE 'contact_id'");
    $contactIdExists = $stmt->fetch();

    if ($contactIdExists) {
        echo "<p class='info'>Поле <strong>contact_id</strong> уже существует</p>";
    } else {
        $sql = "ALTER TABLE crm_deals ADD COLUMN contact_id BIGINT NULL AFTER deal_id";
        $pdo->exec($sql);
        echo "<p class='success'>Поле <strong>contact_id</strong> добавлено</p>";
        $logger->success('Поле contact_id добавлено в crm_deals');
    }

    echo "<h2>Структура таблицы (первые 10 полей):</h2>";
    echo "<pre>";

    $stmt = $pdo->query("SHOW COLUMNS FROM crm_deals");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($columns as $col) {
        if ($count >= 10) {
            echo "... и еще " . (count($columns) - 10) . " полей\n";
            break;
        }
        $marker = ($col['Field'] === 'contact_id') ? ' <- NEW' : '';
        echo sprintf("%-20s %s%s\n", $col['Field'], $col['Type'], $marker);
        $count++;
    }

    echo "</pre>";
    echo "<p class='success'>Готово!</p>";

} catch (Exception $e) {
    $logger->error('Ошибка добавления поля contact_id', ['error' => $e->getMessage()]);
    echo "<p class='error'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

</body>
</html>
