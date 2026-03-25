<?php

/**
 * updatecart.php (actualizarcantidad.php)
 * Endpoint para actualizar la cantidad de un producto específico en el carrito
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
    !isset($payload->keyhash, $payload->keyuser, $payload->product_id, $payload->cart_id, $payload->quantity) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->product_id) || empty($payload->cart_id)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'product_id', 'cart_id', 'quantity'],
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
    $cartId = $payload->cart_id;

    // Obtener hikashop_user_id
    $hikashopUserStmt = $db->prepare("SELECT user_id FROM {$prefix}hikashop_user WHERE user_cms_id = :user_cms_id LIMIT 1");
    $hikashopUserStmt->execute([':user_cms_id' => $userCmsId]);
    $hikashopUser = $hikashopUserStmt->fetch(PDO::FETCH_ASSOC);

    if (!$hikashopUser) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Usuario no tiene carrito de HikaShop',
            'code' => 'NO_HIKASHOP_USER'
        ]);
        exit;
    }

    $hikashopUserId = $hikashopUser['user_id'];

    // Verificar que el carrito pertenece al usuario
    $verifyCartStmt = $db->prepare("
        SELECT cart_id 
        FROM {$prefix}hikashop_cart 
        WHERE cart_id = :cart_id 
        AND user_id = :user_id 
        LIMIT 1
    ");
    $verifyCartStmt->execute([
        ':cart_id' => $cartId,
        ':user_id' => $hikashopUserId
    ]);
    $cartOwner = $verifyCartStmt->fetch(PDO::FETCH_ASSOC);

    if (!$cartOwner) {
        http_response_code(403);
        echo json_encode([
            'error' => 'El carrito no pertenece al usuario',
            'code' => 'CART_NOT_OWNED'
        ]);
        exit;
    }

    // Verificar que el producto existe en el carrito
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

    if (!$existingProduct || empty($existingProduct['cart_product_id'])) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Producto no encontrado en el carrito',
            'code' => 'PRODUCT_NOT_IN_CART'
        ]);
        exit;
    }

    // Actualizar cantidad (REEMPLAZAR, no sumar)
    $updateProductStmt = $db->prepare("
        UPDATE {$prefix}hikashop_cart_product 
        SET cart_product_quantity = :quantity,
            cart_product_modified = :timestamp
        WHERE cart_product_id = :cart_product_id
    ");

    $updateProductStmt->execute([
        ':quantity' => $quantity, // ← REEMPLAZA con la nueva cantidad
        ':timestamp' => time(),
        ':cart_product_id' => $existingProduct['cart_product_id']
    ]);

    // Actualizar fecha de modificación del carrito
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
    // error_log("✏️ Cantidad actualizada: producto_id=" . $productId . ", nueva_cantidad=" . $quantity . ", cart_id=" . $cartId);

    // Retornar resultado exitoso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cantidad actualizada correctamente',
        'cart_id' => $cartId,
        'product_id' => $productId,
        'quantity' => $quantity
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en updatecart.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en updatecart.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
