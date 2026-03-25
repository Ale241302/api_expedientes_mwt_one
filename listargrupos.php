<?php

/**
 * buscarproducto.php
 * Endpoint para traer usergroups con productos y precios relacionados
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
    !isset($payload->keyhash, $payload->keyuser) ||
    empty($payload->keyhash) || empty($payload->keyuser)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser'],
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

    /* ================================ TRAER USERGROUPS ================================ */
    // IDs a omitir
    $excludeIds = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 24, 25, 26];
    $placeholders = str_repeat('?,', count($excludeIds) - 1) . '?';
    $sql = "SELECT id, title FROM {$prefix}usergroups WHERE id NOT IN ({$placeholders}) ORDER BY title ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($excludeIds);
    $usergroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================================ ENRIQUECER CON PRODUCTOS Y PRECIOS ================================ */
    $enrichedUsergroups = [];

    foreach ($usergroups as $usergroup) {
        $usergroupId = $usergroup['id'];

        // 1. Buscar productos donde product_access contiene el ID del usergroup
        $productSql = "SELECT 
                product_id, 
                product_name, 
                product_code, 
                product_price_real, 
                product_price_real_muitowork
            FROM {$prefix}hikashop_product 
            WHERE product_type = 'main' 
            AND FIND_IN_SET(?, product_access) > 0";

        $productStmt = $db->prepare($productSql);
        $productStmt->execute([$usergroupId]);
        $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Para cada producto, buscar precios donde price_access contiene el ID del usergroup
        $productsWithPrices = [];
        foreach ($products as $product) {
            $priceSql = "SELECT 
                    price_value, 
                    por_comision,
                    FROM_UNIXTIME(price_start_date) as price_start_date,
                    FROM_UNIXTIME(price_end_date) as price_end_date
                FROM {$prefix}hikashop_price 
                WHERE price_product_id = ? 
                AND FIND_IN_SET(?, price_access) > 0";

            $priceStmt = $db->prepare($priceSql);
            $priceStmt->execute([$product['product_id'], $usergroupId]);
            $prices = $priceStmt->fetchAll(PDO::FETCH_ASSOC);

            $productsWithPrices[] = [
                'product_id' => $product['product_id'],
                'product_name' => $product['product_name'],
                'product_code' => $product['product_code'],
                'product_price_real' => $product['product_price_real'],
                'product_price_real_muitowork' => $product['product_price_real_muitowork'],
                'prices' => $prices
            ];
        }

        $enrichedUsergroups[] = [
            'usergroup' => $usergroup,
            'products' => $productsWithPrices
        ];
    }

    // Retornar resultado enriquecido
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'total_usergroups' => count($usergroups),
        'total_products' => array_sum(array_map(function ($ug) {
            return count($ug['products']);
        }, $enrichedUsergroups)),
        'data' => $enrichedUsergroups
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en buscarproducto.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en buscarproducto.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
