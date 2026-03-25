<?php

/**
 * cart.php - LÓGICA EXACTA DE product.php CON PRECIOS CORRECTOS
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido. Usa POST.', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw);

if (!isset($payload->keyhash, $payload->keyuser) || empty($payload->keyhash) || empty($payload->keyuser)) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan campos obligatorios', 'required' => ['keyhash', 'keyuser'], 'code' => 'MISSING_FIELDS']);
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

    // Usuario CMS
    $userStmt = $db->prepare("SELECT id FROM {$prefix}users WHERE keyuser = :keyuser LIMIT 1");
    $userStmt->execute([':keyuser' => $payload->keyuser]);
    $user = $userStmt->fetch();
    if (!$user) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuario no encontrado', 'code' => 'USER_NOT_FOUND']);
        exit;
    }
    $userCmsId = $user['id'];

    // Grupos del usuario
    $userGroupsStmt = $db->prepare("SELECT group_id FROM {$prefix}user_usergroup_map WHERE user_id = :user_id");
    $userGroupsStmt->execute([':user_id' => $userCmsId]);
    $userGroups = $userGroupsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($userGroups)) $userGroups = [0];

    // CALCULAR hasGroup21 UNA VEZ
    $hasGroup21 = in_array(21, $userGroups);

    // HikaShop user + Carrito
    $hikashopUserStmt = $db->prepare("SELECT user_id FROM {$prefix}hikashop_user WHERE user_cms_id = :user_cms_id LIMIT 1");
    $hikashopUserStmt->execute([':user_cms_id' => $userCmsId]);
    $hikashopUser = $hikashopUserStmt->fetch();
    if (!$hikashopUser) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Usuario no tiene carrito', 'cart_id' => null, 'count' => 0, 'data' => []]);
        exit;
    }

    $hikashopUserId = $hikashopUser['user_id'];
    $cartStmt = $db->prepare("SELECT cart_id FROM {$prefix}hikashop_cart WHERE user_id = :user_id LIMIT 1");
    $cartStmt->execute([':user_id' => $hikashopUserId]);
    $cart = $cartStmt->fetch();
    if (!$cart) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Carrito vacío', 'cart_id' => null, 'count' => 0, 'data' => []]);
        exit;
    }
    $cartId = $cart['cart_id'];

    // QUERY PARA ITEMS DEL CARRITO (sin precios aún)
    $cartProductsQuery = "
        SELECT 
            cp.cart_product_id, 
            cp.product_id, 
            cp.cart_product_quantity,
            p.product_name, 
            p.product_code, 
            p.product_type, 
            p.product_parent_id,
            p.product_price_real, 
            p.product_price_real_muitowork,
            parent.product_name AS parent_name, 
            parent.product_code AS parent_code,
            parent.product_price_real AS parent_price_real,
            parent.product_price_real_muitowork AS parent_price_real_muitowork,
            -- IMAGEN única
            COALESCE(
                (SELECT CONCAT('https://mwt.one/images/com_hikashop/upload/', file_path) 
                 FROM {$prefix}hikashop_file 
                 WHERE file_ref_id = cp.product_id AND file_type = 'product' 
                 LIMIT 1),
                (SELECT CONCAT('https://mwt.one/images/com_hikashop/upload/', file_path) 
                 FROM {$prefix}hikashop_file 
                 WHERE file_ref_id = p.product_parent_id AND file_type = 'product' 
                 LIMIT 1),
                ''
            ) AS product_image
        FROM {$prefix}hikashop_cart_product cp
        LEFT JOIN {$prefix}hikashop_product p ON p.product_id = cp.product_id
        LEFT JOIN {$prefix}hikashop_product parent ON parent.product_id = p.product_parent_id
        WHERE cp.cart_id = :cart_id
        ORDER BY cp.cart_product_id DESC
    ";

    $cartProductsStmt = $db->prepare($cartProductsQuery);
    $cartProductsStmt->execute([':cart_id' => $cartId]);
    $cartItems = $cartProductsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Carrito vacío', 'cart_id' => $cartId, 'count' => 0, 'data' => []]);
        exit;
    }

    /* ================================ OBTENER PRECIOS VÁLIDOS ================================ */
    // Recolectar IDs de productos únicos (main products)
    $mainProductIds = [];
    foreach ($cartItems as $item) {
        $isVariant = ($item['product_type'] === 'variant');
        $mainProductId = $isVariant ? $item['product_parent_id'] : $item['product_id'];
        if ($mainProductId) {
            $mainProductIds[] = $mainProductId;
        }
    }
    $mainProductIds = array_unique($mainProductIds);

    // Obtener TODOS los precios de hikashop_price para estos productos
    $productPrices = []; // Cache: productId => precio válido

    if (!empty($mainProductIds)) {
        $placeholders = implode(',', array_fill(0, count($mainProductIds), '?'));
        $pricesQuery = "
            SELECT price_product_id, price_value, price_access
            FROM {$prefix}hikashop_price
            WHERE price_product_id IN ($placeholders)
            ORDER BY price_product_id, price_value ASC
        ";

        $pricesStmt = $db->prepare($pricesQuery);
        $pricesStmt->execute($mainProductIds);
        $allPrices = $pricesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Procesar precios por producto
        foreach ($mainProductIds as $productId) {
            $productPrices[$productId] = 0; // Default

            // Buscar primer precio válido para este producto
            foreach ($allPrices as $priceRow) {
                if (
                    $priceRow['price_product_id'] == $productId &&
                    $priceRow['price_value'] !== null &&
                    $priceRow['price_value'] !== ''
                ) {

                    $priceAccess = $priceRow['price_access'] ?? '';
                    $hasAccess = ($priceAccess === 'all' || $priceAccess === '' || $priceAccess === null);

                    if (!$hasAccess) {
                        foreach ($userGroups as $groupId) {
                            if (strpos(',' . $priceAccess . ',', ',' . intval($groupId) . ',') !== false) {
                                $hasAccess = true;
                                break;
                            }
                        }
                    }

                    if ($hasAccess) {
                        $productPrices[$productId] = floatval($priceRow['price_value']);
                        break; // Tomar el primero válido
                    }
                }
            }
        }
    }

    /* ================================ CONSTRUIR RESPUESTA ================================ */
    $cartProducts = [];
    $totalAmount = 0;

    foreach ($cartItems as $row) {
        $isVariant = ($row['product_type'] === 'variant');
        $mainProductId = $isVariant ? $row['product_parent_id'] : $row['product_id'];

        $item = [
            'cart_product_id' => $row['cart_product_id'],
            'product_id' => $row['product_id'],
            'cart_product_quantity' => $row['cart_product_quantity'],
            'product_type' => $row['product_type'],
            'product_parent_id' => $row['product_parent_id']
        ];

        /* ================================ LÓGICA DE PRECIOS (EXACTA A product.php) ================================ */
        $product_sort_price = null;
        $product_price_real = $productPrices[$mainProductId] ?? 0;

        // PRIORIDAD 1: Grupo 21 → product_price_real_muitowork
        if ($hasGroup21) {
            $muitoworkPrice = $isVariant ? ($row['parent_price_real_muitowork'] ?? null) : ($row['product_price_real_muitowork'] ?? null);
            if (!empty($muitoworkPrice) && floatval($muitoworkPrice) > 0) {
                $product_sort_price = floatval($muitoworkPrice);
            }
        }

        // PRIORIDAD 2: Usar product_price_real (que viene de hikashop_price)
        if ($product_sort_price === null && $product_price_real > 0) {
            $product_sort_price = $product_price_real;
        }

        // PRIORIDAD 3: Fallback al campo de la tabla product
        if ($product_sort_price === null) {
            $realPrice = $isVariant ? ($row['parent_price_real'] ?? null) : ($row['product_price_real'] ?? null);
            if (!empty($realPrice) && floatval($realPrice) > 0) {
                $product_sort_price = floatval($realPrice);
            }
        }

        // Si aún es null, usar 0
        if ($product_sort_price === null) {
            $product_sort_price = 0;
        }

        // Datos del producto
        if ($isVariant && $row['product_parent_id']) {
            $item['product_name'] = $row['parent_name'] ?: '';
            $item['product_code'] = $row['parent_code'] ?: $row['product_code'];
        } else {
            $item['product_name'] = $row['product_name'] ?: '';
            $item['product_code'] = $row['product_code'] ?: '';
        }

        $item['product_image'] = $row['product_image'];
        $item['product_sort_price'] = $product_sort_price;

        // Characteristics (solo variants)
        if ($isVariant && $row['product_parent_id']) {
            $item['variant_code'] = $row['product_code'];
            $variantStmt = $db->prepare("
                SELECT v.variant_characteristic_id, c.characteristic_value, c.characteristic_alias
                FROM {$prefix}hikashop_variant v
                LEFT JOIN {$prefix}hikashop_characteristic c ON c.characteristic_id = v.variant_characteristic_id
                WHERE v.variant_product_id = :product_id
            ");
            $variantStmt->execute([':product_id' => $row['product_id']]);
            $item['characteristics'] = [];
            foreach ($variantStmt->fetchAll(PDO::FETCH_ASSOC) as $char) {
                if ($char['characteristic_value']) {
                    $item['characteristics'][] = [
                        'characteristic_id' => $char['variant_characteristic_id'],
                        'characteristic_value' => $char['characteristic_value'],
                        'characteristic_alias' => $char['characteristic_alias']
                    ];
                }
            }
        } else {
            $item['variant_code'] = null;
            $item['characteristics'] = [];
        }

        // Subtotal
        $subtotal = floatval($item['product_sort_price']) * intval($item['cart_product_quantity']);
        $item['subtotal'] = number_format($subtotal, 2, '.', '');
        $totalAmount += $subtotal;
        $cartProducts[] = $item;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'cart_id' => $cartId,
        'count' => count($cartProducts),
        'total_amount' => number_format($totalAmount, 2, '.', ''),
        'data' => $cartProducts
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("❌ cart.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor', 'code' => 'DATABASE_ERROR', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    error_log("❌ cart.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor', 'code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()]);
}
