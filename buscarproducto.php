<?php

/**
 * buscarproducto.php
 * Endpoint para buscar productos MAIN por nombre o código
 * Búsqueda parcial con LIKE %term%
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
    !isset($payload->keyhash, $payload->keyuser, $payload->buscador) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->buscador)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'buscador'],
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
    $searchTerm = trim($payload->buscador);

    // Obtener grupos del usuario para validar acceso
    $groupStmt = $db->prepare("SELECT group_id FROM {$prefix}user_usergroup_map WHERE user_id = :user_id");
    $groupStmt->execute([':user_id' => $userId]);
    $userGroups = $groupStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($userGroups)) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuario sin grupos de acceso', 'code' => 'NO_ACCESS_GROUPS']);
        exit;
    }

    /* ================================ CONDICIÓN DE ACCESO ================================ */
    $productAccessConditions = ["p.product_access = 'all'"];
    foreach ($userGroups as $groupId) {
        $productAccessConditions[] = "CONCAT(',', p.product_access, ',') LIKE '%,{$groupId},%'";
    }
    $productAccessWhere = '(' . implode(' OR ', $productAccessConditions) . ')';

    /* ================================ BUSCAR SOLO PRODUCTOS MAIN ================================ */
    // Primero por product_name (LIKE %50b22%)
    $searchQuery = "
        SELECT 
            p.product_id,
            p.product_name,
            p.product_code,
            p.product_price_real,
            p.product_type,
            p.product_alias
        FROM {$prefix}hikashop_product p
        WHERE p.product_published = 1
        AND p.product_type = 'main'
        AND $productAccessWhere
        AND p.product_name LIKE :search_name
        ORDER BY p.product_name ASC, p.product_code ASC
        LIMIT 50
    ";

    $searchStmt = $db->prepare($searchQuery);
    $searchStmt->execute([':search_name' => "%{$searchTerm}%"]);
    $results = $searchStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================================ SI NO ENCUENTRA POR NOMBRE, BUSCAR POR CÓDIGO ================================ */
    if (empty($results)) {
        $searchQuery = "
            SELECT 
                p.product_id,
                p.product_name,
                p.product_code,
                p.product_price_real,
                p.product_type,
                p.product_alias
            FROM {$prefix}hikashop_product p
            WHERE p.product_published = 1
            AND p.product_type = 'main'
            AND $productAccessWhere
            AND p.product_code LIKE :search_code
            ORDER BY p.product_code ASC
            LIMIT 50
        ";

        $searchStmt = $db->prepare($searchQuery);
        $searchStmt->execute([':search_code' => "%{$searchTerm}%"]);
        $results = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================================ FORMATEAR RESULTADOS ================================ */
    $products = [];
    foreach ($results as $row) {
        $products[] = [
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'product_code' => $row['product_code'],
            'product_price_real' => floatval($row['product_price_real'] ?? 0),
            'product_type' => $row['product_type'], // Siempre "main"
            'product_alias' => $row['product_alias']
        ];
    }

    // Retornar resultado
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'search_term' => $searchTerm,
        'search_type' => empty($results) ? 'code' : 'name',
        'total_results' => count($products),
        'data' => $products
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
