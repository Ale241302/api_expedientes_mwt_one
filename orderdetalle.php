<?php


/**
 * orderdetalle.php
 * Endpoint para obtener detalle de pedidos con productos y variantes agrupadas
 * Solo administrators pueden ejecutar esta acción
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
    !isset($payload->keyhash, $payload->keyuser, $payload->order_number) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->order_number)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'order_number'],
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
            'error' => 'Acceso denegado. Solo administrators puede ver el detalle',
            'code' => 'FORBIDDEN'
        ]);
        exit;
    }


    /* ================================ Obtención de Datos del Pedido ================================ */


    // 1. Obtener order_id desde order_number
    $orderStmt = $db->prepare("
        SELECT order_id 
        FROM {$prefix}hikashop_order 
        WHERE order_number = :order_number 
        LIMIT 1
    ");
    $orderStmt->execute([':order_number' => $payload->order_number]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);


    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Pedido no encontrado',
            'code' => 'ORDER_NOT_FOUND',
            'order_number' => $payload->order_number
        ]);
        exit;
    }


    $orderId = $order['order_id'];


    // 2. Obtener productos del pedido desde hikashop_order_product
    $orderProductsStmt = $db->prepare("
        SELECT product_id, order_product_code 
        FROM {$prefix}hikashop_order_product 
        WHERE order_id = :order_id
    ");
    $orderProductsStmt->execute([':order_id' => $orderId]);
    $orderProducts = $orderProductsStmt->fetchAll(PDO::FETCH_ASSOC);


    if (empty($orderProducts)) {
        http_response_code(404);
        echo json_encode([
            'error' => 'No se encontraron productos para este pedido',
            'code' => 'NO_PRODUCTS_FOUND',
            'order_id' => $orderId
        ]);
        exit;
    }


    // 3. Obtener IDs de productos para consulta
    $productIds = array_column($orderProducts, 'product_id');


    // 4. Obtener detalles de productos desde hikashop_product
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $productsStmt = $db->prepare("
        SELECT product_id, product_name, product_code, product_parent_id 
        FROM {$prefix}hikashop_product 
        WHERE product_id IN ($placeholders)
    ");
    $productsStmt->execute($productIds);
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);


    // Crear mapa de productos por ID
    $productMap = [];
    foreach ($products as $product) {
        $productMap[$product['product_id']] = $product;
    }


    // 5. Obtener IDs de productos padre únicos
    $parentIds = [];
    foreach ($products as $product) {
        if (!empty($product['product_parent_id']) && $product['product_parent_id'] > 0) {
            $parentIds[$product['product_parent_id']] = true;
        }
    }


    $parentProducts = [];
    if (!empty($parentIds)) {
        $parentIdsList = array_keys($parentIds);
        $parentPlaceholders = implode(',', array_fill(0, count($parentIdsList), '?'));
        $parentStmt = $db->prepare("
            SELECT product_id, product_name, product_code 
            FROM {$prefix}hikashop_product 
            WHERE product_id IN ($parentPlaceholders)
        ");
        $parentStmt->execute($parentIdsList);
        $parentProductsResult = $parentStmt->fetchAll(PDO::FETCH_ASSOC);


        foreach ($parentProductsResult as $parent) {
            $parentProducts[$parent['product_id']] = $parent;
        }
    }


    // 6. Estructurar respuesta agrupando variantes por producto padre
    $groupedProducts = [];
    $processedParents = [];


    foreach ($orderProducts as $orderProduct) {
        $productId = $orderProduct['product_id'];
        $product = $productMap[$productId] ?? null;


        if (!$product) {
            continue;
        }


        $parentId = $product['product_parent_id'];


        // Si el producto tiene padre
        if (!empty($parentId) && $parentId > 0) {
            // Si ya procesamos este padre, skip (para agrupar)
            if (isset($processedParents[$parentId])) {
                continue;
            }


            // Marcar como procesado
            $processedParents[$parentId] = true;


            // Obtener info del padre
            $parentInfo = $parentProducts[$parentId] ?? null;


            // Buscar todas las variantes con este mismo padre
            $variants = [];
            foreach ($orderProducts as $op) {
                $pid = $op['product_id'];
                $prod = $productMap[$pid] ?? null;
                if ($prod && $prod['product_parent_id'] == $parentId) {
                    $variants[] = [
                        'product_id' => $prod['product_id'],
                        'product_name' => $prod['product_name'],
                        'product_code' => $prod['product_code'],
                        'order_product_code' => $op['order_product_code']
                    ];
                }
            }


            $groupedProducts[] = [
                'type' => 'parent_with_variants',
                'parent_product_id' => $parentId,
                'parent_product_name' => $parentInfo['product_name'] ?? 'N/A',
                'parent_product_code' => $parentInfo['product_code'] ?? 'N/A',
                'variants' => $variants,
                'variant_count' => count($variants)
            ];
        } else {
            // Producto sin padre (producto simple)
            $groupedProducts[] = [
                'type' => 'simple',
                'product_id' => $product['product_id'],
                'product_name' => $product['product_name'],
                'product_code' => $product['product_code'],
                'order_product_code' => $orderProduct['order_product_code']
            ];
        }
    }


    /* ================================ Respuesta Exitosa ================================ */


    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Detalle de pedido obtenido exitosamente',
        'order_number' => $payload->order_number,
        'order_id' => $orderId,
        'products' => $groupedProducts,
        'product_count' => count($groupedProducts),
        'is_admin' => $isAdmin
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en orderdetalle.php: " . $e->getMessage());
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
    error_log("❌ Error en orderdetalle.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
