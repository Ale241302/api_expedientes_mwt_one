<?php

/**
 * agregarcliente.php
 * Endpoint para agregar cliente(s) a producto y crear precio específico
 * ✅ SOPORTE para price_real_value (opcional)
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
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->id_producto)
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
    $idProducto = $payload->id_producto;
    
    // ✅ price_value es OPCIONAL ahora
    $value = isset($payload->value) ? floatval($payload->value) : 0;
    $realValue = isset($payload->price_real_value) ? floatval($payload->price_real_value) : null;
    $realValueField = isset($payload->price_real_field) ? $payload->price_real_field : null;

    // ✅ Convertir id_cliente a array si no lo es
    $idClientesArray = is_array($payload->id_cliente) ? $payload->id_cliente : [$payload->id_cliente];

    if (empty($idClientesArray)) {
        http_response_code(400);
        echo json_encode(['error' => 'Debe proporcionar al menos un cliente', 'code' => 'NO_CLIENTS']);
        exit;
    }

    // Iniciar transacción
    $db->beginTransaction();

    /* ================================ OBTENER PRODUCTO ACTUAL ================================ */
    $productStmt = $db->prepare("
        SELECT product_access, product_price_real, product_price_real_muitowork 
        FROM {$prefix}hikashop_product 
        WHERE product_id = :product_id LIMIT 1
    ");
    $productStmt->execute([':product_id' => $idProducto]);
    $product = $productStmt->fetch();

    if (!$product) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado', 'code' => 'PRODUCT_NOT_FOUND']);
        exit;
    }

    $productAccess = $product['product_access'];
    $results = [];
    $insertCount = 0;

    /* ================================ PROCESAR CADA CLIENTE ================================ */
    foreach ($idClientesArray as $idCliente) {
        $idCliente = trim($idCliente);

        if (empty($idCliente)) {
            continue;
        }

        // ✅ Actualizar product_access para este cliente
        if (strtolower(trim($productAccess)) === 'all') {
            $productAccess = ",{$idCliente},";
        } else {
            if (strpos($productAccess, ",{$idCliente},") === false) {
                $productAccess = rtrim($productAccess, ',');
                $productAccess = "{$productAccess},{$idCliente},";
            }
        }

        // ✅ Determinar precio real para este cliente específico
        $clientRealPrice = null;
        if ($idCliente == 21 && $realValue !== null) {
            // Cliente 21: usar price_real_value como product_price_real_muitowork
            $clientRealPrice = $realValue;
        } elseif ($realValue !== null) {
            // Otros clientes: usar price_real_value como product_price_real
            $clientRealPrice = $realValue;
        }

        // ✅ Insertar precio para este cliente
        $priceAccess = ",{$idCliente},";
        $currentTimestamp = time();

        $insertStmt = $db->prepare("
            INSERT INTO {$prefix}hikashop_price 
            (price_currency_id, price_product_id, price_value, price_min_quantity, price_access, price_start_date, price_end_date)
            VALUES 
            (:price_currency_id, :price_product_id, :price_value, :price_min_quantity, :price_access, :price_start_date, :price_end_date)
        ");

        $insertStmt->execute([
            ':price_currency_id' => 2,
            ':price_product_id' => $idProducto,
            ':price_value' => $value,
            ':price_min_quantity' => 0,
            ':price_access' => $priceAccess,
            ':price_start_date' => $currentTimestamp,
            ':price_end_date' => 0
        ]);

        // ✅ ACTUALIZAR PRECIO REAL DEL PRODUCTO según cliente
        if ($clientRealPrice !== null) {
            $realField = ($idCliente == 21) ? 'product_price_real_muitowork' : 'product_price_real';
            
            $updateRealStmt = $db->prepare("
                UPDATE {$prefix}hikashop_product 
                SET {$realField} = :real_value 
                WHERE product_id = :product_id
            ");
            $updateRealStmt->execute([
                ':real_value' => $clientRealPrice,
                ':product_id' => $idProducto
            ]);
        }

        $insertCount++;
        $results[] = [
            'id_cliente' => $idCliente,
            'price_id' => $db->lastInsertId(),
            'price_value' => $value,
            'real_price' => $clientRealPrice,
            'real_field' => $clientRealPrice ? (($idCliente == 21) ? 'product_price_real_muitowork' : 'product_price_real') : null
        ];
    }

    // ✅ Actualizar product_access una sola vez al final
    $updateStmt = $db->prepare("UPDATE {$prefix}hikashop_product SET product_access = :product_access WHERE product_id = :product_id");
    $updateStmt->execute([
        ':product_access' => $productAccess,
        ':product_id' => $idProducto
    ]);

    // Confirmar transacción
    $db->commit();

    // Retornar resultado exitoso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cliente(s) agregado(s) exitosamente',
        'summary' => [
            'total_clientes' => count($idClientesArray),
            'insertados' => $insertCount
        ],
        'data' => [
            'id_producto' => $idProducto,
            'new_product_access' => $productAccess,
            'price_value' => $value,
            'price_real_value' => $realValue,
            'results' => $results
        ]
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en agregarcliente.php: " . $e->getMessage());
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
    error_log("❌ Error en agregarcliente.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
