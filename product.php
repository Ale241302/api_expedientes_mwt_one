<?php

/**
 * product.php - CON MAPEO DE IMÁGENES desde plugin wordtoimage
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

    // Obtener grupos del usuario
    $groupStmt = $db->prepare("SELECT group_id FROM {$prefix}user_usergroup_map WHERE user_id = :user_id");
    $groupStmt->execute([':user_id' => $userId]);
    $userGroups = $groupStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($userGroups)) {
        http_response_code(200);
        echo json_encode(['success' => true, 'count' => 0, 'data' => []]);
        exit;
    }

    // CALCULAR hasGroup21 UNA SOLA VEZ antes del loop
    $hasGroup21 = in_array(21, $userGroups);

    /* ================================ CONSULTAR PRODUCTOS ================================ */
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
            p.product_hit,
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
        AND p.product_type = 'main'
        AND $productAccessWhere
        ORDER BY p.product_hit DESC, p.product_id DESC, pr.price_value ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================================ PROCESAR PRODUCTOS ================================ */
    $products = [];
    $productPrices = []; // Cache de precios válidos por producto

    foreach ($rows as $row) {
        $productId = $row['product_id'];

        // Buscar precio válido para este producto (solo una vez por producto)
        if (!isset($productPrices[$productId])) {
            $productPrices[$productId] = 0; // Default

            // Buscar en todas las filas de este producto
            foreach ($rows as $priceRow) {
                if (
                    $priceRow['product_id'] == $productId &&
                    $priceRow['price_value'] !== null &&
                    $priceRow['price_value'] !== ''
                ) {

                    $priceAccess = $priceRow['price_access'] ?? '';
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
                        $productPrices[$productId] = floatval($priceRow['price_value']);
                        break; // Tomar el primero válido
                    }
                }
            }
        }

        if (!isset($products[$productId])) {
            $products[$productId] = [
                'product_id' => $row['product_id'],
                'product_parent_id' => $row['product_parent_id'],
                'product_name' => $row['product_name'],
                'product_code' => $row['product_code'],
                'product_type' => $row['product_type'],
                'product_alias' => $row['product_alias'],
                'product_sort_price' => null,
                'product_price_real' => $productPrices[$productId], // Usar precio válido cacheado
                'product_price_real_muitowork' => floatval($row['product_price_real_muitowork'] ?? 0),

                // Campos con imágenes mapeadas
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
                'producrt_riesgo' => processMultipleValues($row['producrt_riesgo'], $wordToImageMap),
                'product_componentes_reciclados' => processMultipleValues($row['product_componentes_reciclados'], $wordToImageMap),
                'product_cubrepuntera' => processMultipleValues($row['product_cubrepuntera'], $wordToImageMap),
                'product_plantilla' => processMultipleValues($row['product_plantilla'], $wordToImageMap),
                'product_ncm' => $row['product_ncm'],
                'files' => []
            ];

            /* ================================ CALCULAR product_sort_price ================================ */
            // PRIORIDAD 1: Grupo 21 → product_price_real_muitowork
            if ($hasGroup21 && !empty($row['product_price_real_muitowork']) && floatval($row['product_price_real_muitowork']) > 0) {
                $products[$productId]['product_sort_price'] = floatval($row['product_price_real_muitowork']);
            }
            // PRIORIDAD 2: Usar product_price_real (que ya tiene el precio válido)
            elseif ($products[$productId]['product_price_real'] > 0) {
                $products[$productId]['product_sort_price'] = $products[$productId]['product_price_real'];
            }
            // PRIORIDAD 3: Fallback al campo de la tabla product
            else {
                $products[$productId]['product_sort_price'] = floatval($row['product_price_real'] ?? 0);
            }
        }

        // Agregar archivos (evitar duplicados)
        if ($row['file_id']) {
            $fileExists = false;
            foreach ($products[$productId]['files'] as $existingFile) {
                if ($existingFile['file_id'] === $row['file_id']) {
                    $fileExists = true;
                    break;
                }
            }

            if (!$fileExists) {
                $fileData = [
                    'file_id' => $row['file_id'],
                    'file_name' => $row['file_name'],
                    'file_type' => $row['file_type'],
                    'file_path' => $row['file_path'],
                    'file_url' => $row['file_url']
                ];

                // Si es PDF, lo guardamos aparte para agregar al final
                if (pathinfo($row['file_path'], PATHINFO_EXTENSION) === 'pdf') {
                    $products[$productId]['_temp_pdf'] = $fileData;
                } else {
                    $products[$productId]['files'][] = $fileData;
                }
            }
        }
    }

    // Retornar resultados
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count' => count($products),
        'data' => array_values($products)
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("❌ Error en product.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'DATABASE_ERROR',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ Error en product.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
