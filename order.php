<?php

/**
 * order.php
 * Endpoint para listar pedidos de todos los clientes asociados a un usuario (keyuser)
 * Incluye pedidos sin customer pero con order_user_id del usuario
 * Admins ven todos los pedidos sin restricción
 * Incluye información del pedido padre si order_parent_id existe
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

    /* ================================ Consulta de Pedidos ================================ */

    if ($isAdmin) {
        // ADMIN: Traer TODOS los pedidos sin restricción
        $query = "
        SELECT 
            o.order_status,
            o.order_number,
            o.order_invoice_number,
            o.order_full_price,
            o.order_full_price_diferido,
            o.visibilidad_diferido,
            o.order_id,
            o.order_user_id,
            o.customer,
            o.operado_mwt,
            o.order_parent_id,
            FROM_UNIXTIME(o.order_created) AS order_created_date,
            YEAR(FROM_UNIXTIME(o.order_created)) AS order_year,
            pf.number_purchase AS preforma_number_purchase,
            inv.number_invoice AS invoice_number,
            sh.number_guia AS shipping_number_guia,
            sh.fechas AS shipping_fechas,
            sh.fecha_arribo AS shipping_fecha_arribo,
            sap.number_sap AS sap_number_preformar,
            sap.number_purchase AS sap_number_purchase,
            sap.number_preforma_mwt AS sap_number_preforma_mwt,
            sap.number_preforma AS sap_number_preforma,
            prod.fechai AS prod_fechai,
            prod.fechaf AS prod_fechaf,
            cust.customer_name AS cust_customer_name,
            -- NUEVO: Información del pedido padre
            op.order_number AS order_number_parent,
            ppf.number_purchase AS preforma_number_purchase_parent
        FROM {$prefix}hikashop_order o
        LEFT JOIN {$prefix}preforma pf ON pf.order_number = o.order_number
        LEFT JOIN {$prefix}invoice inv ON inv.order_number = o.order_number
        LEFT JOIN {$prefix}shipping sh ON sh.order_number = o.order_number
        LEFT JOIN {$prefix}sap sap ON sap.order_number = o.order_number
        LEFT JOIN {$prefix}produccion prod ON prod.order_number = o.order_number
        LEFT JOIN {$prefix}customer cust ON cust.customer_id = o.customer
        -- NUEVO: JOIN con pedido padre
        LEFT JOIN {$prefix}hikashop_order op ON op.order_id = o.order_parent_id
        LEFT JOIN {$prefix}preforma ppf ON ppf.order_number = op.order_number
        ORDER BY o.order_id DESC
        ";

        $stmt = $db->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // NO ADMIN: Aplicar restricciones normales

        // Obtener hikashop_user_id desde user_cms_id
        $hikashopUserStmt = $db->prepare("SELECT user_id FROM {$prefix}hikashop_user WHERE user_cms_id = :user_cms_id LIMIT 1");
        $hikashopUserStmt->execute([':user_cms_id' => $userId]);
        $hikashopUser = $hikashopUserStmt->fetch(PDO::FETCH_ASSOC);

        $hikashopUserId = $hikashopUser ? $hikashopUser['user_id'] : null;

        // Obtener todos los customer_id del usuario
        $custStmt = $db->prepare("SELECT customer_id FROM {$prefix}customer_user WHERE user_id = :user_id");
        $custStmt->execute([':user_id' => $userId]);
        $customerIds = $custStmt->fetchAll(PDO::FETCH_COLUMN);

        // Si no tiene clientes ni hikashop_user, retornar vacío
        if (empty($customerIds) && !$hikashopUserId) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'count' => 0,
                'data' => []
            ]);
            exit;
        }

        // Construir WHERE clause dinámico
        $whereConditions = [];
        $params = [];

        if (!empty($customerIds)) {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
            $whereConditions[] = "o.customer IN ($placeholders)";
            $params = array_merge($params, $customerIds);
        }

        if ($hikashopUserId) {
            $whereConditions[] = "(o.order_user_id = ? AND (o.customer IS NULL OR o.customer = 0 OR o.customer = ''))";
            $params[] = $hikashopUserId;
        }

        $whereClause = implode(' OR ', $whereConditions);

        // Consulta con restricciones + información padre
        $query = "
        SELECT 
            o.order_status,
            o.order_number,
            o.order_invoice_number,
            o.order_full_price,
            o.order_full_price_diferido,
            o.visibilidad_diferido,
            o.order_id,
            o.order_user_id,
            o.customer,
            o.operado_mwt,
            o.order_parent_id,
            FROM_UNIXTIME(o.order_created) AS order_created_date,
            YEAR(FROM_UNIXTIME(o.order_created)) AS order_year,
            pf.number_purchase AS preforma_number_purchase,
            inv.number_invoice AS invoice_number,
            sh.number_guia AS shipping_number_guia,
            sh.fechas AS shipping_fechas,
            sh.fecha_arribo AS shipping_fecha_arribo,
            sap.number_sap AS sap_number_preformar,
            sap.number_purchase AS sap_number_purchase,
            sap.number_preforma_mwt AS sap_number_preforma_mwt,
            sap.number_preforma AS sap_number_preforma,
            prod.fechai AS prod_fechai,
            prod.fechaf AS prod_fechaf,
            cust.customer_name AS cust_customer_name,
            -- NUEVO: Información del pedido padre
            op.order_number AS order_number_parent,
            ppf.number_purchase AS preforma_number_purchase_parent
        FROM {$prefix}hikashop_order o
        LEFT JOIN {$prefix}preforma pf ON pf.order_number = o.order_number
        LEFT JOIN {$prefix}invoice inv ON inv.order_number = o.order_number
        LEFT JOIN {$prefix}shipping sh ON sh.order_number = o.order_number
        LEFT JOIN {$prefix}sap sap ON sap.order_number = o.order_number
        LEFT JOIN {$prefix}produccion prod ON prod.order_number = o.order_number
        LEFT JOIN {$prefix}customer cust ON cust.customer_id = o.customer
        -- NUEVO: JOIN con pedido padre
        LEFT JOIN {$prefix}hikashop_order op ON op.order_id = o.order_parent_id
        LEFT JOIN {$prefix}preforma ppf ON ppf.order_number = op.order_number
        WHERE $whereClause
        ORDER BY o.order_id DESC
        ";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Desduplicar por order_number
    $unique = [];
    foreach ($rows as $row) {
        $orderNumber = $row['order_number'];
        if (!isset($unique[$orderNumber])) {
            $unique[$orderNumber] = $row;
        }
    }

    // Retornar resultados
    $orders = array_values($unique);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count' => count($orders),
        'data' => $orders,
        'is_admin' => $isAdmin
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
