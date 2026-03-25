<?php

/**
 * monitor.php
 * Endpoint para listar pedidos de todos los clientes asociados a un usuario (keyuser)
 * Incluye pedidos sin customer pero con order_user_id del usuario
 * Incluye logs de tracking del día actual (solo los no notificados)
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

    $userId = $user['id'];

    // Obtener hikashop_user_id desde user_cms_id
    $hikashopUserStmt = $db->prepare("SELECT user_id FROM {$prefix}hikashop_user WHERE user_cms_id = :user_cms_id LIMIT 1");
    $hikashopUserStmt->execute([':user_cms_id' => $userId]);
    $hikashopUser = $hikashopUserStmt->fetch(PDO::FETCH_ASSOC);

    $hikashopUserId = $hikashopUser ? $hikashopUser['user_id'] : null;

    // Obtener todos los customer_id del usuario
    $custStmt = $db->prepare("SELECT customer_id FROM {$prefix}customer_user WHERE user_id = :user_id");
    $custStmt->execute([':user_id' => $userId]);
    $customerIds = $custStmt->fetchAll(PDO::FETCH_COLUMN);

    // Construir consulta dinámica
    if (empty($customerIds) && !$hikashopUserId) {
        // Usuario sin clientes ni hikashop_user
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
        // Crear placeholders para IN clause
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $whereConditions[] = "o.customer IN ($placeholders)";
        $params = array_merge($params, $customerIds);
    }

    if ($hikashopUserId) {
        // Agregar condición para pedidos sin customer pero con order_user_id
        $whereConditions[] = "(o.order_user_id = ? AND (o.customer IS NULL OR o.customer = 0 OR o.customer = ''))";
        $params[] = $hikashopUserId;
    }

    $whereClause = implode(' OR ', $whereConditions);

    $query = "
        SELECT 
            o.order_status,
            o.order_number,
            o.order_id
        FROM {$prefix}hikashop_order o
        LEFT JOIN {$prefix}customer cust ON cust.customer_id = o.customer
        WHERE $whereClause
        ORDER BY o.order_id DESC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Desduplicar por order_number
    $unique = [];
    $orderNumbers = [];
    foreach ($rows as $row) {
        $orderNumber = $row['order_number'];
        if (!isset($unique[$orderNumber])) {
            $unique[$orderNumber] = $row;
            $orderNumbers[] = $orderNumber;
        }
    }

    $orders = array_values($unique);

    /* ================================ Consulta a log_apitracking ================================ */
    $trackingLogs = [];

    if (!empty($orderNumbers)) {
        // Fecha actual (solo fecha, sin hora)
        $currentDate = date('Y-m-d');

        // Funciones permitidas
        $allowedFunctions = [
            'createPreforma',
            'changeStatus',
            'updateCustomerCredit',
            'createSAP',
            'changeStatusToPreparacion',
            'crearPreparacion',
            'changeStatusToDespacho',
            'changeStatusToTransito',
            'crearDespach',
            'changeStatusToPagado',
            'crearTransito',
            'changeStatusToArchivada',
            'createPago'
        ];

        // Crear placeholders para order_numbers
        $orderPlaceholders = implode(',', array_fill(0, count($orderNumbers), '?'));

        // Crear placeholders para funciones
        $functionPlaceholders = implode(',', array_fill(0, count($allowedFunctions), '?'));

        $trackingQuery = "
            SELECT 
                id,
                order_number,
                funcion_handler,
                response_status,
                fecha_creacion
            FROM log_apitracking
            WHERE DATE(fecha_creacion) = ?
                AND response_status = 200
                AND funcion_handler IN ($functionPlaceholders)
                AND order_number IN ($orderPlaceholders)
            ORDER BY fecha_creacion DESC
        ";

        // Preparar parámetros
        $trackingParams = array_merge(
            [$currentDate],
            $allowedFunctions,
            $orderNumbers
        );

        $trackingStmt = $db->prepare($trackingQuery);
        $trackingStmt->execute($trackingParams);
        $trackingLogs = $trackingStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================================ Filtrar logs ya notificados ================================ */
    $newTrackingLogs = [];
    $notifiedLogIds = [];

    if (!empty($trackingLogs)) {
        // Obtener los id_logo que ya fueron notificados para este usuario
        $notifiedStmt = $db->prepare("
            SELECT id_logo 
            FROM log_noti 
            WHERE id_user = :id_user
        ");
        $notifiedStmt->execute([':id_user' => $userId]);
        $notifiedLogIds = $notifiedStmt->fetchAll(PDO::FETCH_COLUMN);

        // Convertir a array asociativo para búsqueda rápida
        $notifiedMap = array_flip($notifiedLogIds);

        // Filtrar tracking_logs para excluir los ya notificados
        $newTrackingLogs = array_filter($trackingLogs, function ($log) use ($notifiedMap) {
            return !isset($notifiedMap[$log['id']]);
        });

        // Reindexar el array
        $newTrackingLogs = array_values($newTrackingLogs);
    }

    /* ================================ Guardar en log_noti ================================ */
    $notificationsInserted = 0;

    if (!empty($newTrackingLogs)) {
        try {
            // Preparar statement para INSERT
            $insertStmt = $db->prepare("
                INSERT IGNORE INTO log_noti (id_user, id_logo) 
                VALUES (:id_user, :id_logo)
            ");

            // Iniciar transacción para mejor rendimiento
            $db->beginTransaction();

            foreach ($newTrackingLogs as $log) {
                $insertStmt->execute([
                    ':id_user' => $userId,
                    ':id_logo' => $log['id']
                ]);
                $notificationsInserted += $insertStmt->rowCount();
            }

            // Confirmar transacción
            $db->commit();
        } catch (Exception $e) {
            // Revertir transacción en caso de error
            $db->rollBack();
            error_log("❌ Error al guardar notificaciones: " . $e->getMessage());
        }
    }

    /* ================================ Respuesta Final ================================ */
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count' => count($orders),
        'data' => $orders,
        'tracking_count' => count($newTrackingLogs),
        'tracking_logs' => $newTrackingLogs,
        'notifications_saved' => $notificationsInserted
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en monitor.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en monitor.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
