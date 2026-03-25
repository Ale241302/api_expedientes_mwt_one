<?php

/**
 * eliminarcliente.php
 * Endpoint para eliminar cliente de producto y eliminar precio específico
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
    !isset($payload->keyhash, $payload->keyuser, $payload->id_cliente, $payload->id_producto) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->id_cliente) || empty($payload->id_producto)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'id_cliente', 'id_producto'],
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
    $idCliente = $payload->id_cliente;
    $idProducto = $payload->id_producto;

    // Iniciar transacción
    $db->beginTransaction();

    /* ================================ ACTUALIZAR PRODUCT_ACCESS ================================ */
    // Obtener el product_access actual
    $productStmt = $db->prepare("SELECT product_access FROM {$prefix}hikashop_product WHERE product_id = :product_id LIMIT 1");
    $productStmt->execute([':product_id' => $idProducto]);
    $product = $productStmt->fetch();

    if (!$product) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado', 'code' => 'PRODUCT_NOT_FOUND']);
        exit;
    }

    $productAccess = $product['product_access'];
    $newProductAccess = '';

    // Remover el id_cliente del product_access
    $newProductAccess = str_replace(",{$idCliente},", ",", $productAccess);

    // Si después de eliminar solo queda "," o está vacío, cambiar a "all"
    if ($newProductAccess === ',' || $newProductAccess === '' || $newProductAccess === ',,') {
        $newProductAccess = 'all';
    }

    // Actualizar product_access
    $updateStmt = $db->prepare("UPDATE {$prefix}hikashop_product SET product_access = :product_access WHERE product_id = :product_id");
    $updateStmt->execute([
        ':product_access' => $newProductAccess,
        ':product_id' => $idProducto
    ]);

    /* ================================ ELIMINAR DE HIKASHOP_PRICE ================================ */
    $priceAccess = ",{$idCliente},";

    $deleteStmt = $db->prepare("
        DELETE FROM {$prefix}hikashop_price 
        WHERE price_product_id = :price_product_id 
        AND price_access = :price_access
    ");

    $deleteStmt->execute([
        ':price_product_id' => $idProducto,
        ':price_access' => $priceAccess
    ]);

    $deletedRows = $deleteStmt->rowCount();

    // Confirmar transacción
    $db->commit();

    // Retornar resultado exitoso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cliente eliminado exitosamente',
        'data' => [
            'id_cliente' => $idCliente,
            'id_producto' => $idProducto,
            'new_product_access' => $newProductAccess,
            'prices_deleted' => $deletedRows
        ]
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en eliminarcliente.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en eliminarcliente.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
