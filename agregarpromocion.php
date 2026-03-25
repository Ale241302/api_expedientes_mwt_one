<?php

/**
 * agregarpromocion.php
 * Endpoint para agregar/actualizar promociones para uno o más clientes
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
    !isset($payload->keyhash, $payload->keyuser, $payload->id_cliente, $payload->id_producto, $payload->value) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->id_producto)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'id_cliente', 'id_producto', 'value'],
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
    $value = $payload->value;

    // Convertir id_cliente a array si viene como string único
    $idClientesArray = is_array($payload->id_cliente) ? $payload->id_cliente : [$payload->id_cliente];

    // Procesar campos opcionales
    $idProductPromocion = isset($payload->id_product_promocion) && !empty($payload->id_product_promocion)
        ? $payload->id_product_promocion
        : null;
    $comision = isset($payload->por_comision) && !empty($payload->por_comision)
        ? $payload->por_comision
        : null;
    // Convertir fechas a timestamp unix
    $priceStartDate = time(); // Por defecto fecha actual
    if (isset($payload->price_start_date) && !empty($payload->price_start_date)) {
        $priceStartDate = is_numeric($payload->price_start_date)
            ? (int)$payload->price_start_date
            : strtotime($payload->price_start_date);
    }

    $priceEndDate = 0; // Por defecto 0
    if (isset($payload->price_end_date) && !empty($payload->price_end_date)) {
        $priceEndDate = is_numeric($payload->price_end_date)
            ? (int)$payload->price_end_date
            : strtotime($payload->price_end_date);
    }

    // Iniciar transacción
    $db->beginTransaction();

    $results = [];
    $insertCount = 0;
    $updateCount = 0;

    /* ================================ PROCESAR CADA CLIENTE ================================ */
    foreach ($idClientesArray as $idCliente) {
        $priceAccess = ",{$idCliente},";

        // Verificar si ya existe el registro
        $checkStmt = $db->prepare("
            SELECT price_id FROM {$prefix}hikashop_price 
            WHERE price_product_id = :price_product_id 
            AND price_access = :price_access 
            LIMIT 1
        ");
        $checkStmt->execute([
            ':price_product_id' => $idProducto,
            ':price_access' => $priceAccess
        ]);
        $existingPrice = $checkStmt->fetch();

        if ($existingPrice) {
            // ACTUALIZAR registro existente
            $updateStmt = $db->prepare("
                UPDATE {$prefix}hikashop_price 
                SET price_value = :price_value,
                    price_start_date = :price_start_date,
                    price_end_date = :price_end_date,
                    product_id_promocion = :product_id_promocion,
                    por_comision = :por_comision
                WHERE price_id = :price_id
            ");

            $updateStmt->execute([
                ':price_value' => $value,
                ':price_start_date' => $priceStartDate,
                ':price_end_date' => $priceEndDate,
                ':por_comision' => $comision,
                ':price_id' => $existingPrice['price_id']
            ]);

            $updateCount++;
            $results[] = [
                'id_cliente' => $idCliente,
                'action' => 'updated',
                'price_id' => $existingPrice['price_id']
            ];
        } else {
            // INSERTAR nuevo registro

            // Primero actualizar product_access en hikashop_product
            $productStmt = $db->prepare("
        SELECT product_access FROM {$prefix}hikashop_product 
        WHERE product_id = :product_id 
        LIMIT 1
    ");
            $productStmt->execute([':product_id' => $idProducto]);
            $product = $productStmt->fetch();

            if ($product) {
                $productAccess = $product['product_access'];
                $newProductAccess = '';

                // Modificar product_access según la lógica
                if (strtolower(trim($productAccess)) === 'all') {
                    // Si es "all", cambiar a ,id_cliente,
                    $newProductAccess = ",{$idCliente},";
                } else {
                    // Si ya tiene valores como ,7,21,11,13, verificar que no exista ya
                    if (strpos($productAccess, ",{$idCliente},") === false) {
                        // Agregar el id_cliente al final solo si no existe
                        $productAccess = rtrim($productAccess, ',');
                        $newProductAccess = "{$productAccess},{$idCliente},";
                    } else {
                        // Ya existe, mantener el mismo
                        $newProductAccess = $productAccess;
                    }
                }

                // Actualizar product_access
                $updateProductStmt = $db->prepare("
            UPDATE {$prefix}hikashop_product 
            SET product_access = :product_access 
            WHERE product_id = :product_id
        ");
                $updateProductStmt->execute([
                    ':product_access' => $newProductAccess,
                    ':product_id' => $idProducto
                ]);
            }

            // Ahora insertar el precio
            $insertStmt = $db->prepare("
        INSERT INTO {$prefix}hikashop_price 
        (price_currency_id, price_product_id, price_value, price_min_quantity, price_access, price_start_date, price_end_date, product_id_promocion, por_comision)
        VALUES 
        (:price_currency_id, :price_product_id, :price_value, :price_min_quantity, :price_access, :price_start_date, :price_end_date, :product_id_promocion, :por_comision)
    ");

            $insertStmt->execute([
                ':price_currency_id' => 2,
                ':price_product_id' => $idProducto,
                ':price_value' => $value,
                ':price_min_quantity' => 0,
                ':price_access' => $priceAccess,
                ':price_start_date' => $priceStartDate,
                ':price_end_date' => $priceEndDate,
                ':product_id_promocion' => $idProductPromocion,
                ':por_comision' => $comision
            ]);

            $insertCount++;
            $results[] = [
                'id_cliente' => $idCliente,
                'action' => 'inserted',
                'price_id' => $db->lastInsertId(),
                'product_access_updated' => $newProductAccess ?? null
            ];
        }
    }
    // Confirmar transacción
    $db->commit();

    // Retornar resultado exitoso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Promoción(es) procesada(s) exitosamente',
        'summary' => [
            'total_clientes' => count($idClientesArray),
            'insertados' => $insertCount,
            'actualizados' => $updateCount
        ],
        'data' => [
            'id_producto' => $idProducto,
            'price_value' => $value,
            'price_start_date' => $priceStartDate,
            'price_end_date' => $priceEndDate,
            'product_id_promocion' => $idProductPromocion,
            'results' => $results
        ]
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en agregarpromocion.php: " . $e->getMessage());
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
    error_log("❌ Error en agregarpromocion.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
