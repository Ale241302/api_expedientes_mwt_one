<?php

/**
 * login.php
 * Endpoint para autenticar usuarios y obtener sus credenciales + grupos
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
    !isset($payload->keyhash, $payload->email, $payload->password) ||
    empty($payload->keyhash) || empty($payload->email) || empty($payload->password)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'email', 'password'],
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

    // Buscar usuario por email
    $userStmt = $db->prepare("
        SELECT id, keyuser, password, name, email 
        FROM {$prefix}users 
        WHERE email = :email 
        LIMIT 1
    ");
    $userStmt->execute([':email' => $payload->email]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Credenciales inválidas',
            'code' => 'INVALID_CREDENTIALS'
        ]);
        exit;
    }

    // Verificar password con password_verify (para hashes bcrypt)
    if (!password_verify($payload->password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Credenciales inválidas',
            'code' => 'INVALID_CREDENTIALS'
        ]);
        exit;
    }

    /* ================================ OBTENER GRUPOS DEL USUARIO ================================ */
    $groupsStmt = $db->prepare("
        SELECT 
            ug.id AS group_id,
            ug.title AS group_title,
            ug.parent_id AS group_parent_id
        FROM {$prefix}user_usergroup_map ugm
        INNER JOIN {$prefix}usergroups ug ON ug.id = ugm.group_id
        WHERE ugm.user_id = :user_id
        ORDER BY ug.title ASC
    ");
    $groupsStmt->execute([':user_id' => $user['id']]);
    $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear grupos para la respuesta
    $userGroups = [];
    foreach ($groups as $group) {
        $userGroups[] = [
            'group_id' => $group['group_id'],
            'group_title' => $group['group_title'],
            'group_parent_id' => $group['group_parent_id']
        ];
    }

    // Retornar datos del usuario con sus grupos
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login exitoso',
        'data' => [
            'id' => $user['id'],
            'keyuser' => $user['keyuser'],
            'name' => $user['name'],
            'email' => $user['email'],
            'groups' => $userGroups,
            'groups_count' => count($userGroups)
        ]
    ]);
} catch (PDOException $e) {
    error_log("❌ Error en login.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en login.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
