<?php

/**
 * detalleproduct.php
 * Endpoint para obtener el detalle de un producto específico
 * con control de acceso y mapeo de imágenes desde plugin wordtoimage
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

/* ================================ FUNCIONES AUXILIARES ================================ */

/**
 * Normaliza string para comparación sin tildes/acentos
 */
function normalizeString($str)
{
    if (empty($str)) return '';

    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($str, Normalizer::FORM_D);
        $normalized = preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized);
    } else {
        $normalized = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ', 'ü', 'Ü'],
            ['a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N', 'u', 'U'],
            $str
        );
    }

    return strtolower(trim($normalized));
}

/**
 * Busca imagen por texto en el mapeo del plugin
 */
function findImageByText($text, $wordToImageMap)
{
    if (empty($text) || empty($wordToImageMap)) {
        return null;
    }

    $textNormalized = normalizeString($text);

    foreach ($wordToImageMap as $word => $imageData) {
        $wordNormalized = normalizeString($word);

        if ($textNormalized === $wordNormalized) {
            return $imageData;
        }

        if (
            strpos($textNormalized, $wordNormalized) !== false ||
            strpos($wordNormalized, $textNormalized) !== false
        ) {
            return $imageData;
        }
    }

    return null;
}

/**
 * Procesa múltiples valores separados por coma
 */
function processMultipleValues($value, $wordToImageMap)
{
    if (empty($value)) return [];

    $values = array_map('trim', explode(',', $value));
    $result = [];

    foreach ($values as $val) {
        $imageData = findImageByText($val, $wordToImageMap);
        $result[] = [
            'text' => $val,
            'image_url' => $imageData['image_url'] ?? null,
            'width' => $imageData['width'] ?? null,
            'height' => $imageData['height'] ?? null,
            'description' => $imageData['description'] ?? null,
            'class' => $imageData['class'] ?? null
        ];
    }

    return $result;
}

/* ================================ Validación Inicial ================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido. Usa POST.', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw);

if (
    !isset($payload->keyhash, $payload->keyuser, $payload->product_id) ||
    empty($payload->keyhash) || empty($payload->keyuser) || empty($payload->product_id)
) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Faltan campos obligatorios',
        'required' => ['keyhash', 'keyuser', 'product_id'],
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

    /* ================================ OBTENER MAPEO DE IMÁGENES ================================ */
    $pluginStmt = $db->prepare("SELECT plugin_params FROM {$prefix}hikashop_plugin WHERE plugin_type = 'wordtoimage' LIMIT 1");
    $pluginStmt->execute();
    $pluginRow = $pluginStmt->fetch(PDO::FETCH_ASSOC);

    $wordToImageMap = [];

    if ($pluginRow && !empty($pluginRow['plugin_params'])) {
        $pluginData = @unserialize($pluginRow['plugin_params']);

        if ($pluginData && is_object($pluginData)) {
            $words = $pluginData->word_to_replace ?? [];
            $images = $pluginData->image_to_insert ?? [];
            $widths = $pluginData->width ?? [];
            $heights = $pluginData->height ?? [];
            $descriptions = $pluginData->descripcion ?? [];
            $classes = $pluginData->clase ?? [];

            foreach ($words as $index => $word) {
                if (!empty($word) && !empty($images[$index])) {
                    $wordToImageMap[$word] = [
                        'image_url' => $images[$index],
                        'width' => $widths[$index] ?? null,
                        'height' => $heights[$index] ?? null,
                        'description' => $descriptions[$index] ?? null,
                        'class' => $classes[$index] ?? null
                    ];
                }
            }
        }
    }

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
    $productId = intval($payload->product_id);

    // Obtener grupos del usuario
    $groupStmt = $db->prepare("SELECT group_id FROM {$prefix}user_usergroup_map WHERE user_id = :user_id");
    $groupStmt->execute([':user_id' => $userId]);
    $userGroups = $groupStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($userGroups)) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuario sin grupos de acceso', 'code' => 'NO_ACCESS_GROUPS']);
        exit;
    }

    /* ================================ CONSULTAR PRODUCTO ================================ */
    $productAccessConditions = ["p.product_access = 'all'"];
    foreach ($userGroups as $groupId) {
        $productAccessConditions[] = "CONCAT(',', p.product_access, ',') LIKE '%," . intval($groupId) . ",%'";
    }
    $productAccessWhere = '(' . implode(' OR ', $productAccessConditions) . ')';

    $query = "
        SELECT 
            p.product_id,
            p.product_parent_id,
            p.product_name,
            p.product_code,
            p.product_type,
            p.product_alias,
            p.product_access,
            p.product_price_real,
            p.product_price_real_muitowork,
            p.product_puntera,
            p.product_suela,
            p.product_color,
            p.product_calzado,
            p.product_antiperforante,
            p.product_disipativo,
            p.product_metatarsal,
            p.product_capellado,
            p.product_cierre,
            p.product_normativa,
            p.product_segmento,
            p.producrt_riesgo,
            p.product_componentes_reciclados,
            p.product_cubrepuntera,
            p.product_plantilla,
            p.product_ncm,
            f.file_id,
            f.file_name,
            f.file_type,
            f.file_path,
            CONCAT('https://mwt.one/images/com_hikashop/upload/', f.file_path) AS file_url,
            pr.price_value,
            pr.price_access
        FROM {$prefix}hikashop_product p
        LEFT JOIN {$prefix}hikashop_file f ON f.file_ref_id = p.product_id
        LEFT JOIN {$prefix}hikashop_price pr ON pr.price_product_id = p.product_id
        WHERE p.product_published = 1
        AND p.product_id = :product_id
        AND $productAccessWhere
        ORDER BY pr.price_value ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute([':product_id' => $productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado o sin acceso', 'code' => 'PRODUCT_NOT_FOUND']);
        exit;
    }

    /* ================================ CONSTRUIR PRODUCTO ================================ */

    // CALCULAR hasGroup21 UNA SOLA VEZ
    $hasGroup21 = in_array(21, $userGroups);

    // BUSCAR PRIMER PRECIO VÁLIDO DE hikashop_price
    $validPriceFound = null;

    foreach ($rows as $row) {
        if ($validPriceFound === null && $row['price_value'] !== null && $row['price_value'] !== '') {
            $priceAccess = $row['price_access'] ?? '';

            // Validar acceso
            $hasAccess = ($priceAccess === 'all' || $priceAccess === '' || $priceAccess === null);

            if (!$hasAccess) {
                foreach ($userGroups as $groupId) {
                    if (strpos(',' . $priceAccess . ',', ',' . $groupId . ',') !== false) {
                        $hasAccess = true;
                        break;
                    }
                }
            }

            if ($hasAccess) {
                $validPriceFound = floatval($row['price_value']);
                break; // Salir del loop al encontrar el primer precio válido
            }
        }
    }

    // Si no se encontró precio válido, usar 0
    if ($validPriceFound === null) {
        $validPriceFound = 0;
    }

    // Construir el producto usando la primera fila
    $firstRow = $rows[0];

    $product = [
        'product_id' => $firstRow['product_id'],
        'product_parent_id' => $firstRow['product_parent_id'],
        'product_name' => $firstRow['product_name'],
        'product_code' => $firstRow['product_code'],
        'product_type' => $firstRow['product_type'],
        'product_alias' => $firstRow['product_alias'],
        'product_sort_price' => null,
        'product_price_real' => $validPriceFound,
        'product_price_real_muitowork' => floatval($firstRow['product_price_real_muitowork'] ?? 0),

        // Campos con mapeo de imágenes
        'product_puntera' => processMultipleValues($firstRow['product_puntera'], $wordToImageMap),
        'product_suela' => processMultipleValues($firstRow['product_suela'], $wordToImageMap),
        'product_color' => processMultipleValues($firstRow['product_color'], $wordToImageMap),
        'product_calzado' => processMultipleValues($firstRow['product_calzado'], $wordToImageMap),
        'product_antiperforante' => processMultipleValues($firstRow['product_antiperforante'], $wordToImageMap),
        'product_disipativo' => processMultipleValues($firstRow['product_disipativo'], $wordToImageMap),
        'product_metatarsal' => processMultipleValues($firstRow['product_metatarsal'], $wordToImageMap),
        'product_capellado' => processMultipleValues($firstRow['product_capellado'], $wordToImageMap),
        'product_cierre' => processMultipleValues($firstRow['product_cierre'], $wordToImageMap),
        'product_normativa' => processMultipleValues($firstRow['product_normativa'], $wordToImageMap),
        'product_segmento' => processMultipleValues($firstRow['product_segmento'], $wordToImageMap),
        'producrt_riesgo' => processMultipleValues($firstRow['producrt_riesgo'], $wordToImageMap),
        'product_componentes_reciclados' => processMultipleValues($firstRow['product_componentes_reciclados'], $wordToImageMap),
        'product_cubrepuntera' => processMultipleValues($firstRow['product_cubrepuntera'], $wordToImageMap),
        'product_plantilla' => processMultipleValues($firstRow['product_plantilla'], $wordToImageMap),
        'product_ncm' => $firstRow['product_ncm'],
        'files' => []
    ];

    // Agregar archivos (evitar duplicados)
    $addedFileIds = [];
    foreach ($rows as $row) {
        if ($row['file_id'] && !in_array($row['file_id'], $addedFileIds)) {
            $product['files'][] = [
                'file_id' => $row['file_id'],
                'file_name' => $row['file_name'],
                'file_type' => $row['file_type'],
                'file_path' => $row['file_path'],
                'file_url' => $row['file_url']
            ];
            $addedFileIds[] = $row['file_id'];
        }
    }

    /* ================================ LÓGICA DE product_sort_price ================================ */

    // PRIORIDAD 1: Grupo 21 → product_price_real_muitowork
    if ($hasGroup21 && !empty($firstRow['product_price_real_muitowork']) && floatval($firstRow['product_price_real_muitowork']) > 0) {
        $product['product_sort_price'] = floatval($firstRow['product_price_real_muitowork']);
    }
    // PRIORIDAD 2: Usar product_price_real (que ya tiene el precio válido de hikashop_price)
    elseif ($product['product_price_real'] > 0) {
        $product['product_sort_price'] = $product['product_price_real'];
    }
    // PRIORIDAD 3: Fallback al campo product_price_real de la tabla product
    else {
        $product['product_sort_price'] = floatval($firstRow['product_price_real'] ?? 0);
    }

    /* ================================ OBTENER VARIANTES ================================ */
    if ($product['product_type'] === 'main') {
        $variantsQuery = "
            SELECT 
                v.product_id AS variant_id,
                v.product_code AS variant_code,
                v.product_name AS variant_name,
                var.variant_characteristic_id,
                c.characteristic_value,
                c.characteristic_alias
            FROM {$prefix}hikashop_product v
            LEFT JOIN {$prefix}hikashop_variant var ON var.variant_product_id = v.product_id
            LEFT JOIN {$prefix}hikashop_characteristic c ON c.characteristic_id = var.variant_characteristic_id
            WHERE v.product_parent_id = :product_id
            AND v.product_published = 1
            ORDER BY v.product_id
        ";

        $variantsStmt = $db->prepare($variantsQuery);
        $variantsStmt->execute([':product_id' => $productId]);
        $variantRows = $variantsStmt->fetchAll(PDO::FETCH_ASSOC);

        $variants = [];
        foreach ($variantRows as $vRow) {
            $variantId = $vRow['variant_id'];

            if (!isset($variants[$variantId])) {
                $variants[$variantId] = [
                    'variant_id' => $vRow['variant_id'],
                    'variant_code' => $vRow['variant_code'],
                    'variant_name' => $vRow['variant_name'],
                    'characteristics' => []
                ];
            }

            if ($vRow['characteristic_value']) {
                $variants[$variantId]['characteristics'][] = [
                    'characteristic_id' => $vRow['variant_characteristic_id'],
                    'characteristic_value' => $vRow['characteristic_value'],
                    'characteristic_alias' => $vRow['characteristic_alias']
                ];
            }
        }

        $product['variants'] = array_values($variants);
    } else {
        $product['variants'] = [];
    }

    // Retornar resultado
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $product
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("❌ Error en detalleproduct.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en detalleproduct.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
