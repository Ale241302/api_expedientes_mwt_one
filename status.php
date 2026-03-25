<?php

/**
 * status.php
 * Endpoint para obtener el estado de una orden por número
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config.php';
require __DIR__ . '/app/db.php';

/* ================================ Validación Inicial ================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido. Usa POST.', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw);

if (
    !isset($payload->keyhash, $payload->keyuser, $payload->order_number) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->order_number)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'order_number'],
        'code' => 'MISSING_FIELDS'
    ]);
    exit;
}

if ($payload->keyhash !== $Keyhas) {
    http_response_code(401);
    echo json_encode(['error' => 'Keyhash inválido', 'code' => 'INVALID_KEYHASH']);
    exit;
}

try {
    $db = db_connect($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
    $prefix = $DB_PREFIX;

    // Validar que el usuario existe
    $userStmt = $db->prepare("SELECT id FROM {$prefix}users WHERE keyuser = :keyuser LIMIT 1");
    $userStmt->execute([':keyuser' => $payload->keyuser]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Usuario no encontrado',
            'code' => 'USER_NOT_FOUND'
        ]);
        exit;
    }

    /* ================================ CONSULTAR ORDEN ================================ */

    $orderStmt = $db->prepare("
        SELECT 
            o.order_status
        FROM {$prefix}hikashop_order o
        LEFT JOIN {$prefix}hikashop_orderstatus os ON os.orderstatus_namekey = o.order_status
        WHERE o.order_number = :order_number
        LIMIT 1
    ");

    $orderStmt->execute([':order_number' => $payload->order_number]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Orden no encontrada',
            'code' => 'ORDER_NOT_FOUND',
            'message' => 'No existe ninguna orden con ese número'
        ]);
        exit;
    }

    // Retornar resultado
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [

            'order_status' => $order['order_status']
        ]
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
