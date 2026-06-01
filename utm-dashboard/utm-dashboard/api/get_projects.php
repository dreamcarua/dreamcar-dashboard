<?php
// === get_projects.php ===
// api/get_projects.php
// НАЗНАЧЕНИЕ: Возвращает список уникальных проектов из deal_project

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/models/CrmDeal.php';

try {
    // Список проектов из CrmDeal (с датами)
    $projects = CrmDeal::getMainProjects();
    $projectDates = CrmDeal::getProjectDates();

    $projectsWithDates = [];
    foreach ($projects as $p) {
        $dates = $projectDates[$p] ?? null;
        $projectsWithDates[] = [
            'name' => $p,
            'date_from' => $dates ? $dates['date_from'] : null,
            'date_to' => $dates ? $dates['date_to'] : null,
        ];
    }

    echo json_encode([
        'success'  => true,
        'projects' => array_column($projectsWithDates, 'name'),
        'projects_with_dates' => $projectsWithDates,
        'count'    => count($projects)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Ошибка получения списка проектов: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
