<?php
require_once __DIR__ . '/handler-base.php';

class HandlerPagado extends HandlerBase
{
    /**
     * Procesa una orden en estado "pagado"
     */
    public function process(array $orderData, object $payload): array
    {
        try {
            $orderNumber = $orderData['order_number'];
            return $this->listPagoInfo($orderNumber);
        } catch (Exception $e) {
            error_log("Error en pagado handler: " . $e->getMessage());
            return [
                'http_code' => 500,
                'success' => false,
                'error' => 'Error procesando orden pagada'
            ];
        }
    }
    public function createPago(
        string $orderNumber,
        ?string $tipoPago = null,
        ?float $cantidadPago = null,
        ?int $metodoPago = null,
        ?string $comprobanteFile = null,
        ?string $comprobanteMiniatura = null,
        ?string $adiccional = null,
        ?string $fechaPago = null,
        ?string $nombre = null
    ): array {
        try {
            $this->db->beginTransaction();
            $orderInfo = $this->getOrderInfo($orderNumber);

            if (!$orderInfo) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Orden no encontrada'];
            }

            $customerId = $this->getTargetCustomerId($orderNumber);
            if ($customerId === null) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'No se pudo determinar el cliente a afectar'];
            }
            $orderFullPrice = (float)$orderInfo['order_full_price'];
            $orderFullPriceDiferido = !empty($orderInfo['order_full_price_diferido'])
                ? (float)$orderInfo['order_full_price_diferido']
                : null;

            $precioObjetivo = $orderFullPriceDiferido ?? $orderFullPrice;

            // Para pago completo, cantidadPago es el precio objetivo
            if ($tipoPago === 'Completo') {
                $cantidadPago = $precioObjetivo;
            }

            $existingPayment = $this->getPaymentRecord($orderNumber);

            if ($tipoPago === 'Completo') {
                $result = $this->processCompletePayment(
                    $orderNumber,
                    $customerId,
                    $precioObjetivo,
                    $metodoPago,
                    $comprobanteFile,
                    $comprobanteMiniatura,
                    $adiccional,
                    $fechaPago,
                    $nombre,
                    $existingPayment
                );
            } elseif ($tipoPago === 'Parcial') {
                $result = $this->processPartialPayment(
                    $orderNumber,
                    $customerId,
                    $cantidadPago,
                    $precioObjetivo,
                    $metodoPago,
                    $comprobanteFile,
                    $comprobanteMiniatura,
                    $adiccional,
                    $fechaPago,
                    $nombre
                );
            } else {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Tipo de pago no especificado'];
            }

            if ($result['success']) {
                try {
                    require_once __DIR__ . '/../utils/emailer.php';
                    $emailer = new EmailSender();
                    $customerData = $emailer->getCustomerDataForEmail($this->db, $this->prefix, $orderNumber);
                    if ($customerData && !empty($customerData['customer_email'])) {
                        $purchaseNumber = $customerData['number_purchase'] ?? $orderNumber;
                        $metodoPagoText = $this->mapMetodoPago($metodoPago) ?? '';
                        $emailSent = $emailer->sendPagadoAlert(
                            $customerData['customer_email'],
                            $purchaseNumber,
                            $tipoPago,
                            $metodoPagoText,
                            $cantidadPago,
                            $fechaPago
                        );
                        if ($emailSent) {
                            error_log("✅ Email de pago enviado a: {$customerData['customer_email']}");
                        } else {
                            error_log("⚠️ Falló el envío de email de pago a: {$customerData['customer_email']}");
                        }
                    }
                } catch (Exception $e) {
                    error_log("❌ Error enviando email de pago: " . $e->getMessage());
                }
            }

            return $result;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("❌ Error en createPago: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear pago: ' . $e->getMessage()
            ];
        }
    }


    // --- Lógica principal de pagos ---

    private function processCompletePayment(
        string $orderNumber,
        int $customerId,
        float $precioObjetivo,
        ?int $metodoPago,
        ?string $comprobanteFile,
        ?string $comprobanteMiniatura,
        ?string $adiccional,
        ?string $fechaPago,
        ?string $nombre,
        $existingPayment
    ): array {
        $metodoPagoText = $this->mapMetodoPago($metodoPago);

        if ($existingPayment && $existingPayment['tipo_pago'] === 'Completo') {
            $this->updatePaymentRecord(
                $orderNumber,
                'Credito Liberado',
                'Completo',
                $precioObjetivo,
                $metodoPagoText,
                $comprobanteFile,
                $comprobanteMiniatura,
                $adiccional,
                $fechaPago,
                $nombre
            );
            $this->db->commit();
            return ['success' => true, 'message' => 'Pago completo actualizado'];
        }

        $this->insertPaymentRecord(
            $orderNumber,
            'Credito Liberado',
            'Completo',
            $precioObjetivo,
            $metodoPagoText,
            $comprobanteFile,
            $comprobanteMiniatura,
            $adiccional,
            $fechaPago,
            $nombre
        );
        $this->updateCustomerCredit($customerId, $precioObjetivo);
        $this->db->commit();
        return ['success' => true, 'message' => 'Pago completo registrado'];
    }

    private function processPartialPayment(
        string $orderNumber,
        int $customerId,
        ?float $cantidadPago,
        float $precioObjetivo,
        ?int $metodoPago,
        ?string $comprobanteFile,
        ?string $comprobanteMiniatura,
        ?string $adiccional,
        ?string $fechaPago,
        ?string $nombre
    ): array {
        if ($cantidadPago === null || $cantidadPago <= 0) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Cantidad de pago requerida para pago parcial'];
        }
        if ($cantidadPago > $precioObjetivo) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'La cantidad supera el valor del pedido'];
        }
        $metodoPagoText = $this->mapMetodoPago($metodoPago);
        $totalPagado = $this->getTotalPaidAmount($orderNumber);
        $nuevoTotal = $totalPagado + $cantidadPago;
        if ($nuevoTotal > $precioObjetivo) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'La suma de pagos supera el valor del pedido'];
        }
        $status = ($nuevoTotal >= $precioObjetivo) ? 'Credito Liberado' : 'Pago Incompleto';
        $newOrderNumber = $this->generatePartialOrderNumber($orderNumber);
        $this->insertPaymentRecord(
            $newOrderNumber,
            $status,
            'Parcial',
            $cantidadPago,
            $metodoPagoText,
            $comprobanteFile,
            $comprobanteMiniatura,
            $adiccional,
            $fechaPago,
            $nombre
        );
        $this->updateCustomerCredit($customerId, $cantidadPago);
        $this->db->commit();
        return ['success' => true, 'message' => 'Pago parcial registrado', 'status' => $status];
    }

    // --- Métodos auxiliares para consulta, altas y bajas ---

    private function getOrderInfo(string $orderNumber): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT customer, order_full_price, order_full_price_diferido 
             FROM {$this->prefix}hikashop_order 
             WHERE order_number = :order_number 
             LIMIT 1"
        );
        $stmt->execute([':order_number' => $orderNumber]);
        return $stmt->fetch();
    }

    private function getPaymentRecord(string $orderNumber): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->prefix}pago_order 
         WHERE order_number = :order_number 
         LIMIT 1"
        );
        $stmt->execute([':order_number' => $orderNumber]);
        $result = $stmt->fetch();
        return $result === false ? null : $result; // <--- CORREGIDO
    }


    private function getLastPartialPayment(string $orderNumber): ?array
    {
        $baseOrderNumber = preg_replace('/-\d{3}$/', '', $orderNumber);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->prefix}pago_order 
             WHERE order_number LIKE :pattern 
             ORDER BY id DESC 
             LIMIT 1"
        );
        $stmt->execute([':pattern' => $baseOrderNumber . '%']);
        return $stmt->fetch();
    }

    private function getTotalPaidAmount(string $orderNumber): float
    {
        $baseOrderNumber = preg_replace('/-\d{3}$/', '', $orderNumber);
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(cantidad_pago), 0) as total 
             FROM {$this->prefix}pago_order 
             WHERE order_number LIKE :pattern"
        );
        $stmt->execute([':pattern' => $baseOrderNumber . '%']);
        $result = $stmt->fetch();
        return (float)$result['total'];
    }
    /**
     * Devuelve el customer_id correcto según reglas de operado_mwt
     */


    private function generatePartialOrderNumber(string $orderNumber): string
    {
        $baseOrderNumber = preg_replace('/-\d{3}$/', '', $orderNumber);
        $stmt = $this->db->prepare(
            "SELECT order_number FROM {$this->prefix}pago_order 
             WHERE order_number LIKE :pattern 
             ORDER BY order_number DESC 
             LIMIT 1"
        );
        $stmt->execute([':pattern' => $baseOrderNumber . '%']);
        $lastRecord = $stmt->fetch();
        if (!$lastRecord) {
            return $baseOrderNumber . '-002';
        }
        if (preg_match('/-(\d+)$/', $lastRecord['order_number'], $matches)) {
            $nextSuffix = (int)$matches[1] + 1;
            return $baseOrderNumber . '-' . str_pad($nextSuffix, 3, '0', STR_PAD_LEFT);
        }
        return $baseOrderNumber . '-002';
    }

    private function insertPaymentRecord(
        string $orderNumber,
        string $status,
        string $tipoPago,
        float $cantidadPago,
        ?string $metodoPago,
        ?string $comprobante,
        ?string $miniatura,
        ?string $adiccional,
        ?string $fechaPago,
        ?string $nombre
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO {$this->prefix}pago_order 
             (order_number, status, tipo_pago, cantidad_pago, metodo_pago, comprobante, miniatura, adiccional, fecha_pago, nombre)
             VALUES (:order_number, :status, :tipo_pago, :cantidad_pago, :metodo_pago, :comprobante, :miniatura, :adiccional, :fecha_pago, :nombre)"
        );
        $stmt->execute([
            ':order_number' => $orderNumber,
            ':status' => $status,
            ':tipo_pago' => $tipoPago,
            ':cantidad_pago' => $cantidadPago,
            ':metodo_pago' => $metodoPago,
            ':comprobante' => $comprobante,
            ':miniatura' => $miniatura,
            ':adiccional' => $adiccional,
            ':fecha_pago' => $fechaPago,
            ':nombre' => $nombre
        ]);
    }

    private function updatePaymentRecord(
        string $orderNumber,
        string $status,
        string $tipoPago,
        float $cantidadPago,
        ?string $metodoPago,
        ?string $comprobante,
        ?string $miniatura,
        ?string $adiccional,
        ?string $fechaPago,
        ?string $nombre
    ): void {
        $fields = [];
        $params = [':order_number' => $orderNumber];
        $fields[] = 'status = :status';
        $params[':status'] = $status;
        $fields[] = 'tipo_pago = :tipo_pago';
        $params[':tipo_pago'] = $tipoPago;
        $fields[] = 'cantidad_pago = :cantidad_pago';
        $params[':cantidad_pago'] = $cantidadPago;
        if ($metodoPago !== null) {
            $fields[] = 'metodo_pago = :metodo_pago';
            $params[':metodo_pago'] = $metodoPago;
        }
        if ($comprobante !== null) {
            $fields[] = 'comprobante = :comprobante';
            $params[':comprobante'] = $comprobante;
        }
        if ($miniatura !== null) {
            $fields[] = 'miniatura = :miniatura';
            $params[':miniatura'] = $miniatura;
        }
        if ($adiccional !== null) {
            $fields[] = 'adiccional = :adiccional';
            $params[':adiccional'] = $adiccional;
        }
        if ($fechaPago !== null) {
            $fields[] = 'fecha_pago = :fecha_pago';
            $params[':fecha_pago'] = $fechaPago;
        }
        if ($nombre !== null) {
            $fields[] = 'nombre = :nombre';
            $params[':nombre'] = $nombre;
        }
        $setClause = implode(', ', $fields);
        $stmt = $this->db->prepare(
            "UPDATE {$this->prefix}pago_order SET {$setClause} WHERE order_number = :order_number"
        );
        $stmt->execute($params);
    }

    private function updateCustomerCredit(int $customerId, float $amount): void
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->prefix}customer 
             SET customer_credit = COALESCE(customer_credit, 0) + :amount 
             WHERE customer_id = :customer_id"
        );
        $stmt->execute([
            ':amount' => $amount,
            ':customer_id' => $customerId
        ]);
    }

    private function mapMetodoPago(?int $code): ?string
    {
        if ($code === null) return null;
        $map = [
            1 => 'Transferencia Bancaria',
            2 => 'Nota Credito'
        ];
        return $map[$code] ?? null;
    }

    public function uploadComprobanteFile(string $tempFilePath): array
    {
        return $this->uploadFile($tempFilePath, '/images/comprobantes/');
    }

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
    public function deleteComprobante(int $pagoId): array
    {
        try {
            // Obtener el pago específico por ID
            $stmt = $this->db->prepare(
                "SELECT id, comprobante, miniatura FROM {$this->prefix}pago_order WHERE id = :id"
            );
            $stmt->execute([':id' => $pagoId]);
            $pago = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pago) {
                return ['success' => false, 'message' => 'Registro de pago no encontrado'];
            }

            // Eliminar archivos físicos
            if (!empty($pago['comprobante'])) {
                $this->deletePhysicalFile($pago['comprobante']);
            }
            if (!empty($pago['miniatura'])) {
                $this->deletePhysicalFile($pago['miniatura']);
            }

            // Actualizar registro en BD
            $stmt = $this->db->prepare(
                "UPDATE {$this->prefix}pago_order SET comprobante = NULL, miniatura = NULL WHERE id = :id"
            );
            $stmt->execute([':id' => $pagoId]);

            return ['success' => true, 'message' => 'Comprobante eliminado correctamente'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deletePago(string $orderNumber): array
    {
        try {
            $this->db->beginTransaction();
            $baseOrderNumber = preg_replace('/-\d{3}$/', '', $orderNumber);
            $pago = $this->getLastPartialPayment($orderNumber) ?? $this->getPaymentRecord($orderNumber);
            if (!$pago) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Registro de pago no encontrado'];
            }

            $customerId = $this->getTargetCustomerId($baseOrderNumber);
            if ($customerId === null) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Cliente no encontrado para ajuste de crédito'];
            }

            $stmt = $this->db->prepare(
                "UPDATE {$this->prefix}customer 
             SET customer_credit = COALESCE(customer_credit,0) - :cantidad 
             WHERE customer_id = :customer_id"
            );
            $stmt->execute([
                ':cantidad' => $pago['cantidad_pago'],
                ':customer_id' => $customerId
            ]);

            $this->deletePhysicalFile($pago['comprobante']);
            $this->deletePhysicalFile($pago['miniatura']);

            $delstmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}pago_order WHERE id = :id"
            );
            $delstmt->execute([':id' => $pago['id']]);

            $this->db->commit();
            return ['success' => true, 'message' => 'Pago y archivos eliminados y crédito ajustado'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getTargetCustomerId(string $orderNumber): ?int
    {
        error_log("Buscando customer_id para orden: {$orderNumber}");
        $stmt = $this->db->prepare("SELECT customer, operado_mwt FROM {$this->prefix}hikashop_order WHERE order_number = :order_number LIMIT 1");
        $stmt->execute([':order_number' => $orderNumber]);
        $data = $stmt->fetch();
        if (!$data) {
            error_log("No encontrado customer para orden: {$orderNumber}");
            return null;
        }
        $customerId = ($data['operado_mwt'] == 1) ? 11 : (int) $data['customer'];
        error_log("CustomerId resuelto: {$customerId}");
        return $customerId;
    }

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

    public function listPagoInfo(string $orderNumber): array
    {
        try {
            $baseOrderNumber = preg_replace('/-\d{3}$/', '', $orderNumber);
            $baseUrl = 'https://mwt.one';
            $stmt = $this->db->prepare(
                "SELECT status, tipo_pago, cantidad_pago, metodo_pago, comprobante, adiccional, fecha_pago, nombre, miniatura, order_number, id
                 FROM {$this->prefix}pago_order
                 WHERE order_number LIKE :pattern
                 ORDER BY id"
            );
            $stmt->execute([':pattern' => $baseOrderNumber . '%']);
            $pagos = $stmt->fetchAll();
            $mapMetodo = [
                'Transferencia Bancaria' => 'Transferencia Bancaria',
                'Nota Credito' => 'Nota de Crédito'
            ];
            foreach ($pagos as &$pago) {
                if (!empty($pago['comprobante'])) {
                    $pago['comprobante_url'] = $baseUrl . $pago['comprobante'];
                }
                if (!empty($pago['miniatura'])) {
                    $pago['miniatura_url'] = $baseUrl . $pago['miniatura'];
                }
                $pago['metodo_pago_legible'] = isset($mapMetodo[$pago['metodo_pago']]) ? $mapMetodo[$pago['metodo_pago']] : $pago['metodo_pago'];
            }
            return [
                'http_code' => 200,
                'success' => true,
                'message' => 'Información de pagos obtenida',
                'data' => $pagos
            ];
        } catch (Exception $e) {
            return [
                'http_code' => 500,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function changeStatusToArchivada(string $orderNumber): array
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
            if ($order['order_status'] !== 'pagado') {
                return [
                    'success' => false,
                    'message' => 'El estado actual no es \"pagado\". Estado actual: ' . $order['order_status']
                ];
            }
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}hikashop_order 
             SET order_status = :new_status, order_modified = NOW() 
             WHERE order_number = :order_number"
            );
            $updateStmt->execute([
                ':new_status' => 'Archivada',
                ':order_number' => $orderNumber
            ]);
            error_log("✅ Estado cambiado de pagado a Archivada para orden: {$orderNumber}");
            // Enviar email de cambio de estado
            $this->sendStatusChangeEmail($orderNumber, 'Archivada');
            return [
                'success' => true,
                'message' => 'Estado cambiado a Archivada',
                'new_status' => 'Archivada'
            ];
        } catch (Exception $e) {
            error_log("❌ Error en changeStatusToArchivada: " . $e->getMessage());
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
            'transito' => 'Tránsito',
            'pagado' => 'Pagado',
            'archivada' => 'Archivada',
            'pending' => 'Pendiente',
            'shipped' => 'Enviado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado'
        ];

        return $statusMap[strtolower($status)] ?? ucfirst($status);
    }
}
