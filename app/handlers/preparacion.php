<?php
require_once __DIR__ . '/handler-base.php';

class HandlerPreparacion extends HandlerBase
{
    /**
     * Procesa una orden en estado "preparacion"
     */
    public function process(array $orderData, object $payload): array
    {
        try {
            $orderNumber = $orderData['order_number'];

            // Listar información actualizada
            return $this->listPreparacionInfo($orderNumber);
        } catch (Exception $e) {
            error_log("Error en preparacion handler: " . $e->getMessage());
            return [
                'http_code' => 500,
                'success' => false,
                'error' => 'Error procesando orden en preparación'
            ];
        }
    }

    /**
     * Crea o actualiza información de preparación
     */
    public function crearPreparacion(
        string $orderNumber,
        ?int $orderShippingMethodCode = null,
        ?float $orderShippingPrice = null,
        ?int $operatorCode = null,
        ?int $addres_id = null,
        ?string $incoterms = null,
        ?string $codeIncoterms = null,
        ?string $packFile = null,
        ?string $packMiniatura = null,
        ?string $cotizacionFile = null,
        ?string $cotizacionMiniatura = null,
        ?int $manejo_pago = null
    ): array {
        try {
            $this->db->beginTransaction();

            // Mapear códigos a valores reales
            $orderShippingMethod = $this->mapShippingMethod($orderShippingMethodCode);
            $operator = $this->mapOperator($operatorCode);

            // 1. Actualizar hikashop_order
            $this->updateHikashopOrderPreparacion(
                $orderNumber,
                $orderShippingMethod,
                $orderShippingPrice,
                $operator,
                $addres_id,
                $incoterms,
                $codeIncoterms,
                $manejo_pago
            );

            // 2. Crear/actualizar pack_detallado
            if ($packFile !== null) {
                $this->upsertPackDetallado($orderNumber, $packFile, $packMiniatura);
            }

            // 3. Crear/actualizar cotizacion
            if ($cotizacionFile !== null) {
                $this->upsertCotizacion($orderNumber, $cotizacionFile, $cotizacionMiniatura);
            }

            $this->db->commit();

            error_log("✅ Preparación actualizada para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE PREPARACIÓN
            $this->sendPreparacionEmail(
                $orderNumber,
                $orderShippingMethod,
                $orderShippingPrice,
                $operator,
                $incoterms,
                $codeIncoterms,
                $manejo_pago
            );

            return [
                'success' => true,
                'message' => 'Información de preparación actualizada'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("❌ Error en crearPreparacion: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear preparación: ' . $e->getMessage()
            ];
        }
    }


    /**
     * Mapea código de método de envío a texto
     */
    private function mapShippingMethod(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $map = [
            1 => 'Aereo',
            2 => 'Maritimo'
        ];

        return $map[$code] ?? null;
    }

    /**
     * Mapea código de operador a texto
     */
    private function mapOperator(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $map = [
            1 => 'Fabrica',
            2 => 'Cliente'
        ];

        return $map[$code] ?? null;
    }
    /**
     * Método auxiliar para enviar email de preparación
     */
    private function sendPreparacionEmail(
        string $orderNumber,
        ?string $orderShippingMethod,
        ?float $orderShippingPrice,
        ?string $operator,
        ?string $incoterms,
        ?string $codeIncoterms,
        ?int $manejo_pago = null
    ): void {
        try {
            require_once __DIR__ . '/../utils/emailer.php';
            $emailer = new EmailSender();

            // Obtener datos del cliente y purchase number
            $customerData = $emailer->getCustomerDataForEmail($this->db, $this->prefix, $orderNumber);

            if ($customerData && !empty($customerData['customer_email'])) {
                // Usar number_purchase si existe
                $purchaseNumber = $customerData['number_purchase'] ?? $orderNumber;

                // Determinar qué incoterm usar (preferir incoterms completo sobre código)
                $incotermsValue = !empty($incoterms) ? $incoterms : ($codeIncoterms ?? 'N/A');
                // Convertir manejo_pago a texto legible
                $manejoPagoText = 'N/A';
                if ($manejo_pago !== null) {
                    $manejoPagoText = $manejo_pago === 1 ? 'Contraentrega' : 'Pre-pagado';
                }
                $emailSent = $emailer->sendPreparacionAlert(
                    $customerData['customer_email'],
                    $purchaseNumber,
                    $orderShippingMethod ?? 'N/A',
                    $operator ?? 'N/A',
                    $orderShippingPrice !== null ? number_format($orderShippingPrice, 2) : 'N/A',
                    $incotermsValue,
                    $manejoPagoText
                );

                if ($emailSent) {
                    error_log("✅ Email de preparación enviado a: {$customerData['customer_email']}");
                } else {
                    error_log("⚠️ No se pudo enviar el email de preparación");
                }
            } else {
                error_log("⚠️ No se pudo enviar email: datos de cliente no encontrados para orden {$orderNumber}");
            }
        } catch (Exception $e) {
            error_log("❌ Error enviando email de preparación: " . $e->getMessage());
        }
    }



    /**
     * Actualiza campos de hikashop_order relacionados con preparación
     */
    private function updateHikashopOrderPreparacion(
        string $orderNumber,
        ?string $orderShippingMethod,
        ?float $orderShippingPrice,
        ?string $operator,
        ?string $addres_id,
        ?string $incoterms,
        ?string $codeIncoterms,
        ?int $manejo_pago = null
    ): void {
        $updateFields = [];
        $params = [':order_number' => $orderNumber];

        if ($orderShippingMethod !== null) {
            $updateFields[] = 'order_shipping_method = :order_shipping_method';
            $params[':order_shipping_method'] = $orderShippingMethod;
        }
        if ($orderShippingPrice !== null) {
            $updateFields[] = 'order_shipping_price = :order_shipping_price';
            $params[':order_shipping_price'] = $orderShippingPrice;
        }
        if ($manejo_pago !== null) {
            $updateFields[] = 'manejo_pago = :manejo_pago';
            $params[':manejo_pago'] = $manejo_pago;
        }
        if ($operator !== null) {
            $updateFields[] = 'operator = :operator';
            $params[':operator'] = $operator;
        }
        if ($addres_id !== null) {
            $updateFields[] = 'order_shipping_address_id = :order_shipping_address_id';
            $params[':order_shipping_address_id'] = $addres_id;
        }
        if ($incoterms !== null) {
            $updateFields[] = 'Incoterms = :incoterms';
            $params[':incoterms'] = $incoterms;
        }
        if ($codeIncoterms !== null) {
            $updateFields[] = 'Code_incoterms = :code_incoterms';
            $params[':code_incoterms'] = $codeIncoterms;
        }

        if (!empty($updateFields)) {
            $updateQuery = "UPDATE {$this->prefix}hikashop_order SET " .
                implode(', ', $updateFields) .
                " WHERE order_number = :order_number";
            $updateStmt = $this->db->prepare($updateQuery);
            $updateStmt->execute($params);
        }
    }

    /**
     * Crea o actualiza pack_detallado
     */
    private function upsertPackDetallado(string $orderNumber, string $packFile, ?string $miniatura): void
    {
        $checkStmt = $this->db->prepare(
            "SELECT id FROM {$this->prefix}pack_detallado WHERE order_number = :order_number LIMIT 1"
        );
        $checkStmt->execute([':order_number' => $orderNumber]);
        $exists = $checkStmt->fetch();

        if ($exists) {
            // Actualizar
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}pack_detallado 
                 SET pack = :pack, caja = 'PL', miniatura = :miniatura 
                 WHERE order_number = :order_number"
            );
            $updateStmt->execute([
                ':pack' => $packFile,
                ':miniatura' => $miniatura,
                ':order_number' => $orderNumber
            ]);
        } else {
            // Insertar
            $insertStmt = $this->db->prepare(
                "INSERT INTO {$this->prefix}pack_detallado (order_number, pack, caja, miniatura)
                 VALUES (:order_number, :pack, 'PL', :miniatura)"
            );
            $insertStmt->execute([
                ':order_number' => $orderNumber,
                ':pack' => $packFile,
                ':miniatura' => $miniatura
            ]);
        }
    }

    /**
     * Crea o actualiza cotizacion
     */
    private function upsertCotizacion(string $orderNumber, string $cotizacionFile, ?string $miniatura): void
    {
        $checkStmt = $this->db->prepare(
            "SELECT id FROM {$this->prefix}cotizacion WHERE order_number = :order_number LIMIT 1"
        );
        $checkStmt->execute([':order_number' => $orderNumber]);
        $exists = $checkStmt->fetch();

        if ($exists) {
            // Actualizar
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}cotizacion 
                 SET cotizacion = :cotizacion, miniatura = :miniatura 
                 WHERE order_number = :order_number"
            );
            $updateStmt->execute([
                ':cotizacion' => $cotizacionFile,
                ':miniatura' => $miniatura,
                ':order_number' => $orderNumber
            ]);
        } else {
            // Insertar
            $insertStmt = $this->db->prepare(
                "INSERT INTO {$this->prefix}cotizacion (order_number, cotizacion, miniatura)
                 VALUES (:order_number, :cotizacion, :miniatura)"
            );
            $insertStmt->execute([
                ':order_number' => $orderNumber,
                ':cotizacion' => $cotizacionFile,
                ':miniatura' => $miniatura
            ]);
        }
    }

    /**
     * Sube archivo a /images/pack/
     */
    public function uploadPackFile(string $tempFilePath): array
    {
        return $this->uploadFile($tempFilePath, '/images/pack/');
    }

    /**
     * Sube archivo a /images/cotizacion/
     */
    public function uploadCotizacionFile(string $tempFilePath): array
    {
        return $this->uploadFile($tempFilePath, '/images/cotizacion/');
    }

    /**
     * Método genérico para subir archivos
     */
    private function uploadFile(string $tempFilePath, string $webPath): array
    {
        try {
            if (!file_exists($tempFilePath)) {
                return [
                    'success' => false,
                    'message' => 'Archivo temporal no encontrado',
                    'pdf_path' => null,
                    'thumbnail_path' => null
                ];
            }

            $filename = 'pdf_' . time() . '.pdf';
            $storageDir = MWT_STORAGE_ROOT . $webPath;
            $filePath = $storageDir . $filename;

            if (!is_dir($storageDir)) {
                if (!mkdir($storageDir, 0755, true)) {
                    return [
                        'success' => false,
                        'message' => 'No se pudo crear el directorio: ' . $storageDir,
                        'pdf_path' => null,
                        'thumbnail_path' => null
                    ];
                }
                error_log("✅ Directorio creado: {$storageDir}");
            }

            if (!move_uploaded_file($tempFilePath, $filePath)) {
                return [
                    'success' => false,
                    'message' => 'Error al mover el archivo PDF',
                    'pdf_path' => null,
                    'thumbnail_path' => null
                ];
            }

            error_log("✅ PDF guardado en: {$filePath}");

            $miniatura = $this->createThumbnail($filePath, $storageDir, $webPath);

            return [
                'success' => true,
                'message' => 'Archivo guardado exitosamente',
                'pdf_path' => $webPath . $filename,
                'thumbnail_path' => $miniatura
            ];
        } catch (Exception $e) {
            error_log("❌ Error en uploadFile: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al subir archivo: ' . $e->getMessage(),
                'pdf_path' => null,
                'thumbnail_path' => null
            ];
        }
    }

    /**
     * Crea miniatura PNG a partir del PDF
     */
    private function createThumbnail(string $pdfPath, string $storageDir, string $webPath): ?string
    {
        try {
            if (!extension_loaded('imagick')) {
                error_log("❌ Extensión Imagick no disponible");
                return null;
            }

            $imagick = new Imagick();
            $imagick->readImage($pdfPath . '[0]');
            $imagick->setResolution(150, 150);
            $imagick->setImageFormat('png');

            $thumbnailFilename = 'miniatura_' . time() . '.png';
            $thumbnailFullPath = $storageDir . $thumbnailFilename;

            $imagick->writeImage($thumbnailFullPath);
            $imagick->clear();
            $imagick->destroy();

            error_log("✅ Miniatura creada en: {$thumbnailFullPath}");

            return $webPath . $thumbnailFilename;
        } catch (Exception $e) {
            error_log("❌ Error creating thumbnail: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Elimina registro y archivos de pack_detallado
     */
    public function deletePackDetallado(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT pack, miniatura FROM {$this->prefix}pack_detallado WHERE order_number = :order_number LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $packData = $stmt->fetch();

            if (!$packData) {
                return ['success' => false, 'message' => 'Pack detallado no encontrado'];
            }

            // Eliminar archivos físicos
            $this->deletePhysicalFile($packData['pack']);
            $this->deletePhysicalFile($packData['miniatura']);

            // Eliminar registro
            $deleteStmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}pack_detallado WHERE order_number = :order_number"
            );
            $deleteStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Pack detallado eliminado'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina registro y archivos de cotizacion
     */
    public function deleteCotizacion(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT cotizacion, miniatura FROM {$this->prefix}cotizacion WHERE order_number = :order_number LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $cotizacionData = $stmt->fetch();

            if (!$cotizacionData) {
                return ['success' => false, 'message' => 'Cotización no encontrada'];
            }

            // Eliminar archivos físicos
            $this->deletePhysicalFile($cotizacionData['cotizacion']);
            $this->deletePhysicalFile($cotizacionData['miniatura']);

            // Eliminar registro
            $deleteStmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}cotizacion WHERE order_number = :order_number"
            );
            $deleteStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Cotización eliminada'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina archivo físico del servidor
     */
    private function deletePhysicalFile(?string $filePath): bool
    {
        if (empty($filePath)) {
            return false;
        }

        $fullPath = MWT_STORAGE_ROOT . $filePath;

        if (file_exists($fullPath)) {
            unlink($fullPath);
            error_log("✅ Archivo eliminado: {$fullPath}");
            return true;
        }

        return false;
    }

    /**
     * Lista información completa de preparación
     */
    public function listPreparacionInfo(string $orderNumber): array
    {
        try {
            $baseUrl = 'https://mwt.one';

            // ✅ PRIMERO: Verificar si es pedido hijo (tiene order_parent_id)
            $parentCheckStmt = $this->db->prepare(
                "SELECT order_parent_id FROM {$this->prefix}hikashop_order WHERE order_number = :order_number LIMIT 1"
            );
            $parentCheckStmt->execute([':order_number' => $orderNumber]);
            $parentCheck = $parentCheckStmt->fetch(PDO::FETCH_ASSOC);

            $effectiveOrderNumber = $orderNumber; // Por defecto usa el mismo

            // Si tiene order_parent_id, usar el order_number del pedido padre
            if ($parentCheck && !empty($parentCheck['order_parent_id'])) {
                $parentStmt = $this->db->prepare(
                    "SELECT order_number FROM {$this->prefix}hikashop_order WHERE order_id = :parent_id LIMIT 1"
                );
                $parentStmt->execute([':parent_id' => $parentCheck['order_parent_id']]);
                $parentOrder = $parentStmt->fetch(PDO::FETCH_ASSOC);

                if ($parentOrder) {
                    $effectiveOrderNumber = $parentOrder['order_number'];
                }
            }

            // Obtener datos de hikashop_order con dirección (usando order_number efectivo)
            $orderStmt = $this->db->prepare(
                "SELECT 
                h.order_shipping_method,
                h.order_shipping_price,
                h.manejo_pago,
                h.operator,
                h.Incoterms,
                h.Code_incoterms,
                h.order_shipping_address_id,
                a.address_street,
                a.address_post_code,
                a.address_city,
                a.address_telephone
             FROM {$this->prefix}hikashop_order h
             LEFT JOIN {$this->prefix}hikashop_address a ON h.order_shipping_address_id = a.address_id
             WHERE h.order_number = :order_number
             LIMIT 1"
            );
            $orderStmt->execute([':order_number' => $effectiveOrderNumber]);
            $orderInfo = $orderStmt->fetch();

            // Obtener pack_detallado (usando order_number efectivo)
            $packStmt = $this->db->prepare(
                "SELECT * FROM {$this->prefix}pack_detallado WHERE order_number = :order_number LIMIT 1"
            );
            $packStmt->execute([':order_number' => $effectiveOrderNumber]);
            $packInfo = $packStmt->fetch();

            if ($packInfo) {
                $packInfo['pack_url'] = !empty($packInfo['pack']) ? $baseUrl . $packInfo['pack'] : null;
                $packInfo['miniatura_url'] = !empty($packInfo['miniatura']) ? $baseUrl . $packInfo['miniatura'] : null;
            }

            // Obtener cotizacion (usando order_number efectivo)
            $cotizacionStmt = $this->db->prepare(
                "SELECT * FROM {$this->prefix}cotizacion WHERE order_number = :order_number LIMIT 1"
            );
            $cotizacionStmt->execute([':order_number' => $effectiveOrderNumber]);
            $cotizacionInfo = $cotizacionStmt->fetch();

            if ($cotizacionInfo) {
                $cotizacionInfo['cotizacion_url'] = !empty($cotizacionInfo['cotizacion']) ? $baseUrl . $cotizacionInfo['cotizacion'] : null;
                $cotizacionInfo['miniatura_url'] = !empty($cotizacionInfo['miniatura']) ? $baseUrl . $cotizacionInfo['miniatura'] : null;
            }

            // ✅ Obtener productos (todos) - usando order_number efectivo
            $productosResult = $this->getProductos($effectiveOrderNumber);

            // ✅ Obtener productos con promoción - usando order_number efectivo
            $productosPromoResult = $this->getProductos2($effectiveOrderNumber);

            return [
                'http_code' => 200,
                'success' => true,
                'message' => 'Información de preparación obtenida',
                'original_order_number' => $orderNumber,
                'effective_order_number' => $effectiveOrderNumber,
                'is_child_order' => (!empty($parentCheck['order_parent_id'])),
                'parent_order_id' => $parentCheck['order_parent_id'] ?? null,
                'data' => [
                    'order_info' => $orderInfo,
                    'pack_detallado' => $packInfo,
                    'cotizacion' => $cotizacionInfo,
                    'productos' => $productosResult['success'] ? $productosResult['products'] : [],
                    'productos_promocion' => $productosPromoResult['success'] ? $productosPromoResult['products'] : []
                ]
            ];
        } catch (Exception $e) {
            return [
                'http_code' => 500,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function formatPrice($price): float
    {
        return $price !== null ? (float)number_format((float)$price, 2, '.', '') : 0.00;
    }

    /**
     * Formatea los precios en un array de productos
     */
    private function formatProductPrices(array $products): array
    {
        foreach ($products as &$product) {
            if (isset($product['order_product_price'])) {
                $product['order_product_price'] = $this->formatPrice($product['order_product_price']);
            }
            if (isset($product['order_product_price_diferido'])) {
                $product['order_product_price_diferido'] = $this->formatPrice($product['order_product_price_diferido']);
            }
        }
        return $products;
    }

    /**
     * Obtiene productos de la orden (excluyendo los que tienen cantidad=0 y precio=0)
     */
    public function getProductos(string $orderNumber): array
    {
        try {
            // 1. Obtener cabecera del pedido
            $orderStmt = $this->db->prepare(
                "SELECT order_id, order_full_price 
             FROM {$this->prefix}hikashop_order 
             WHERE order_number = :order_number 
             LIMIT 1"
            );
            $orderStmt->execute([':order_number' => $orderNumber]);
            $order = $orderStmt->fetch();

            if (!$order) {
                return [
                    'success' => false,
                    'message' => "No se encontró ningún pedido con número {$orderNumber}"
                ];
            }

            $orderId = (int)$order['order_id'];

            // 2. Obtener productos del pedido
            $productsStmt = $this->db->prepare(
                "SELECT 
                order_product_id,
                order_product_quantity,
                order_product_name,
                order_product_price,
                order_product_price_diferido,
                order_product_quantity_diferido,
                product_id,
                order_product_promicion
             FROM {$this->prefix}hikashop_order_product
             WHERE order_id = :order_id
             AND NOT (
                COALESCE(order_product_quantity, 0) = 0 
                AND COALESCE(order_product_price, 0) = 0
             )"
            );
            $productsStmt->execute([':order_id' => $orderId]);
            $products = $productsStmt->fetchAll();

            // Formatear precios
            $products = $this->formatProductPrices($products);

            return [
                'success' => true,
                'message' => null,
                'products' => $products,
                'order_full_price' => $this->formatPrice($order['order_full_price'])
            ];
        } catch (Exception $e) {
            error_log("❌ Error en getProductos: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'ERROR_FETCHING_ORDER_DATA'
            ];
        }
    }

    /**
     * Obtiene productos con promoción (order_product_promicion = 1)
     */
    public function getProductos2(string $orderNumber): array
    {
        try {
            // 1. Obtener cabecera del pedido
            $orderStmt = $this->db->prepare(
                "SELECT order_id, order_full_price 
             FROM {$this->prefix}hikashop_order 
             WHERE order_number = :order_number 
             LIMIT 1"
            );
            $orderStmt->execute([':order_number' => $orderNumber]);
            $order = $orderStmt->fetch();

            if (!$order) {
                return [
                    'success' => false,
                    'message' => "No se encontró ningún pedido con número {$orderNumber}"
                ];
            }

            $orderId = (int)$order['order_id'];

            // 2. Obtener productos con promoción
            $productsStmt = $this->db->prepare(
                "SELECT 
                order_product_id,
                order_product_quantity,
                order_product_name,
                order_product_price,
                order_product_price_diferido,
                order_product_quantity_diferido,
                product_id,
                order_product_promicion
             FROM {$this->prefix}hikashop_order_product
             WHERE order_id = :order_id
             AND order_product_promicion = 1"
            );
            $productsStmt->execute([':order_id' => $orderId]);
            $products = $productsStmt->fetchAll();

            // Formatear precios
            $products = $this->formatProductPrices($products);

            return [
                'success' => true,
                'message' => null,
                'products' => $products,
                'order_full_price' => $this->formatPrice($order['order_full_price'])
            ];
        } catch (Exception $e) {
            error_log("❌ Error en getProductos2: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'ERROR_FETCHING_ORDER_DATA'
            ];
        }
    }
    /**
     * Cambia estado de preparación a despacho
     */
    public function changeStatusToDespacho(string $orderNumber): array
    {
        try {
            $checkStmt = $this->db->prepare(
                "SELECT order_status FROM {$this->prefix}hikashop_order WHERE order_number = :order_number LIMIT 1"
            );
            $checkStmt->execute([':order_number' => $orderNumber]);
            $order = $checkStmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Orden no encontrada'];
            }

            if ($order['order_status'] !== 'preparacion') {
                return [
                    'success' => false,
                    'message' => 'El estado actual no es "preparacion". Estado actual: ' . $order['order_status']
                ];
            }

            // Actualizar estado
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}hikashop_order 
             SET order_status = :new_status, order_modified = NOW() 
             WHERE order_number = :order_number"
            );
            $updateStmt->execute([
                ':new_status' => 'despacho',
                ':order_number' => $orderNumber
            ]);

            error_log("✅ Estado cambiado de preparacion a despacho para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE CAMBIO DE ESTADO
            $this->sendStatusChangeEmail($orderNumber, 'despacho');

            return [
                'success' => true,
                'message' => 'Estado cambiado a despacho',
                'new_status' => 'despacho'
            ];
        } catch (Exception $e) {
            error_log("❌ Error en changeStatusToDespacho: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Método auxiliar para enviar email de cambio de estado
     */
    private function sendStatusChangeEmail(string $orderNumber, string $newStatus): void
    {
        try {
            require_once __DIR__ . '/../utils/emailer.php';
            $emailer = new EmailSender();

            // Obtener datos del cliente y number_purchase
            $customerData = $emailer->getCustomerDataForEmail($this->db, $this->prefix, $orderNumber);

            if ($customerData && !empty($customerData['customer_email'])) {
                // Usar number_purchase si existe, sino usar order_number
                $purchaseNumber = $customerData['number_purchase'] ?? $orderNumber;

                // Convertir estado a formato legible
                $statusReadable = $this->getReadableStatus($newStatus);

                $emailSent = $emailer->sendStatusChangeAlert(
                    $customerData['customer_email'],
                    $purchaseNumber,
                    $statusReadable
                );

                if ($emailSent) {
                    error_log("✅ Email de cambio de estado enviado a: {$customerData['customer_email']} - Estado: {$statusReadable}");
                } else {
                    error_log("⚠️ No se pudo enviar el email de cambio de estado");
                }
            } else {
                error_log("⚠️ No se pudo enviar email: datos de cliente no encontrados para orden {$orderNumber}");
            }
        } catch (Exception $e) {
            error_log("❌ Error enviando email de estado: " . $e->getMessage());
        }
    }

    /**
     * Convierte el código de estado a texto legible
     */
    private function getReadableStatus(string $status): string
    {
        $statusMap = [
            'confirmed' => 'Confirmado',
            'credito' => 'Crédito',
            'produccion' => 'Producción',
            'preparacion' => 'Preparación',
            'despacho' => 'Despacho',
            'pending' => 'Pendiente',
            'shipped' => 'Enviado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado'
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }
}
