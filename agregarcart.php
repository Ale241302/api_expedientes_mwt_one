<?php

/**
 * agregarcart.php
 * Endpoint para agregar productos al carrito de compras de un usuario autenticado
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
    !isset($payload->keyhash, $payload->keyuser, $payload->product_id, $payload->quantity) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->product_id)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'product_id', 'quantity'],
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

// Validar cantidad
$quantity = intval($payload->quantity);
if ($quantity <= 0) {
    http_response_code(400);
    echo json_encode([
        'error' => 'La cantidad debe ser mayor a 0',
        'code' => 'INVALID_QUANTITY'
    ]);
    exit;
}

try {
    // Conectar a BD
    $db = db_connect($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
    $prefix = $DB_PREFIX;

    // Obtener user_id (CMS ID) desde keyuser
    $userStmt = $db->prepare("SELECT id FROM josmwt_users WHERE keyuser = :keyuser LIMIT 1");
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

    $userCmsId = $user['id'];
    $productId = $payload->product_id;

    // Verificar que el producto existe
    $productStmt = $db->prepare("SELECT product_id, product_type FROM {$prefix}hikashop_product WHERE product_id = :product_id AND product_published = 1 LIMIT 1");
    $productStmt->execute([':product_id' => $productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Producto no encontrado',
            'code' => 'PRODUCT_NOT_FOUND'
        ]);
        exit;
    }

    // Obtener o crear user_id de HikaShop
    $hikashopUserStmt = $db->prepare("SELECT user_id FROM {$prefix}hikashop_user WHERE user_cms_id = :user_cms_id LIMIT 1");
    $hikashopUserStmt->execute([':user_cms_id' => $userCmsId]);
    $hikashopUser = $hikashopUserStmt->fetch(PDO::FETCH_ASSOC);

    if (!$hikashopUser) {
        // Crear usuario de HikaShop si no existe
        $insertUserStmt = $db->prepare("
            INSERT INTO {$prefix}hikashop_user (user_cms_id, user_created, user_created_ip) 
            VALUES (:user_cms_id, :timestamp, :ip)
        ");
        $insertUserStmt->execute([
            ':user_cms_id' => $userCmsId,
            ':timestamp' => time(),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
        $hikashopUserId = $db->lastInsertId();
    } else {
        $hikashopUserId = $hikashopUser['user_id'];
    }

    // Buscar carrito existente del usuario
    $cartStmt = $db->prepare("
        SELECT cart_id, cart_modified
        FROM {$prefix}hikashop_cart 
        WHERE user_id = :user_id 
        AND cart_type = 'cart' 
        AND cart_current = 1 
        LIMIT 1
    ");
    $cartStmt->execute([':user_id' => $hikashopUserId]);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);

    // Validación corregida: verifica que $cart no sea false Y tenga cart_id
    if ($cart !== false && !empty($cart['cart_id'])) {
        // Usar carrito existente
        $cartId = $cart['cart_id'];

        // Actualizar fecha de modificación
        $updateCartStmt = $db->prepare("
            UPDATE {$prefix}hikashop_cart 
            SET cart_modified = :timestamp 
            WHERE cart_id = :cart_id
        ");
        $updateCartStmt->execute([
            ':timestamp' => time(),
            ':cart_id' => $cartId
        ]);

        // DEBUG (opcional - comentar en producción)
        // error_log("✅ Carrito existente reutilizado: " . $cartId . " para usuario HikaShop: " . $hikashopUserId);
    } else {
        // Crear nuevo carrito
        $sessionId = bin2hex(random_bytes(12)); // Genera un session_id aleatorio
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $timestamp = time();

        $insertCartStmt = $db->prepare("
            INSERT INTO {$prefix}hikashop_cart (
                user_id, 
                cart_type, 
                cart_currency_id, 
                cart_payment_id, 
                cart_billing_address_id, 
                cart_current, 
                cart_share, 
                cart_params, 
                cart_ip, 
                cart_modified, 
                session_id
            ) VALUES (
                :user_id, 
                'cart', 
                2, 
                0, 
                0, 
                1, 
                'nobody', 
                '{}', 
                :ip, 
                :timestamp, 
                :session_id
            )
        ");

        $insertCartStmt->execute([
            ':user_id' => $hikashopUserId,
            ':ip' => $ipAddress,
            ':timestamp' => $timestamp,
            ':session_id' => $sessionId
        ]);

        $cartId = $db->lastInsertId();

        // DEBUG (opcional - comentar en producción)
        // error_log("🆕 Nuevo carrito creado: " . $cartId . " para usuario HikaShop: " . $hikashopUserId);
    }

    // Verificar si el producto ya está en el carrito
    $checkProductStmt = $db->prepare("
        SELECT cart_product_id, cart_product_quantity 
        FROM {$prefix}hikashop_cart_product 
        WHERE cart_id = :cart_id 
        AND product_id = :product_id 
        LIMIT 1
    ");
    $checkProductStmt->execute([
        ':cart_id' => $cartId,
        ':product_id' => $productId
    ]);
    $existingProduct = $checkProductStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingProduct && !empty($existingProduct['cart_product_id'])) {
        // Actualizar cantidad si ya existe
        $newQuantity = $existingProduct['cart_product_quantity'] + $quantity;

        $updateProductStmt = $db->prepare("
            UPDATE {$prefix}hikashop_cart_product 
            SET cart_product_quantity = :quantity,
                cart_product_modified = :timestamp
            WHERE cart_product_id = :cart_product_id
        ");

        $updateProductStmt->execute([
            ':quantity' => $newQuantity,
            ':timestamp' => time(),
            ':cart_product_id' => $existingProduct['cart_product_id']
        ]);

        $message = 'Cantidad actualizada en el carrito';
    } else {
        // Insertar nuevo producto en el carrito
        $insertProductStmt = $db->prepare("
            INSERT INTO {$prefix}hikashop_cart_product (
                cart_id,
                product_id,
                cart_product_quantity,
                cart_product_parent_id,
                cart_product_modified,
                cart_product_option_parent_id,
                cart_product_wishlist_id,
                cart_product_wishlist_product_id
            ) VALUES (
                :cart_id,
                :product_id,
                :quantity,
                0,
                :timestamp,
                0,
                0,
                0
            )
        ");

        $insertProductStmt->execute([
            ':cart_id' => $cartId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':timestamp' => time()
        ]);

        $message = 'Producto agregado al carrito';
    }

    // Retornar resultado exitoso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'cart_id' => $cartId,
        'product_id' => $productId,
        'quantity' => $quantity
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en agregarcart.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en agregarcart.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
