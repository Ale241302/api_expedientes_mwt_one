<?php

/**
 * addres.php
 * Endpoint para obtener las direcciones de un usuario autenticado
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
    !isset($payload->keyhash, $payload->keyuser) ||
    empty($payload->keyhash) || empty($payload->keyuser)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser'],
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

    // Obtener user_id (CMS ID) desde keyuser
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

    $userCmsId = $user['id'];

    /* ================================ CONSULTAR DIRECCIONES ================================ */

    $addressStmt = $db->prepare("
        SELECT 
            a.address_id,
            a.address_firstname,
            a.address_lastname,
            a.address_telephone,
            a.address_country,
            a.address_state,
            a.address_city,
            a.address_street,
            a.address_post_code
        FROM {$prefix}hikashop_address a
        INNER JOIN {$prefix}hikashop_user u ON a.address_user_id = u.user_id
        WHERE u.user_cms_id = :user_cms_id
        ORDER BY a.address_id DESC
    ");

    $addressStmt->execute([':user_cms_id' => $userCmsId]);
    $addresses = $addressStmt->fetchAll(PDO::FETCH_ASSOC);

    // Retornar resultado (puede ser array vacío si no tiene direcciones)
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count' => count($addresses),
        'data' => $addresses
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en addres.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en addres.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
