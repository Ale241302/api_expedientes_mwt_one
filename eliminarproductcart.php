<?php

/**
 * eliminarproductcart.php
 * Endpoint para eliminar un producto del carrito de compras
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
    !isset($payload->keyhash, $payload->keyuser, $payload->product_id, $payload->cart_id) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->product_id) || empty($payload->cart_id)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'product_id', 'cart_id'],
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

    // Obtener user_id de HikaShop
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
        SELECT cart_product_id 
        FROM {$prefix}hikashop_cart_product 
        WHERE cart_id = :cart_id 
        AND product_id = :product_id 
        LIMIT 1
    ");
    $checkProductStmt->execute([
        ':cart_id' => $cartId,
        ':product_id' => $productId
    ]);
    $cartProduct = $checkProductStmt->fetch(PDO::FETCH_ASSOC);

    if (!$cartProduct) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Producto no encontrado en el carrito',
            'code' => 'PRODUCT_NOT_IN_CART'
        ]);
        exit;
    }

    // Eliminar el producto del carrito
    $deleteStmt = $db->prepare("
        DELETE FROM {$prefix}hikashop_cart_product 
        WHERE cart_id = :cart_id 
        AND product_id = :product_id
    ");

    $deleteStmt->execute([
        ':cart_id' => $cartId,
        ':product_id' => $productId
    ]);

    $rowsDeleted = $deleteStmt->rowCount();

    // Verificar si quedan productos en el carrito
    $countProductsStmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM {$prefix}hikashop_cart_product 
        WHERE cart_id = :cart_id
    ");
    $countProductsStmt->execute([':cart_id' => $cartId]);
    $result = $countProductsStmt->fetch(PDO::FETCH_ASSOC);
    $remainingProducts = intval($result['total']);

    $cartDeleted = false;

    if ($remainingProducts === 0) {
        // No quedan productos, eliminar el carrito completo
        $deleteCartStmt = $db->prepare("
            DELETE FROM {$prefix}hikashop_cart 
            WHERE cart_id = :cart_id
        ");
        $deleteCartStmt->execute([':cart_id' => $cartId]);
        $cartDeleted = true;

        // DEBUG (opcional - comentar en producción)
        // error_log("🗑️ Carrito vacío eliminado: " . $cartId);
    } else {
        // Aún quedan productos, solo actualizar fecha de modificación
        $updateCartStmt = $db->prepare("
            UPDATE {$prefix}hikashop_cart 
            SET cart_modified = :timestamp 
            WHERE cart_id = :cart_id
        ");
        $updateCartStmt->execute([
            ':timestamp' => time(),
            ':cart_id' => $cartId
        ]);
    }

    // Retornar resultado exitoso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $cartDeleted ? 'Producto eliminado y carrito vacío removido' : 'Producto eliminado del carrito',
        'cart_id' => $cartId,
        'product_id' => $productId,
        'cart_deleted' => $cartDeleted,
        'remaining_products' => $remainingProducts
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en eliminarproductcart.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en eliminarproductcart.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
