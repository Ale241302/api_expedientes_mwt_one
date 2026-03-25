<?php

/**
 * lugares.php
 * Endpoint para obtener puertos o aeropuertos según el método de envío de la orden
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

    /* ================================ PASO 1: OBTENER MÉTODO DE ENVÍO ================================ */

    $shippingStmt = $db->prepare("
        SELECT order_shipping_method 
        FROM {$prefix}hikashop_order 
        WHERE order_number = :order_number 
        LIMIT 1
    ");

    $shippingStmt->execute([':order_number' => $payload->order_number]);
    $shippingMethod = $shippingStmt->fetchColumn();

    if ($shippingMethod === false || $shippingMethod === null) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Pedido no encontrado',
            'code' => 'ORDER_NOT_FOUND',
            'order_number' => $payload->order_number
        ]);
        exit;
    }

    /* ================================ PASO 2: CONSULTAR LUGARES SEGÚN MÉTODO ================================ */

    $lugares = [];

    if ($shippingMethod === 'Maritimo') {
        // Consultar puertos marítimos
        $lugaresStmt = $db->prepare("
            SELECT 
                id,
                Code as code,
                Name as nombre,
                Country as country
            FROM {$prefix}puertos
            ORDER BY Name ASC
        ");
        $lugaresStmt->execute();
        $lugares = $lugaresStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($shippingMethod === 'Aereo') {
        // Consultar aeropuertos
        $lugaresStmt = $db->prepare("
            SELECT 
                id,
                Code as code,
                Airport as nombre,
                Country as country
            FROM {$prefix}airport
            ORDER BY Airport ASC
        ");
        $lugaresStmt->execute();
        $lugares = $lugaresStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Método de envío no válido
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Método de envío no válido',
            'code' => 'INVALID_SHIPPING_METHOD',
            'shipping_method' => $shippingMethod
        ]);
        exit;
    }

    // Retornar resultado exitoso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'shipping_method' => $shippingMethod,
        'count' => count($lugares),
        'data' => $lugares
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en lugares.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en lugares.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
