<?php

/**
 * eliminarpromocion.php
 * Endpoint para eliminar promoción - remover group_id de price_acces
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
    !isset($payload->keyhash, $payload->keyuser, $payload->id_producto, $payload->id_cliente) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->id_producto) || empty($payload->id_cliente)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'id_producto', 'id_cliente'],
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

    /* ================================ VALIDAR USUARIO ================================ */
    $userStmt = $db->prepare("SELECT id FROM {$prefix}users WHERE keyuser = :keyuser LIMIT 1");
    $userStmt->execute([':keyuser' => $payload->keyuser]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuario no encontrado', 'code' => 'USER_NOT_FOUND']);
        exit;
    }

    $userId = $user['id'];
    $productId = $payload->id_producto;
    $groupId = $payload->id_cliente;

    /* ================================ ELIMINAR PROMOCIÓN ================================ */
    // Buscar registros afectados
    $checkStmt = $db->prepare("
        SELECT price_id, price_access 
        FROM {$prefix}hikashop_price 
        WHERE price_product_id = ? 
        AND FIND_IN_SET(?, price_access) > 0
    ");
    $checkStmt->execute([$productId, $groupId]);
    $affectedRecords = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($affectedRecords)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No se encontró promoción para eliminar',
            'code' => 'PROMOTION_NOT_FOUND'
        ]);
        exit;
    }

    $db->beginTransaction();

    $updatedCount = 0;
    foreach ($affectedRecords as $record) {
        $priceId = $record['price_id'];
        $currentAccess = $record['price_access'];

        // Remover group_id del CSV (ej: ",11,13," -> ",11," si group_id=13)
        $newAccess = preg_replace('/(^|,)' . preg_quote($groupId, '/') . '(,|$)/', '$1', $currentAccess);
        $newAccess = trim($newAccess, ','); // Limpiar comas sobrantes

        // Actualizar registro
        $updateStmt = $db->prepare("
            UPDATE {$prefix}hikashop_price 
            SET price_access = ? 
            WHERE price_id = ?
        ");
        $result = $updateStmt->execute([$newAccess, $priceId]);

        if ($result) {
            $updatedCount++;
        }
    }

    $db->commit();

    // Retornar resultado
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Promoción eliminada correctamente',
        'updated_records' => $updatedCount,
        'affected_product_id' => $productId,
        'removed_group_id' => $groupId
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en eliminarpromocion.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en eliminarpromocion.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
