<?php
// Este archivo es opcional, permite consultar logs desde el navegador
// Acceso: https://muitowork.com/api-tracking/debug/logs.php?user_id=123&action=stats

require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../utils/logger.php';

if (!isset($_GET['debug_key']) || $_GET['debug_key'] !== 'tu_clave_debug_segura') {
    http_response_code(403);
    die('Acceso denegado');
}

$db = db_connect($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
$logger = new APILogger($db, $DB_PREFIX);

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'stats';

switch ($action) {
    case 'stats':
        $hours = $_GET['hours'] ?? 24;
        $stats = $logger->getStats((int)$hours);
        echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case 'user_logs':
        $userId = $_GET['user_id'] ?? 0;
        $limit = $_GET['limit'] ?? 50;
        $offset = $_GET['offset'] ?? 0;
        $logs = $logger->getLogsForUser((int)$userId, (int)$limit, (int)$offset);
        echo json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case 'order_logs':
        $orderNumber = $_GET['order_number'] ?? '';
        $logs = $logger->getLogsForOrder($orderNumber);
        echo json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['error' => 'Acción no reconocida']);
}
