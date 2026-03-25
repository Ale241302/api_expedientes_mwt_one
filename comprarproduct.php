<?php

/**
 * comprarproduct.php - LÓGICA EXACTA: Grupo 21 → muitowork → hikashop_price → real
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

if (
    !isset($payload->keyhash, $payload->keyuser, $payload->cart_id) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->cart_id)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'cart_id'],
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
    $cartId = intval($payload->cart_id);

    // Grupos del usuario
    $userGroupsStmt = $db->prepare("SELECT group_id FROM {$prefix}user_usergroup_map WHERE user_id = :user_id");
    $userGroupsStmt->execute([':user_id' => $userCmsId]);
    $userGroups = $userGroupsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($userGroups)) $userGroups = [0];

    // CALCULAR hasGroup21 UNA VEZ
    $hasGroup21 = in_array(21, $userGroups);

    // HikaShop user
    $hikashopUserStmt = $db->prepare("SELECT user_id FROM {$prefix}hikashop_user WHERE user_cms_id = :user_cms_id LIMIT 1");
    $hikashopUserStmt->execute([':user_cms_id' => $userCmsId]);
    $hikashopUser = $hikashopUserStmt->fetch();
    if (!$hikashopUser) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuario no tiene cuenta HikaShop', 'code' => 'NO_HIKASHOP_USER']);
        exit;
    }
    $hikashopUserId = $hikashopUser['user_id'];

    // Verificar carrito
    $cartStmt = $db->prepare("SELECT cart_id FROM {$prefix}hikashop_cart WHERE cart_id = :cart_id AND user_id = :user_id LIMIT 1");
    $cartStmt->execute([':cart_id' => $cartId, ':user_id' => $hikashopUserId]);
    $cart = $cartStmt->fetch();
    if (!$cart) {
        http_response_code(404);
        echo json_encode(['error' => 'Carrito no encontrado', 'code' => 'CART_NOT_FOUND']);
        exit;
    }

    // QUERY PARA ITEMS DEL CARRITO (sin precios de hikashop_price)
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
            parent.product_price_real_muitowork AS parent_price_real_muitowork
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
        http_response_code(400);
        echo json_encode(['error' => 'Carrito vacío', 'code' => 'EMPTY_CART']);
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

    /* ================================ PROCESAR PRODUCTOS CON LÓGICA DE PRECIOS ================================ */
    $cartProducts = [];
    $totalPrice = 0;

    foreach ($cartItems as $row) {
        $isVariant = ($row['product_type'] === 'variant');
        $mainProductId = $isVariant ? $row['product_parent_id'] : $row['product_id'];

        /* ================================ LÓGICA DE PRECIOS (EXACTA A cart.php) ================================ */
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

        // Validar que hay precio
        if ($product_sort_price === null || $product_sort_price <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Producto sin precio válido: ' . $row['product_id'], 'code' => 'NO_VALID_PRICE']);
            exit;
        }

        $product = [
            'cart_product_id' => $row['cart_product_id'],
            'product_id' => $row['product_id'],
            'cart_product_quantity' => $row['cart_product_quantity'],
            'product_sort_price' => $product_sort_price,
            'product_name' => $isVariant && $row['parent_name'] ? $row['parent_name'] : $row['product_name'],
            'product_code' => $isVariant ? $row['product_code'] : ($row['parent_code'] ?? $row['product_code'])
        ];

        $subtotal = $product_sort_price * intval($row['cart_product_quantity']);
        $totalPrice += $subtotal;
        $cartProducts[] = $product;
    }

    /* ================================ GENERAR ORDEN ================================ */
    // Generar order_number
    $currentYear = date('Y');
    $lastOrderStmt = $db->prepare("SELECT order_number FROM {$prefix}hikashop_order WHERE order_number LIKE 'MWT-%' ORDER BY order_id DESC LIMIT 1");
    $lastOrderStmt->execute();
    $lastOrder = $lastOrderStmt->fetch();

    $newNumber = 1;
    if ($lastOrder && preg_match('/MWT-(\d+)-(\d{4})/', $lastOrder['order_number'], $matches)) {
        $lastNumber = intval($matches[1]);
        $lastYear = intval($matches[2]);
        $newNumber = ($lastYear == $currentYear) ? $lastNumber + 1 : 1;
    }
    $orderNumber = sprintf('MWT-%04d-%s', $newNumber, $currentYear);

    // Datos serializados
    $currencyInfo = serialize((object)['currency_code' => 'USD', 'currency_rate' => '1.000000', 'currency_percent_fee' => '0.00', 'currency_modified' => time()]);
    $emptyObject = serialize(new stdClass());
    $timestamp = time();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Insertar orden
    $insertOrderStmt = $db->prepare("
        INSERT INTO {$prefix}hikashop_order (
            order_billing_address_id, order_shipping_address_id, order_user_id, order_parent_id,
            order_status, order_type, order_number, order_created, order_modified,
            order_invoice_id, order_invoice_number, order_invoice_created,
            order_currency_id, order_currency_info, order_full_price,
            order_discount_code, order_discount_tax, order_payment_method, order_payment_price, order_payment_tax,
            order_payment_params, order_shipping_id, order_shipping_tax, order_shipping_params,
            order_partner_id, order_partner_price, order_partner_paid, order_partner_currency_id, order_ip
        ) VALUES (
            0, 0, :user_id, 0, 'confirmed', 'sale', :order_number, :created, :modified,
            0, 0, 0, 2, :currency_info, :total_price,
            '', 0, '', 0, 0, :payment_params, 0, 0, :shipping_params,
            0, 0, 0, 0, :ip
        )
    ");

    $insertOrderStmt->execute([
        ':user_id' => $hikashopUserId,
        ':order_number' => $orderNumber,
        ':created' => $timestamp,
        ':modified' => $timestamp,
        ':currency_info' => $currencyInfo,
        ':total_price' => $totalPrice,
        ':payment_params' => $emptyObject,
        ':shipping_params' => $emptyObject,
        ':ip' => $ipAddress
    ]);

    $orderId = $db->lastInsertId();

    // Insertar productos en orden
    $insertProductStmt = $db->prepare("
        INSERT INTO {$prefix}hikashop_order_product (
            order_id, product_id, order_product_quantity, order_product_name, order_product_code,
            order_product_price, order_product_tax, order_product_tax_info, order_product_options,
            order_product_option_parent_id, order_product_status, order_product_wishlist_id,
            order_product_wishlist_product_id, order_product_shipping_id, order_product_shipping_method,
            order_product_shipping_price, order_product_shipping_tax, order_product_shipping_params,
            order_product_params, order_product_weight, order_product_weight_unit,
            order_product_width, order_product_length, order_product_height, order_product_dimension_unit,
            order_product_price_before_discount, order_product_tax_before_discount,
            order_product_discount_code, order_product_discount_info
        ) VALUES (
            :order_id, :product_id, :quantity, :product_name, :product_code,
            :price, 0, 'a:0:{}', 'a:0:{}', 0, 0, 0, 0, 0, '',
            0, 0, NULL, NULL, 0, 'kg', 0, 0, 0, 'm', :price, 0, '', NULL
        )
    ");

    foreach ($cartProducts as $product) {
        $insertProductStmt->execute([
            ':order_id' => $orderId,
            ':product_id' => $product['product_id'],
            ':quantity' => $product['cart_product_quantity'],
            ':product_name' => $product['product_name'],
            ':product_code' => $product['product_code'],
            ':price' => $product['product_sort_price']
        ]);

        // Actualizar product_hit
        $parentStmt = $db->prepare("SELECT product_parent_id FROM {$prefix}hikashop_product WHERE product_id = :product_id");
        $parentStmt->execute([':product_id' => $product['product_id']]);
        $parentData = $parentStmt->fetch();
        $productToUpdate = ($parentData && $parentData['product_parent_id'] > 0) ? $parentData['product_parent_id'] : $product['product_id'];

        $updateHitStmt = $db->prepare("UPDATE {$prefix}hikashop_product SET product_hit = COALESCE(product_hit, 0) + :quantity WHERE product_id = :product_id");
        $updateHitStmt->execute([':quantity' => $product['cart_product_quantity'], ':product_id' => $productToUpdate]);
    }

    // Vaciar carrito
    $deleteCartStmt = $db->prepare("DELETE FROM {$prefix}hikashop_cart_product WHERE cart_id = :cart_id");
    $deleteCartStmt->execute([':cart_id' => $cartId]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Compra procesada exitosamente',
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'total_price' => number_format($totalPrice, 2, '.', ''),
        'items_count' => count($cartProducts)
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("❌ comprarproduct.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor', 'code' => 'DATABASE_ERROR', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    error_log("❌ comprarproduct.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor', 'code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()]);
}
