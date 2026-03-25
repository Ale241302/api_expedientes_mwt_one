<?php

/**
 * splitorder.php
 * Endpoint para dividir pedidos - crea nuevos pedidos a partir de productos seleccionados
 * Solo administrators pueden ejecutar esta acción
 * Si "order_parente": 1 -> Invierte la relación parent/child
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

// Leer payload
$raw = file_get_contents('php://input');
$payload = json_decode($raw);

// Validar campos obligatorios
if (
    !isset($payload->keyhash, $payload->keyuser, $payload->order_number, $payload->product_id) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->order_number) ||
    !is_array($payload->product_id) || empty($payload->product_id)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'order_number', 'product_id (array)'],
        'code' => 'MISSING_FIELDS'
    ]);
    exit;
}

// Validar keyhash
if ($payload->keyhash !== $Keyhas) {
    http_response_code(401);
    echo json_encode(['error' => 'Keyhash inválido', 'code' => 'INVALID_KEYHASH']);
    exit;
}

try {
    // Conectar a BD
    $db = db_connect($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
    $prefix = $DB_PREFIX;

    // Obtener user_id (CMS ID) desde keyuser
    $userStmt = $db->prepare("SELECT id FROM {$prefix}users WHERE keyuser = :keyuser LIMIT 1");
    $userStmt->execute([':keyuser' => $payload->keyuser]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Usuario no encontrado',
            'code' => 'USER_NOT_FOUND'
        ]);
        exit;
    }

    $userId = $user['id'];

    /* ================================ Verificación de Rol Administrator ================================ */

    // Obtener todos los group_id del usuario
    $groupStmt = $db->prepare("SELECT group_id FROM {$prefix}user_usergroup_map WHERE user_id = :user_id");
    $groupStmt->execute([':user_id' => $userId]);
    $groupIds = $groupStmt->fetchAll(PDO::FETCH_COLUMN);

    $isAdmin = false;

    if (!empty($groupIds)) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $titleStmt = $db->prepare("SELECT title FROM {$prefix}usergroups WHERE id IN ($placeholders)");
        $titleStmt->execute($groupIds);
        $titles = $titleStmt->fetchAll(PDO::FETCH_COLUMN);

        $isAdmin = in_array('Administrator', $titles, true);
    }

    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Acceso denegado. Solo administrators pueden dividir pedidos.',
            'code' => 'FORBIDDEN'
        ]);
        exit;
    }

    /* ================================ Determinar Modo de Relación Parent ================================ */
    $isParentMode = isset($payload->order_parente) && $payload->order_parente == 1;
    $newOrderParentId = $isParentMode ? 0 : $originalOrderId; // Aún no tenemos $originalOrderId

    /* ================================ Iniciar Transacción ================================ */
    $db->beginTransaction();

    /* ================================ Buscar Pedido Original ================================ */

    $orderStmt = $db->prepare("
        SELECT * FROM {$prefix}hikashop_order 
        WHERE order_number = :order_number 
        LIMIT 1
    ");
    $orderStmt->execute([':order_number' => $payload->order_number]);
    $originalOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$originalOrder) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            'error' => 'Pedido no encontrado',
            'code' => 'ORDER_NOT_FOUND'
        ]);
        exit;
    }

    $originalOrderId = $originalOrder['order_id'];

    /* ================================ Buscar Productos del Pedido ================================ */

    $productIds = array_map('intval', $payload->product_id);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $productsStmt = $db->prepare("
        SELECT 
            product_id, order_product_quantity, order_product_name, 
            order_product_code, order_product_price, order_product_tax, 
            order_product_tax_info, order_product_options, order_product_status, 
            order_product_wishlist_id, order_product_wishlist_product_id, 
            order_product_shipping_id, order_product_shipping_method, 
            order_product_shipping_price, order_product_shipping_tax, 
            order_product_price_before_discount, order_product_tax_before_discount
        FROM {$prefix}hikashop_order_product 
        WHERE order_id = ? AND product_id IN ($placeholders)
    ");

    $params = array_merge([$originalOrderId], $productIds);
    $productsStmt->execute($params);
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            'error' => 'No se encontraron productos en el pedido original',
            'code' => 'PRODUCTS_NOT_FOUND'
        ]);
        exit;
    }

    /* ================================ Calcular Precio Total del Split ================================ */

    $newOrderFullPrice = 0;
    foreach ($products as $product) {
        $newOrderFullPrice += ($product['order_product_quantity'] * $product['order_product_price']);
    }

    /* ================================ Generar Nuevo Order Number ================================ */

    $baseOrderNumber = $payload->order_number;
    $suffix = 1;
    $newOrderNumber = "{$baseOrderNumber}-{$suffix}";

    // Verificar si ya existe y aumentar el sufijo
    $checkStmt = $db->prepare("SELECT order_number FROM {$prefix}hikashop_order WHERE order_number LIKE :pattern");
    $checkStmt->execute([':pattern' => "{$baseOrderNumber}-%"]);
    $existingNumbers = $checkStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($existingNumbers)) {
        $maxSuffix = 0;
        foreach ($existingNumbers as $existingNumber) {
            if (preg_match('/-(\d+)$/', $existingNumber, $matches)) {
                $maxSuffix = max($maxSuffix, (int)$matches[1]);
            }
        }
        $suffix = $maxSuffix + 1;
        $newOrderNumber = "{$baseOrderNumber}-{$suffix}";
    }

    /* ================================ Crear Nuevo Pedido ================================ */

    $newOrderParentId = $isParentMode ? 0 : $originalOrderId;

    $insertOrderStmt = $db->prepare("
        INSERT INTO {$prefix}hikashop_order (
            order_billing_address_id, order_shipping_address_id, order_user_id, 
            order_parent_id, order_status, order_type, order_number, 
            order_created, order_modified, order_invoice_id, order_invoice_number, 
            order_invoice_created, order_currency_id, order_currency_info, 
            order_full_price, order_discount_code, order_discount_price, 
            order_discount_tax, order_payment_id, order_payment_method, 
            order_payment_price, order_payment_tax, order_shipping_id, 
            order_shipping_tax, order_partner_id, order_partner_price, 
            order_partner_paid, order_partner_currency_id, order_ip
        ) VALUES (
            :order_billing_address_id, :order_shipping_address_id, :order_user_id,
            :order_parent_id, :order_status, :order_type, :order_number,
            :order_created, :order_modified, :order_invoice_id, :order_invoice_number,
            :order_invoice_created, :order_currency_id, :order_currency_info,
            :order_full_price, :order_discount_code, :order_discount_price,
            :order_discount_tax, :order_payment_id, :order_payment_method,
            :order_payment_price, :order_payment_tax, :order_shipping_id,
            :order_shipping_tax, :order_partner_id, :order_partner_price,
            :order_partner_paid, :order_partner_currency_id, :order_ip
        )
    ");

    $insertOrderStmt->execute([
        ':order_billing_address_id' => $originalOrder['order_billing_address_id'],
        ':order_shipping_address_id' => $originalOrder['order_shipping_address_id'],
        ':order_user_id' => $originalOrder['order_user_id'],
        ':order_parent_id' => $newOrderParentId,  // ← CAMBIO AQUÍ
        ':order_status' => $originalOrder['order_status'],
        ':order_type' => $originalOrder['order_type'],
        ':order_number' => $newOrderNumber,
        ':order_created' => time(),
        ':order_modified' => time(),
        ':order_invoice_id' => $originalOrder['order_invoice_id'] ?? null,
        ':order_invoice_number' => $originalOrder['order_invoice_number'] ?? null,
        ':order_invoice_created' => $originalOrder['order_invoice_created'] ?? null,
        ':order_currency_id' => $originalOrder['order_currency_id'] ?? 2,  // Default a 1 si es NULL
        ':order_currency_info' => $originalOrder['order_currency_info'] ?? null,
        ':order_full_price' => $newOrderFullPrice,
        ':order_discount_code' => $originalOrder['order_discount_code'] ?? null,
        ':order_discount_price' => $originalOrder['order_discount_price'] ?? 0,
        ':order_discount_tax' => $originalOrder['order_discount_tax'] ?? 0,
        ':order_payment_id' => $originalOrder['order_payment_id'] ?? null,
        ':order_payment_method' => $originalOrder['order_payment_method'] ?? null,
        ':order_payment_price' => $originalOrder['order_payment_price'] ?? 0,
        ':order_payment_tax' => $originalOrder['order_payment_tax'] ?? 0,
        ':order_shipping_id' => $originalOrder['order_shipping_id'] ?? null,
        ':order_shipping_tax' => $originalOrder['order_shipping_tax'] ?? 0,
        ':order_partner_id' => $originalOrder['order_partner_id'] ?? null,
        ':order_partner_price' => $originalOrder['order_partner_price'] ?? 0,
        ':order_partner_paid' => $originalOrder['order_partner_paid'] ?? 0,
        ':order_partner_currency_id' => $originalOrder['order_partner_currency_id'] ?? null,
        ':order_ip' => $originalOrder['order_ip'] ?? null
    ]);

    $newOrderId = $db->lastInsertId();

    /* ================================ Actualizar Relación Parent en Pedido Original (si es modo parent) ================================ */
    if ($isParentMode) {
        $updateOriginalParentStmt = $db->prepare("
            UPDATE {$prefix}hikashop_order 
            SET order_parent_id = :new_order_id, order_modified = :modified 
            WHERE order_id = :original_order_id
        ");
        $updateOriginalParentStmt->execute([
            ':new_order_id' => $newOrderId,
            ':modified' => time(),
            ':original_order_id' => $originalOrderId
        ]);
    }

    /* ================================ Copiar Productos al Nuevo Pedido ================================ */

    $insertProductStmt = $db->prepare("
        INSERT INTO {$prefix}hikashop_order_product (
            order_id, product_id, order_product_quantity, order_product_name,
            order_product_code, order_product_price, order_product_tax,
            order_product_tax_info, order_product_options, order_product_status,
            order_product_wishlist_id, order_product_wishlist_product_id,
            order_product_shipping_id, order_product_shipping_method,
            order_product_shipping_price, order_product_shipping_tax,
            order_product_price_before_discount, order_product_tax_before_discount
        ) VALUES (
            :order_id, :product_id, :order_product_quantity, :order_product_name,
            :order_product_code, :order_product_price, :order_product_tax,
            :order_product_tax_info, :order_product_options, :order_product_status,
            :order_product_wishlist_id, :order_product_wishlist_product_id,
            :order_product_shipping_id, :order_product_shipping_method,
            :order_product_shipping_price, :order_product_shipping_tax,
            :order_product_price_before_discount, :order_product_tax_before_discount
        )
    ");

    foreach ($products as $product) {
        $insertProductStmt->execute([
            ':order_id' => $newOrderId,
            ':product_id' => $product['product_id'],
            ':order_product_quantity' => $product['order_product_quantity'],
            ':order_product_name' => $product['order_product_name'],
            ':order_product_code' => $product['order_product_code'],
            ':order_product_price' => $product['order_product_price'],
            ':order_product_tax' => $product['order_product_tax'] ?? 0,
            ':order_product_tax_info' => $product['order_product_tax_info'] ?? null,
            ':order_product_options' => $product['order_product_options'] ?? null,
            ':order_product_status' => $product['order_product_status'] ?? null,
            ':order_product_wishlist_id' => $product['order_product_wishlist_id'] ?? null,
            ':order_product_wishlist_product_id' => $product['order_product_wishlist_product_id'] ?? null,
            ':order_product_shipping_id' => $product['order_product_shipping_id'] ?? null,
            ':order_product_shipping_method' => $product['order_product_shipping_method'] ?? null,
            ':order_product_shipping_price' => $product['order_product_shipping_price'] ?? 0,
            ':order_product_shipping_tax' => $product['order_product_shipping_tax'] ?? 0,
            ':order_product_price_before_discount' => $product['order_product_price_before_discount'] ?? 0,
            ':order_product_tax_before_discount' => $product['order_product_tax_before_discount'] ?? 0
        ]);
    }

    /* ================================ Actualizar Precio del Pedido Original ================================ */

    $newOriginalPrice = $originalOrder['order_full_price'] - $newOrderFullPrice;

    // Prevenir precios negativos
    if ($newOriginalPrice < 0) {
        $newOriginalPrice = 0;
    }

    $updateOriginalStmt = $db->prepare("
        UPDATE {$prefix}hikashop_order 
        SET order_full_price = :new_price, order_modified = :modified 
        WHERE order_id = :order_id
    ");

    $updateOriginalStmt->execute([
        ':new_price' => $newOriginalPrice,
        ':modified' => time(),
        ':order_id' => $originalOrderId
    ]);

    /* ================================ Eliminar Productos del Pedido Original ================================ */

    $deleteProductsStmt = $db->prepare("
        DELETE FROM {$prefix}hikashop_order_product 
        WHERE order_id = ? AND product_id IN ($placeholders)
    ");

    // Solo parámetros posicionales
    $deleteProductsStmt->execute(array_merge([$originalOrderId], $productIds));

    // Commit transacción
    $db->commit();

    /* ================================ Respuesta Exitosa ================================ */

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Pedido dividido exitosamente',
        'order_parente_mode' => $isParentMode,
        'original_order' => [
            'order_id' => $originalOrderId,
            'order_number' => $payload->order_number,
            'new_price' => $newOriginalPrice,
            'order_parent_id' => $isParentMode ? $newOrderId : $originalOrder['order_parent_id']
        ],
        'new_order' => [
            'order_id' => $newOrderId,
            'order_number' => $newOrderNumber,
            'order_full_price' => $newOrderFullPrice,
            'order_parent_id' => $newOrderParentId,
            'products_count' => count($products)
        ],
        'is_admin' => $isAdmin
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en splitorder.php: " . $e->getMessage());
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
    error_log("❌ Error en splitorder.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
