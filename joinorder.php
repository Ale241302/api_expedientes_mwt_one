<?php

/**
 * joinorder.php
 * Endpoint para unir pedidos - establece relación parent/child entre pedidos
 * Solo administrators pueden ejecutar esta acción
 * order_number_parent se convierte en padre de order_number (uno o varios)
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

// Leer payload como array asociativo
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

// Normalizar campos
$keyhash    = $payload['keyhash']             ?? null;
$keyuser    = $payload['keyuser']             ?? null;
$orderNumbers = $payload['order_number']      ?? null;
$orderParent  = $payload['order_number_parent'] ?? null;

// Permitir string o array en order_number
if (is_string($orderNumbers)) {
    $orderNumbers = [$orderNumbers];
}

// Validar campos obligatorios
if (
    !isset($keyhash, $keyuser, $orderNumbers, $orderParent) ||
    empty($keyhash) || empty($keyuser) ||
    empty($orderNumbers) || empty($orderParent)
) {
    http_response_code(400);
    echo json_encode([
        'error'    => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'order_number', 'order_number_parent'],
        'code'     => 'MISSING_FIELDS'
    ]);
    exit;
}

// Validar keyhash
if ($keyhash !== $Keyhas) {
    http_response_code(401);
    echo json_encode(['error' => 'Keyhash inválido', 'code' => 'INVALID_KEYHASH']);
    exit;
}

try {
    // Conectar a BD
    $db     = db_connect($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
    $prefix = $DB_PREFIX;

    // Obtener user_id (CMS ID) desde keyuser
    $userStmt = $db->prepare("SELECT id FROM {$prefix}users WHERE keyuser = :keyuser LIMIT 1");
    $userStmt->execute([':keyuser' => $keyuser]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Usuario no encontrado',
            'code'  => 'USER_NOT_FOUND'
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
            'error' => 'Acceso denegado. Solo administrators pueden unir pedidos.',
            'code'  => 'FORBIDDEN'
        ]);
        exit;
    }

    /* ================================ Iniciar Transacción ================================ */
    $db->beginTransaction();

    /* ================================ Buscar Pedido Padre ================================ */
    $parentOrderStmt = $db->prepare("
        SELECT order_id 
        FROM {$prefix}hikashop_order 
        WHERE order_number = :order_number_parent 
        LIMIT 1
    ");
    $parentOrderStmt->execute([':order_number_parent' => $orderParent]);
    $parentOrder = $parentOrderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$parentOrder) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            'error' => 'Pedido padre no encontrado',
            'code'  => 'PARENT_ORDER_NOT_FOUND'
        ]);
        exit;
    }

    $parentOrderId = $parentOrder['order_id'];

    /* ================================ Preparar consultas hijo ================================ */
    $childOrderStmt = $db->prepare("
        SELECT order_id, order_parent_id 
        FROM {$prefix}hikashop_order 
        WHERE order_number = :order_number 
        LIMIT 1
    ");

    $updateChildStmt = $db->prepare("
        UPDATE {$prefix}hikashop_order 
        SET order_parent_id = :parent_order_id, order_modified = :modified 
        WHERE order_id = :child_order_id
    ");

    $results = [];

    foreach ($orderNumbers as $childNumber) {
        // Buscar pedido hijo
        $childOrderStmt->execute([':order_number' => $childNumber]);
        $childOrder = $childOrderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$childOrder) {
            $results[] = [
                'order_number' => $childNumber,
                'success'      => false,
                'code'         => 'CHILD_ORDER_NOT_FOUND'
            ];
            continue;
        }

        $childOrderId    = $childOrder['order_id'];
        $currentParentId = $childOrder['order_parent_id'];

        // Prevenir auto-referencia
        if ($childOrderId == $parentOrderId) {
            $results[] = [
                'order_number' => $childNumber,
                'success'      => false,
                'code'         => 'SELF_REFERENCE'
            ];
            continue;
        }

        // Verificar que no tenga ya un padre
        if (!empty($currentParentId) && $currentParentId != 0) {
            $results[] = [
                'order_number'      => $childNumber,
                'success'           => false,
                'code'              => 'ALREADY_HAS_PARENT',
                'current_parent_id' => $currentParentId
            ];
            continue;
        }

        // Establecer relación padre-hijo
        $updateChildStmt->execute([
            ':parent_order_id' => $parentOrderId,
            ':modified'        => time(),
            ':child_order_id'  => $childOrderId
        ]);

        $results[] = [
            'order_number'  => $childNumber,
            'success'       => true,
            'new_parent_id' => $parentOrderId
        ];
    }

    // Commit transacción
    $db->commit();

    /* ================================ Respuesta Exitosa ================================ */
    http_response_code(200);
    echo json_encode([
        'success'      => true,
        'message'      => 'Procesamiento de unión de pedidos completado',
        'parent_order' => [
            'order_number' => $orderParent,
            'order_id'     => $parentOrderId
        ],
        'results'  => $results,
        'is_admin' => $isAdmin
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en joinorder.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'Error interno del servidor',
        'code'    => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Error en joinorder.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'Error interno del servidor',
        'code'    => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
