<?php

/**
 * detalleproduct.php
 * Endpoint para obtener el detalle de un producto específico
 * con control de acceso y mapeo de imágenes desde plugin wordtoimage
 * Incluye múltiples precios con validación de price_access y títulos de grupos
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

/**
 * Retorna TODOS los grupos del price_access del registro (sin validar usuario)
 */
/**
 * Retorna grupos del price_access con TODOS los títulos disponibles
 */
function getPriceGroups($priceAccess, $allGroupTitles)
{
    $priceAccessGroups = array_map('intval', explode(',', trim($priceAccess, ',')));
    $groups = [];

    foreach ($priceAccessGroups as $groupId) {
        if ($groupId > 0) {
            $groups[] = [
                'id' => $groupId,
                'title' => $allGroupTitles[$groupId] ?? 'Unknown'
            ];
        }
    }

    return [
        'all' => ($priceAccess === 'all'),
        'groups' => $groups,
        'raw_access' => $priceAccess
    ];
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
    $productId = $payload->product_id;

    // Obtener grupos del usuario
    $groupStmt = $db->prepare("SELECT group_id FROM {$prefix}user_usergroup_map WHERE user_id = :user_id");
    $groupStmt->execute([':user_id' => $userId]);
    $userGroups = $groupStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($userGroups)) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuario sin grupos de acceso', 'code' => 'NO_ACCESS_GROUPS']);
        exit;
    }

    // Obtener títulos de grupos del usuario
    $groupsQuery = "SELECT id, title FROM {$prefix}usergroups ORDER BY id";
    $allGroupsStmt = $db->query($groupsQuery);
    $allGroupTitles = $allGroupsStmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => title

    /* ================================ CONSULTAR PRODUCTO ================================ */
    $productAccessConditions = ["p.product_access = 'all'"];
    foreach ($userGroups as $groupId) {
        $productAccessConditions[] = "CONCAT(',', p.product_access, ',') LIKE '%,{$groupId},%'";
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
            CONCAT('https://mwt.one/images/com_hikashop/upload/', f.file_path) AS file_url
        FROM {$prefix}hikashop_product p
        LEFT JOIN {$prefix}hikashop_file f ON f.file_ref_id = p.product_id
        WHERE p.product_published = 1
        AND p.product_id = :product_id
        AND $productAccessWhere
    ";

    $stmt = $db->prepare($query);
    $stmt->execute([':product_id' => $productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado o sin acceso', 'code' => 'PRODUCT_NOT_FOUND']);
        exit;
    }

    /* ================================ OBTENER PRECIOS DEL PRODUCTO ================================ */
    $pricesQuery = "
        SELECT price_id, price_value, price_access, price_currency_id
        FROM {$prefix}hikashop_price 
        WHERE price_product_id = :product_id
    ";
    $pricesStmt = $db->prepare($pricesQuery);
    $pricesStmt->execute([':product_id' => $productId]);
    $allPrices = $pricesStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================================ CONSTRUIR PRODUCTO ================================ */
    $product = null;

    foreach ($rows as $row) {
        if ($product === null) {
            $product = [
                'product_id' => $row['product_id'],
                'product_parent_id' => $row['product_parent_id'],
                'product_name' => $row['product_name'],
                'product_code' => $row['product_code'],
                'product_type' => $row['product_type'],
                'product_alias' => $row['product_alias'],
                'product_price_real' => floatval($row['product_price_real'] ?? 0),
                'product_price_real_muitowork' => floatval($row['product_price_real_muitowork'] ?? 0),

                // Campos con mapeo de imágenes
                'product_puntera' => processMultipleValues($row['product_puntera'], $wordToImageMap),
                'product_suela' => processMultipleValues($row['product_suela'], $wordToImageMap),
                'product_color' => processMultipleValues($row['product_color'], $wordToImageMap),
                'product_calzado' => processMultipleValues($row['product_calzado'], $wordToImageMap),
                'product_antiperforante' => processMultipleValues($row['product_antiperforante'], $wordToImageMap),
                'product_disipativo' => processMultipleValues($row['product_disipativo'], $wordToImageMap),
                'product_metatarsal' => processMultipleValues($row['product_metatarsal'], $wordToImageMap),
                'product_capellado' => processMultipleValues($row['product_capellado'], $wordToImageMap),
                'product_cierre' => processMultipleValues($row['product_cierre'], $wordToImageMap),
                'product_normativa' => processMultipleValues($row['product_normativa'], $wordToImageMap),
                'product_segmento' => processMultipleValues($row['product_segmento'], $wordToImageMap),
                'product_riesgo' => processMultipleValues($row['product_riesgo'], $wordToImageMap),
                'product_componentes_reciclados' => processMultipleValues($row['product_componentes_reciclados'], $wordToImageMap),
                'product_cubrepuntera' => processMultipleValues($row['product_cubrepuntera'], $wordToImageMap),
                'product_plantilla' => processMultipleValues($row['product_plantilla'], $wordToImageMap),
                'product_ncm' => $row['product_ncm'],

                'prices' => [],
                'files' => []
            ];
        }

        // Agregar archivos
        if ($row['file_id']) {
            $fileExists = false;
            foreach ($product['files'] as $existingFile) {
                if ($existingFile['file_id'] === $row['file_id']) {
                    $fileExists = true;
                    break;
                }
            }

            if (!$fileExists) {
                $product['files'][] = [
                    'file_id' => $row['file_id'],
                    'file_name' => $row['file_name'],
                    'file_type' => $row['file_type'],
                    'file_path' => $row['file_path'],
                    'file_url' => $row['file_url']
                ];
            }
        }
    }

    foreach ($allPrices as $priceRow) {
        $product['prices'][] = [
            'price_id' => $priceRow['price_id'],
            'value' => floatval($priceRow['price_value']),
            'currency_id' => $priceRow['price_currency_id'],
            'groups' => getPriceGroups($priceRow['price_access'], $allGroupTitles)
        ];
    }

    /* ================================ OBTENER VARIANTES ================================ */
    if ($product['product_type'] === 'main') {
        $variantsQuery = "
            SELECT 
                v.product_id AS variant_id,
                v.product_code AS variant_code,
                v.product_name AS variant_name,
                v.product_price_real AS variant_price_real,
                v.product_price_real_muitowork AS variant_price_real_muitowork,
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
                    'product_price_real' => floatval($vRow['variant_price_real'] ?? 0),
                    'product_price_real_muitowork' => floatval($vRow['variant_price_real_muitowork'] ?? 0),
                    'prices' => [],
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

        // Obtener precios para cada variante
        foreach ($variants as $variantId => &$variant) {
            $varPricesQuery = "
                SELECT price_id, price_value, price_access, price_currency_id
                FROM {$prefix}hikashop_price 
                WHERE price_product_id = :variant_id
            ";
            $varPricesStmt = $db->prepare($varPricesQuery);
            $varPricesStmt->execute([':variant_id' => $variantId]);
            $varAllPrices = $varPricesStmt->fetchAll(PDO::FETCH_ASSOC);

            // Obtener precios para cada variante (TODOS los precios)
            foreach ($varAllPrices as $varPriceRow) {
                $variant['prices'][] = [
                    'price_id' => $varPriceRow['price_id'],
                    'value' => floatval($varPriceRow['price_value']),
                    'currency_id' => $varPriceRow['price_currency_id'],
                    'groups' => getPriceGroups($priceRow['price_access'], $allGroupTitles)
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
    ]);
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
