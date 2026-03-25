<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_api.log');
error_log("🚀 NEW STATEFUL API TRACKING INICIADA - " . date('Y-m-d H:i:s'));

// CORS + JSON
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
require __DIR__ . '/app/utils/permission.php';
require __DIR__ . '/app/utils/logger.php';
require __DIR__ . '/app/utils/action_mapper.php';

// ✅ DEFINIR RUTAS FISICAS Y WEB
if (!defined('JPATH_ROOT')) {
    define('JPATH_ROOT', dirname(__DIR__));  // /home/embarc7/public_html
}

// Ruta física donde se guardan los archivos
define('MWT_STORAGE_ROOT', '/home/embarc7/mwt.one');

// Ruta web que va en la BD
define('MWT_WEB_PATH', '/images/orders/');

error_log("📁 JPATH_ROOT: " . JPATH_ROOT);
error_log("📁 MWT_STORAGE_ROOT: " . MWT_STORAGE_ROOT);
error_log("🌐 MWT_WEB_PATH: " . MWT_WEB_PATH);

// Variables para logging
$logger = null;
$userId = null;
$handlerName = null;
$httpResponseCode = 200;
$responseData = null;

/* ================================ Validación Inicial ================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido. Usa POST.', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// Detectar si es multipart (con archivo) o JSON
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = strpos($contentType, 'multipart/form-data') !== false;

if ($isMultipart) {
    $payload = (object) $_POST;
} else {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw);
}

// Validar campos obligatorios básicos keyhash y keyuser
if (!isset($payload->keyhash, $payload->keyuser) || empty($payload->keyhash) || empty($payload->keyuser)) {
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
    $db = db_connect($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
    $prefix = $DB_PREFIX;

    // Validar que venga algún identificador reconocido
    $validNumbers = ['order_number', 'number_purchase', 'number_sap', 'number_preforma', 'number_preforma_mwt'];
    $foundNumber = null;
    $foundValue = null;
    foreach ($validNumbers as $numField) {
        if (isset($payload->$numField) && !empty($payload->$numField)) {
            $foundNumber = $numField;
            $foundValue = $payload->$numField;
            break;
        }
    }

    if ($foundNumber === null) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Faltan campos obligatorios de número',
            'required' => $validNumbers,
            'code' => 'MISSING_NUMBER_FIELD'
        ]);
        exit;
    }

    $orderNumber = null;

    if ($foundNumber === 'order_number') {
        $orderNumber = $foundValue;
    } else {
        // Consultar order_number en tabla apropiada según campo
        switch ($foundNumber) {
            case 'number_purchase':
                $stmt = $db->prepare("SELECT order_number FROM {$prefix}preforma WHERE number_purchase = :val LIMIT 1");
                break;
            case 'number_sap':
                $stmt = $db->prepare("SELECT order_number FROM {$prefix}sap WHERE number_sap = :val LIMIT 1");
                break;
            case 'number_preforma':
                $stmt = $db->prepare("SELECT order_number FROM {$prefix}sap WHERE number_preforma = :val LIMIT 1");
                break;
            case 'number_preforma_mwt':
                $stmt = $db->prepare("SELECT order_number FROM {$prefix}sap WHERE number_preforma_mwt = :val LIMIT 1");
                break;
            default:
                http_response_code(400);
                echo json_encode([
                    'error' => 'Campo número inválido',
                    'code' => 'INVALID_NUMBER_FIELD'
                ]);
                exit;
        }
        $stmt->execute([':val' => $foundValue]);
        $result = $stmt->fetch();
        if (!$result || empty($result['order_number'])) {
            http_response_code(404);
            echo json_encode([
                'error' => 'No se encontró orden para el número proporcionado',
                'code' => 'ORDER_NOT_FOUND'
            ]);
            exit;
        }
        $orderNumber = $result['order_number'];
    }

    // Normalizar order_number para el flujo
    $payload->order_number = $orderNumber;

    // Inicializar logger
    $logger = new APILogger($db, $prefix);

    /* ================================ Validación de Permisos ================================ */
    $permission = new PermissionValidator($db, $prefix);

    $hasAccess = $permission->validateUserOrderAccess(
        $payload->keyuser,
        $payload->order_number
    );

    if (!$hasAccess['allowed']) {
        $httpResponseCode = 403;
        http_response_code(403);
        $responseData = [
            'error' => 'No tienes permiso para consultar este pedido',
            'code' => 'UNAUTHORIZED_ORDER_ACCESS',
            'details' => $hasAccess['reason'] ?? null
        ];
        echo json_encode($responseData);
        exit;
    }

    // Obtener ID del usuario desde la tabla users
    $userStmt = $db->prepare("SELECT id FROM josmwt_users WHERE keyuser = :keyuser LIMIT 1");
    $userStmt->execute([':keyuser' => $payload->keyuser]);
    $userRow = $userStmt->fetch();
    $userId = $userRow['id'] ?? 0;

    // Obtener acción (por defecto 'process')
    $action = $payload->action ?? 'process';

    // ✅ VALIDAR PERMISOS DE FUNCIÓN
    if ($action !== 'process') {
        $hasFunctionPermission = $permission->hasPermissionForFunction($userId, $action);

        if (!$hasFunctionPermission) {
            $httpResponseCode = 403;
            http_response_code(403);
            $responseData = [
                'error' => 'No tienes permisos para ejecutar esta función',
                'code' => 'FUNCTION_PERMISSION_DENIED',
                'function' => $action
            ];
            echo json_encode($responseData);

            if ($logger && $userId) {
                $logger->logRequest($userId, 'PERMISSION_DENIED', $action, $payload, 403, $payload->order_number);
            }
            exit;
        }

        error_log("✅ Permiso validado para función: {$action}");
    }

    // Obtener información de la orden
    $orderData = $permission->getOrderData($payload->order_number);

    if (!$orderData) {
        $httpResponseCode = 404;
        http_response_code(404);
        $responseData = ['error' => 'Orden no encontrada', 'code' => 'ORDER_NOT_FOUND'];
        echo json_encode($responseData);

        if ($logger && $userId) {
            $logger->logRequest($userId, 'ORDER_NOT_FOUND', 'validate', $payload, 404, $payload->order_number);
        }
        exit;
    }

    // Obtener estado
    $orderStatus = $orderData['order_status'];

    error_log("✅ Acceso validado - Usuario: {$payload->keyuser}, Orden: {$payload->order_number}, Estado: {$orderStatus}, Acción: {$action}");

    /* ================================ Enrutador Dinámico de Acciones ================================ */
    $mapper = new ActionMapper($db, $prefix);

    // Determinar qué handler debe manejar esta acción
    $handlerInfo = $mapper->getHandlerForAction($action, $orderStatus);

    if (!$handlerInfo['allowed']) {
        $httpResponseCode = 403;
        http_response_code(403);
        $responseData = [
            'error' => 'Acción no permitida en el estado actual',
            'code' => 'ACTION_NOT_ALLOWED_IN_STATE',
            'details' => $handlerInfo['reason'],
            'current_state' => $orderStatus,
            'required_state' => $handlerInfo['state']
        ];
        echo json_encode($responseData);

        if ($logger && $userId) {
            $logger->logRequest($userId, 'STATE_VIOLATION', $action, $payload, 403, $payload->order_number);
        }
        exit;
    }

    try {
        $handler = $mapper->loadHandler($handlerInfo['state']);
        $handlerName = 'Handler' . ucfirst(str_replace('_', '', $handlerInfo['state']));

        error_log("✅ Ejecutando acción '{$action}' con handler '{$handlerName}' (orden en estado '{$orderStatus}')");
    } catch (Exception $e) {
        $httpResponseCode = 500;
        http_response_code(500);
        $responseData = [
            'error' => 'Error cargando handler',
            'code' => 'HANDLER_LOAD_ERROR'
        ];
        echo json_encode($responseData);
        error_log("❌ Error cargando handler: " . $e->getMessage());

        if ($logger && $userId) {
            $logger->logRequest($userId, 'HANDLER_ERROR', $action, $payload, 500, $payload->order_number);
        }
        exit;
    }

    /* ================================ Ejecutor de Acciones ================================ */
    switch ($action) {
        case 'createPreforma':
            $filePath = null;
            $miniaturePath = null;

            if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadAndSaveFile($_FILES['document']['tmp_name']);

                if (!$uploadResult['success']) {
                    $httpResponseCode = 400;
                    http_response_code(400);
                    $responseData = [
                        'success' => false,
                        'error' => $uploadResult['message'],
                        'code' => 'FILE_UPLOAD_ERROR'
                    ];
                    echo json_encode($responseData);
                    break;
                }

                $filePath = $uploadResult['pdf_path'];
                $miniaturePath = $uploadResult['thumbnail_path'];
            }

            $preformaResult = $handler->createOrUpdatePreforma(
                $payload->order_number,
                $payload->purchase_compra ?? null,
                isset($payload->id_cliente) ? (int)$payload->id_cliente : null,
                isset($payload->operador) ? (int)$payload->operador : null,
                $filePath,
                $miniaturePath
            );

            $httpResponseCode = $preformaResult['success'] ? 200 : 400;
            $responseData = $preformaResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'changeStatus':
            $statusResult = $handler->changeStatus($payload->order_number);
            $httpResponseCode = $statusResult['success'] ? 200 : 400;
            $responseData = $statusResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deletePreforma':
            $deleteResult = $handler->deletePreformaRecord($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deleteFiles':
            $deleteFilesResult = $handler->deleteFilesOnly($payload->order_number);
            $httpResponseCode = $deleteFilesResult['success'] ? 200 : 400;
            $responseData = $deleteFilesResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'getPreforma':
            $preforma = $handler->getPreforma($payload->order_number);

            if ($preforma === null) {
                $httpResponseCode = 404;
                $responseData = [
                    'success' => false,
                    'message' => 'Preforma no encontrada',
                    'code' => 'PREFORMA_NOT_FOUND'
                ];
            } else {
                $httpResponseCode = 200;
                $responseData = [
                    'success' => true,
                    'data' => $preforma
                ];
            }

            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'updateCustomerCredit':
            $creditResult = $handler->updateCustomerCredit($payload->order_number);
            $httpResponseCode = $creditResult['success'] ? 200 : 400;
            $responseData = $creditResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'getCreditOrderData':
            $creditResult = $handler->getCreditOrderData($payload->order_number, $userId);
            $httpResponseCode = $creditResult['success'] ? 200 : 400;
            $responseData = $creditResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'createSAP':
            $preformaPath = null;
            $preformaMwtPath = null;
            $miniaturaPath = null;
            $miniaturaMwtPath = null;

            if (isset($_FILES['preforma']) && $_FILES['preforma']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadAndSaveFile($_FILES['preforma']['tmp_name'], 'preforma');
                if ($uploadResult['success']) {
                    $preformaPath = $uploadResult['pdf_path'];
                    $miniaturaPath = $uploadResult['thumbnail_path'];
                }
            }

            if (isset($_FILES['preforma_mwt']) && $_FILES['preforma_mwt']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadAndSaveFile($_FILES['preforma_mwt']['tmp_name'], 'preforma_mwt');
                if ($uploadResult['success']) {
                    $preformaMwtPath = $uploadResult['pdf_path'];
                    $miniaturaMwtPath = $uploadResult['thumbnail_path'];
                }
            }

            $sapResult = $handler->createSAP(
                $payload->order_number,
                $payload->numero_sap ?? null,
                $payload->numero_proforma ?? null,
                $payload->numero_proforma_mwt ?? null,
                isset($payload->product_id) ? (int)$payload->product_id : null,
                $payload->fecha_inicio ?? null,
                $payload->fecha_final ?? null,
                $preformaPath,
                $preformaMwtPath,
                $miniaturaPath,
                $miniaturaMwtPath
            );
            $httpResponseCode = $sapResult['success'] ? 200 : 400;
            $responseData = $sapResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deletePreformaFiles':
            $deleteResult = $handler->deletePreformaFiles($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deletePreformaMwtFiles':
            $deleteResult = $handler->deletePreformaMwtFiles($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deleteComplete':
            $deleteResult = $handler->deleteComplete($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'changeStatusToPreparacion':
            $statusResult = $handler->changeStatusToPreparacion($payload->order_number);
            $httpResponseCode = $statusResult['success'] ? 200 : 400;
            $responseData = $statusResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'listProduccionInfo':
            $produccionInfo = $handler->listProduccionInfo($payload->order_number);
            $httpResponseCode = $produccionInfo['http_code'] ?? 200;
            http_response_code($httpResponseCode);
            echo json_encode($produccionInfo);
            break;

        case 'crearPreparacion':
            $packPath = null;
            $packMiniatura = null;
            $cotizacionPath = null;
            $cotizacionMiniatura = null;

            if (isset($_FILES['pack']) && $_FILES['pack']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadPackFile($_FILES['pack']['tmp_name']);
                if ($uploadResult['success']) {
                    $packPath = $uploadResult['pdf_path'];
                    $packMiniatura = $uploadResult['thumbnail_path'];
                }
            }

            if (isset($_FILES['cotizacion']) && $_FILES['cotizacion']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadCotizacionFile($_FILES['cotizacion']['tmp_name']);
                if ($uploadResult['success']) {
                    $cotizacionPath = $uploadResult['pdf_path'];
                    $cotizacionMiniatura = $uploadResult['thumbnail_path'];
                }
            }

            $preparacionResult = $handler->crearPreparacion(
                $payload->order_number,
                isset($payload->order_shipping_method) ? (int)$payload->order_shipping_method : null,
                isset($payload->order_shipping_price) ? (float)$payload->order_shipping_price : null,
                isset($payload->operator) ? (int)$payload->operator : null,
                isset($payload->addres_id) ? (int)$payload->addres_id : null,  // ✅ Ahora en posición 5
                $payload->incoterms ?? null,                                    // ✅ Ahora en posición 6
                $payload->code_incoterms ?? null,                               // ✅ Ahora en posición 7
                $packPath,                                                      // ✅ Posición 8
                $packMiniatura,                                                 // ✅ Posición 9
                $cotizacionPath,                                                // ✅ Posición 10
                $cotizacionMiniatura,                                           // ✅ Posición 11
                isset($payload->manejo_pago) ? (int)$payload->manejo_pago : null  // ✅ Ahora en posición 12
            );

            $httpResponseCode = $preparacionResult['success'] ? 200 : 400;
            $responseData = $preparacionResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;


        case 'listPreparacionInfo':
            $preparacionInfo = $handler->listPreparacionInfo($payload->order_number);
            $httpResponseCode = $preparacionInfo['http_code'] ?? 200;
            http_response_code($httpResponseCode);
            echo json_encode($preparacionInfo);
            break;

        case 'deletePackDetallado':
            $deleteResult = $handler->deletePackDetallado($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deleteCotizacion':
            $deleteResult = $handler->deleteCotizacion($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'changeStatusToDespacho':
            $statusResult = $handler->changeStatusToDespacho($payload->order_number);
            $httpResponseCode = $statusResult['success'] ? 200 : 400;
            $responseData = $statusResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'crearDespacho':
            $guiaPath = null;
            $guiaMiniatura = null;
            $invoicePath = null;
            $invoiceMiniatura = null;
            $certificadoPath = null;
            $certificadoMiniatura = null;
            $invoiceMwtPath = null;
            $invoiceMwtMiniatura = null;

            if (isset($_FILES['guia']) && $_FILES['guia']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadGuiaFile($_FILES['guia']['tmp_name']);
                if ($uploadResult['success']) {
                    $guiaPath = $uploadResult['pdf_path'];
                    $guiaMiniatura = $uploadResult['thumbnail_path'];
                }
            }

            if (isset($_FILES['invoice']) && $_FILES['invoice']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadInvoiceFile($_FILES['invoice']['tmp_name']);
                if ($uploadResult['success']) {
                    $invoicePath = $uploadResult['pdf_path'];
                    $invoiceMiniatura = $uploadResult['thumbnail_path'];
                }
            }

            if (isset($_FILES['certificado']) && $_FILES['certificado']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadCertificadoFile($_FILES['certificado']['tmp_name']);
                if ($uploadResult['success']) {
                    $certificadoPath = $uploadResult['pdf_path'];
                    $certificadoMiniatura = $uploadResult['thumbnail_path'];
                }
            }

            if (isset($_FILES['invoice_mwt']) && $_FILES['invoice_mwt']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadInvoiceFile($_FILES['invoice_mwt']['tmp_name']);
                if ($uploadResult['success']) {
                    $invoiceMwtPath = $uploadResult['pdf_path'];
                    $invoiceMwtMiniatura = $uploadResult['thumbnail_path'];
                }
            }

            $despachoResult = $handler->crearDespacho(
                $payload->order_number,
                $payload->nomber ?? null,
                $payload->number_guia ?? null,
                $payload->nomber_despacho ?? null,
                $payload->nomber_arribo ?? null,
                $guiaPath,
                $guiaMiniatura,
                $payload->adiccional ?? null,
                $payload->fechas ?? null,
                $payload->link ?? null,
                $payload->number_invoice ?? null,
                $invoicePath,
                $invoiceMiniatura,
                $certificadoPath,
                $certificadoMiniatura,
                $payload->number_invoice_mwt ?? null,
                $invoiceMwtPath,
                $invoiceMwtMiniatura
            );
            $httpResponseCode = $despachoResult['success'] ? 200 : 400;
            $responseData = $despachoResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'listDespachoInfo':
            $despachoInfo = $handler->listDespachoInfo($payload->order_number);
            $httpResponseCode = $despachoInfo['http_code'] ?? 200;
            http_response_code($httpResponseCode);
            echo json_encode($despachoInfo);
            break;

        case 'deleteInvoice':
            $deleteResult = $handler->deleteInvoice($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deleteInvoiceMwt':
            $deleteResult = $handler->deleteInvoiceMwt($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deleteCertificado':
            $deleteResult = $handler->deleteCertificado($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deleteGuia':
            $deleteResult = $handler->deleteGuia($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'deleteCompleteDespacho':
            $deleteResult = $handler->deleteComplete($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'changeStatusToTransito':
            $statusResult = $handler->changeStatusToTransito($payload->order_number);
            $httpResponseCode = $statusResult['success'] ? 200 : 400;
            $responseData = $statusResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'crearTransito':
            $packPath = null;
            $packMiniatura = null;

            if (isset($_FILES['pack']) && $_FILES['pack']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadPackFile($_FILES['pack']['tmp_name']);
                if ($uploadResult['success']) {
                    $packPath = $uploadResult['pdf_path'];
                    $packMiniatura = $uploadResult['thumbnail_path'];
                }
            }

            $transitoResult = $handler->crearTransito(
                $payload->order_number,
                $payload->fecha_arribo ?? null,
                $payload->puerto_intermedio ?? null,
                $packPath,
                $packMiniatura
            );
            $httpResponseCode = $transitoResult['success'] ? 200 : 400;
            $responseData = $transitoResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'listTransitoInfo':
            $transitoInfo = $handler->listTransitoInfo($payload->order_number);
            $httpResponseCode = $transitoInfo['http_code'] ?? 200;
            http_response_code($httpResponseCode);
            echo json_encode($transitoInfo);
            break;

        case 'deletePack':
            $deleteResult = $handler->deletePack($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'changeStatusToPagado':
            $statusResult = $handler->changeStatusToPagado($payload->order_number);
            $httpResponseCode = $statusResult['success'] ? 200 : 400;
            $responseData = $statusResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'createPago':
            $comprobantePath = null;
            $miniaturaPath = null;

            if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $handler->uploadComprobanteFile($_FILES['comprobante']['tmp_name']);
                if ($uploadResult['success']) {
                    $comprobantePath = $uploadResult['pdf_path'];
                    $miniaturaPath = $uploadResult['thumbnail_path'];
                }
            }

            $createPagoResult = $handler->createPago(
                $payload->order_number,
                $payload->tipo_pago ?? null,
                isset($payload->cantidad_pago) ? floatval($payload->cantidad_pago) : null,
                isset($payload->metodo_pago) ? intval($payload->metodo_pago) : null,
                $comprobantePath,
                $miniaturaPath,
                $payload->adiccional ?? null,
                $payload->fecha_pago ?? null,
                $payload->nombre ?? null
            );
            $httpResponseCode = $createPagoResult['success'] ? 200 : 400;
            $responseData = $createPagoResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'listPagoInfo':
            $pagosInfo = $handler->listPagoInfo($payload->order_number);
            $httpResponseCode = $pagosInfo['http_code'] ?? 200;
            http_response_code($httpResponseCode);
            echo json_encode($pagosInfo);
            break;

        case 'deleteComprobante':
            $pago_id = isset($_POST['pago_id']) ? intval($_POST['pago_id']) : null;
            $order_number = isset($_POST['order_number']) ? $_POST['order_number'] : '';

            if (!$pago_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID de pago requerido']);
                break;
            }

            $deleteResult = $handler->deleteComprobante($pago_id);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            http_response_code($httpResponseCode);
            echo json_encode($deleteResult);
            break;


        case 'deletePago':
            $deleteResult = $handler->deletePago($payload->order_number);
            $httpResponseCode = $deleteResult['success'] ? 200 : 400;
            $responseData = $deleteResult;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'changeStatusToArchivada':
            $result = $handler->changeStatusToArchivada($payload->order_number);
            $httpResponseCode = $result['success'] ? 200 : 400;
            $responseData = $result;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;

        case 'process':
        default:
            $responseData = $handler->process($orderData, $payload);
            $httpResponseCode = $responseData['http_code'] ?? 200;
            http_response_code($httpResponseCode);
            echo json_encode($responseData);
            break;
    }

    // ✅ Registrar en log después de responder
    if ($logger && $userId && $handlerName) {
        $logger->logRequest(
            $userId,
            $handlerName,
            $action,
            $payload,
            $httpResponseCode,
            $payload->order_number
        );
    }
} catch (Exception $e) {
    $httpResponseCode = 500;
    error_log("❌ Error en API: " . $e->getMessage());
    http_response_code(500);
    $responseData = [
        'error' => 'Error interno del servidor',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ];
    echo json_encode($responseData);

    if ($logger && $userId) {
        $logger->logRequest(
            $userId,
            $handlerName ?? 'ERROR',
            $action ?? 'unknown',
            $payload ?? (object)[],
            500,
            $payload->order_number ?? null
        );
    }
}
