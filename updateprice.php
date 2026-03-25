<?php

/**
 * updateprice.php
 * Endpoint para actualizar/insertar precios de productos con control de acceso por grupos
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

/* ================================ FUNCIONES AUXILIARES ================================ */

/**
 * Convierte fecha Y-m-d a timestamp Unix
 */
function dateToTimestamp($dateString)
{
    if (empty($dateString)) {
        return null;
    }

    try {
        $date = new DateTime($dateString);
        return $date->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Verifica si un group_id está presente en el campo price_access
 * price_access tiene formato: ",21,11,13,14,12,"
 */
function groupExistsInAccess($priceAccess, $groupId)
{
    if (empty($priceAccess) || $priceAccess === 'all') {
        return false;
    }

    // Buscar el group_id con comas alrededor para match exacto
    return strpos($priceAccess, ",$groupId,") !== false;
}

/**
 * Formatea valor decimal para DECIMAL(17,5)
 * Convierte "12.5" → "12.50000", "15.00" → "15.00000"
 */
function formatDecimal($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 0.00000;
    }

    // Convertir a float y formatear con 5 decimales
    return number_format((float)$value, 5, '.', '');
}

/* ================================ Validación Inicial ================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido. Usa POST.', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw);

// ✅ SOLO 4 CAMPOS OBLIGATORIOS
if (
    !isset($payload->keyhash, $payload->keyuser, $payload->product_id, $payload->group_id) ||
    empty($payload->keyhash) || empty($payload->keyuser) ||
    empty($payload->product_id) || empty($payload->group_id)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'product_id', 'group_id'],
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
    $productId = $payload->product_id;
    $groupId = $payload->group_id;

    /* ================================ BUSCAR PRECIO EXISTENTE ================================ */

    // Buscar registro en hikashop_price donde el group_id esté en price_access
    $priceStmt = $db->prepare("
        SELECT price_id, price_access 
        FROM {$prefix}hikashop_price 
        WHERE price_product_id = :product_id
    ");
    $priceStmt->execute([':product_id' => $productId]);
    $existingPrices = $priceStmt->fetchAll(PDO::FETCH_ASSOC);

    $priceExists = false;
    $priceIdToUpdate = null;

    // Verificar si alguno de los registros contiene el group_id
    foreach ($existingPrices as $priceRow) {
        if (groupExistsInAccess($priceRow['price_access'], $groupId)) {
            $priceExists = true;
            $priceIdToUpdate = $priceRow['price_id'];
            break;
        }
    }

    /* ================================ UPDATE O INSERT EN HIKASHOP_PRICE ================================ */

    if ($priceExists && $priceIdToUpdate) {
        // ===== UPDATE EXISTENTE =====

        $updateFields = [];
        $updateParams = [':price_id' => $priceIdToUpdate];

        // ✅ price_value OPCIONAL (si viene se actualiza)
        if (isset($payload->price_value) && $payload->price_value !== null && $payload->price_value !== '') {
            $updateFields[] = "price_value = :price_value";
            $updateParams[':price_value'] = formatDecimal($payload->price_value);
        }

        // ✅ product_id_promocion OPCIONAL
        if (isset($payload->product_id_promocion) && !empty($payload->product_id_promocion)) {
            $updateFields[] = "price_id_promocion = :product_id_promocion";
            $updateParams[':product_id_promocion'] = $payload->product_id_promocion;
        }

        // ✅ CORRECTO - permite 0 pero rechaza null/undefined
        if (isset($payload->por_comision) && $payload->por_comision !== null && $payload->por_comision !== '') {
            $updateFields[] = "por_comision = :por_comision";
            $updateParams[':por_comision'] = formatDecimal($payload->por_comision);
        }


        // ✅ promocion_start_date OPCIONAL
        if (isset($payload->promocion_start_date) && !empty($payload->promocion_start_date)) {
            $timestamp = dateToTimestamp($payload->promocion_start_date);
            if ($timestamp !== null) {
                $updateFields[] = "price_start_date = :price_start_date";
                $updateParams[':price_start_date'] = $timestamp;
            }
        }

        // ✅ promocion_end_date OPCIONAL
        if (isset($payload->promocion_end_date) && !empty($payload->promocion_end_date)) {
            $timestamp = dateToTimestamp($payload->promocion_end_date);
            if ($timestamp !== null) {
                $updateFields[] = "price_end_date = :price_end_date";
                $updateParams[':price_end_date'] = $timestamp;
            }
        }

        // ✅ SI HAY CAMPOS, hacer UPDATE
        if (!empty($updateFields)) {
            $updateSQL = "UPDATE {$prefix}hikashop_price SET " . implode(', ', $updateFields) . " WHERE price_id = :price_id";
            $updateStmt = $db->prepare($updateSQL);
            $updateStmt->execute($updateParams);
        }

        $operation = 'updated';
    } else {
        // ===== INSERT NUEVO =====

        // Preparar price_start_date
        $priceStartDate = time(); // Fecha actual por defecto
        if (isset($payload->promocion_start_date) && !empty($payload->promocion_start_date)) {
            $timestamp = dateToTimestamp($payload->promocion_start_date);
            if ($timestamp !== null) {
                $priceStartDate = $timestamp;
            }
        }

        // ✅ price_value OPCIONAL (default 0)
        $priceValue = isset($payload->price_value) && $payload->price_value !== '' ? formatDecimal($payload->price_value) : 0;

        $insertSQL = "INSERT INTO {$prefix}hikashop_price (
            price_currency_id,
            price_product_id,
            price_value,
            price_min_quantity,
            price_access,
            price_start_date,
            por_comision
        ) VALUES (
            2,
            :product_id,
            :price_value,
            0,
            :price_access,
            :price_start_date,
            :por_comision
        )";

        $insertStmt = $db->prepare($insertSQL);
        $insertStmt->execute([
            ':product_id' => $productId,
            ':price_value' => $priceValue,
            ':price_access' => "," . $groupId . ",",
            ':price_start_date' => $priceStartDate,
            ':por_comision' => isset($payload->por_comision) ? $payload->por_comision : 0,
        ]);

        $priceIdToUpdate = $db->lastInsertId();
        $operation = 'inserted';
    }

    /* ================================ UPDATE OPCIONAL product_price_real ================================ */

    // ✅ OPCIONAL: solo si viene price_real_value
    if (isset($payload->price_real_value) && $payload->price_real_value !== null && $payload->price_real_value !== '') {
        $updateProductStmt = $db->prepare("
            UPDATE {$prefix}hikashop_product 
            SET product_price_real = :price_real_value 
            WHERE product_id = :product_id
        ");
        $updateProductStmt->execute([
            ':price_real_value' => formatDecimal($payload->price_real_value),
            ':product_id' => $productId
        ]);
    }

    // ✅ OPCIONAL: solo si viene product_price_real_muitowork
    if (isset($payload->product_price_real_muitowork) && $payload->product_price_real_muitowork !== null && $payload->product_price_real_muitowork !== '') {
        $updateProductStmt = $db->prepare("
            UPDATE {$prefix}hikashop_product 
            SET product_price_real_muitowork = :product_price_real_muitowork 
            WHERE product_id = :product_id
        ");
        $updateProductStmt->execute([
            ':product_price_real_muitowork' => formatDecimal($payload->product_price_real_muitowork),
            ':product_id' => $productId
        ]);
    }

    /* ================================ RESPUESTA EXITOSA ================================ */

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'operation' => $operation,
        'price_id' => $priceIdToUpdate,
        'product_id' => $productId,
        'group_id' => $groupId,
        'message' => $operation === 'updated' ? 'Precio actualizado correctamente' : 'Precio insertado correctamente'
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en updateprice.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error en base de datos',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en updateprice.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
