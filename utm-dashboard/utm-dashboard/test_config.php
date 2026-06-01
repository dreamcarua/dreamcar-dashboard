<?php
/**
 * test_config.php
 * Тестовый файл для проверки конфигурации окружения
 */

require_once 'config/app_config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест конфигурации</title>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #0a0a0a;
            color: #fafafa;
        }
        .info-box {
            background: #171717;
            border: 1px solid #262626;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }
        .info-box h2 {
            margin-top: 0;
            color: #3b82f6;
        }
        .info-item {
            margin: 10px 0;
            padding: 10px;
            background: #1a1a1a;
            border-radius: 8px;
        }
        .info-label {
            font-weight: 600;
            color: #8b5cf6;
        }
        .info-value {
            color: #10b981;
            word-break: break-all;
        }
        .success {
            color: #10b981;
        }
        .warning {
            color: #f59e0b;
        }
    </style>
</head>
<body>
    <h1>🔧 Тест конфигурации окружения</h1>
    
    <div class="info-box">
        <h2>📊 Информация об окружении</h2>
        <div class="info-item">
            <span class="info-label">Окружение:</span>
            <span class="info-value"><?php echo APP_ENV; ?></span>
            <?php if (APP_ENV === 'local'): ?>
                <span class="success">✅ Локальное</span>
            <?php else: ?>
                <span class="warning">🌐 Серверное</span>
            <?php endif; ?>
        </div>
        <div class="info-item">
            <span class="info-label">BASE_URL:</span>
            <span class="info-value"><?php echo BASE_URL; ?></span>
        </div>
    </div>
    
    <div class="info-box">
        <h2>🌐 Серверная информация</h2>
        <div class="info-item">
            <span class="info-label">HTTP_HOST:</span>
            <span class="info-value"><?php echo $_SERVER['HTTP_HOST'] ?? 'не определен'; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">SERVER_NAME:</span>
            <span class="info-value"><?php echo $_SERVER['SERVER_NAME'] ?? 'не определен'; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">SCRIPT_NAME:</span>
            <span class="info-value"><?php echo $_SERVER['SCRIPT_NAME'] ?? 'не определен'; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">REQUEST_URI:</span>
            <span class="info-value"><?php echo $_SERVER['REQUEST_URI'] ?? 'не определен'; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">DOCUMENT_ROOT:</span>
            <span class="info-value"><?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'не определен'; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">__FILE__:</span>
            <span class="info-value"><?php echo __FILE__; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Относительный путь от DOCUMENT_ROOT:</span>
            <span class="info-value">
                <?php 
                $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
                $filePath = __FILE__;
                if ($docRoot && strpos($filePath, $docRoot) === 0) {
                    echo substr($filePath, strlen($docRoot));
                } else {
                    echo 'не определен';
                }
                ?>
            </span>
        </div>
        <div class="info-item">
            <span class="info-label">Протокол:</span>
            <span class="info-value"><?php echo (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'HTTPS' : 'HTTP'; ?></span>
        </div>
    </div>
    
    <div class="info-box">
        <h2>📁 Пути к файлам</h2>
        <div class="info-item">
            <span class="info-label">ROOT_DIR:</span>
            <span class="info-value"><?php echo ROOT_DIR; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">CONFIG_DIR:</span>
            <span class="info-value"><?php echo CONFIG_DIR; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">DATA_DIR:</span>
            <span class="info-value"><?php echo DATA_DIR; ?></span>
        </div>
    </div>
    
    <div class="info-box">
        <h2>🔗 Ссылки</h2>
        <div class="info-item">
            <a href="<?php echo BASE_URL; ?>login.php" style="color: #3b82f6;">→ Страница входа</a>
        </div>
        <div class="info-item">
            <a href="<?php echo BASE_URL; ?>index.php" style="color: #3b82f6;">→ Главная страница</a>
        </div>
    </div>
</body>
</html>
