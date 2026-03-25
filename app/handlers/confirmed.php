<?php
require_once __DIR__ . '/handler-base.php';

class HandlerConfirmed extends HandlerBase
{
    public function process(array $orderData, object $payload): array
    {
        try {
            $orderId = $orderData['order_id'];
            $customerId = $orderData['customer_id'];

            $items = $this->getOrderItems($orderId);
            $customer = $this->getCustomerInfo($customerId);

            return [
                'http_code' => 200,
                'success' => true,
                'message' => 'Orden confirmada',
                'status' => 'confirmed',
                'order' => [
                    'order_id' => $orderId,
                    'order_number' => $orderData['order_number'],
                    'order_date' => $orderData['order_created'],
                    'customer_name' => $customer['customer_name'] ?? 'N/A',
                    'total' => $orderData['order_total'],
                    'items_count' => count($items)
                ]
            ];
        } catch (Exception $e) {
            error_log("Error en confirmed handler: " . $e->getMessage());
            return [
                'http_code' => 500,
                'success' => false,
                'error' => 'Error procesando orden confirmada'
            ];
        }
    }

    /**
     * Sube y guarda un archivo PDF en MWT_STORAGE_ROOT
     * Retorna URL para guardar en BD
     */
    public function uploadAndSaveFile(string $tempFilePath): array
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

            // ✅ Rutas separadas: física y web
            $webPath = '/images/orders/';  // Lo que va en BD
            $filename = 'pdf_' . time() . '.pdf';

            // Ruta física para guardar
            $storageDir = MWT_STORAGE_ROOT . $webPath;
            $filePath = $storageDir . $filename;

            // Crear directorio si no existe
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

            // Mover archivo PDF
            if (!move_uploaded_file($tempFilePath, $filePath)) {
                return [
                    'success' => false,
                    'message' => 'Error al mover el archivo PDF',
                    'pdf_path' => null,
                    'thumbnail_path' => null
                ];
            }

            error_log("✅ PDF guardado en: {$filePath}");

            // Crear miniatura
            $miniatura = $this->createThumbnail($filePath, $storageDir, $webPath);

            if (!$miniatura) {
                return [
                    'success' => true,
                    'message' => 'PDF guardado pero no se pudo generar miniatura',
                    'pdf_path' => $webPath . $filename,
                    'thumbnail_path' => null
                ];
            }

            return [
                'success' => true,
                'message' => 'PDF y miniatura guardados exitosamente',
                'pdf_path' => $webPath . $filename,
                'thumbnail_path' => $miniatura
            ];
        } catch (Exception $e) {
            error_log("❌ Error en uploadAndSaveFile: " . $e->getMessage());
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
     * @param string $pdfPath Ruta física del PDF
     * @param string $storageDir Directorio físico donde guardar
     * @param string $webPath URL relativa para BD
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

            // Guardar miniatura en ruta física
            $imagick->writeImage($thumbnailFullPath);

            $imagick->clear();
            $imagick->destroy();

            error_log("✅ Miniatura creada en: {$thumbnailFullPath}");

            // Retornar URL para BD
            return $webPath . $thumbnailFilename;
        } catch (Exception $e) {
            error_log("❌ Error creating thumbnail: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Crea o actualiza registro en preforma
     */
    public function createOrUpdatePreforma(
        string $orderNumber,
        ?string $purchaseCompra = null,
        ?int $clienteId = null,
        ?int $operador = null,
        ?string $filePath = null,
        ?string $miniaturePath = null
    ): array {
        try {
            $checkStmt = $this->db->prepare(
                "SELECT id FROM {$this->prefix}preforma WHERE order_number = :order_number LIMIT 1"
            );
            $checkStmt->execute([':order_number' => $orderNumber]);
            $existingRecord = $checkStmt->fetch();

            if ($existingRecord) {
                // ========== CASO: ACTUALIZAR PREFORMA EXISTENTE ==========
                $updateFields = [];
                $params = [':order_number' => $orderNumber];

                if (!is_null($filePath)) {
                    $updateFields[] = 'preformar = :filePath';
                    $params[':filePath'] = $filePath;
                }

                if (!is_null($miniaturePath)) {
                    $updateFields[] = 'miniatura = :miniaturePath';
                    $params[':miniaturePath'] = $miniaturePath;
                }

                if (!is_null($purchaseCompra)) {
                    $updateFields[] = 'number_purchase = :purchaseCompra';
                    $params[':purchaseCompra'] = $purchaseCompra;
                }

                $this->updateHikashopOrder($orderNumber, $clienteId, $operador);

                if (!empty($updateFields)) {
                    $updateQuery = "UPDATE {$this->prefix}preforma SET " . implode(', ', $updateFields) . " WHERE order_number = :order_number";
                    $updateStmt = $this->db->prepare($updateQuery);
                    $updateStmt->execute($params);
                }

                error_log("✅ Preforma actualizada para orden: {$orderNumber}");

                // ✅ ENVIAR EMAIL DE ACTUALIZACIÓN
                $this->sendPreformaEmail($orderNumber);

                return [
                    'success' => true,
                    'message' => 'Registro de preforma actualizado',
                    'preforma_id' => $existingRecord['id']
                ];
            } else {
                // ========== CASO: CREAR NUEVA PREFORMA ==========
                $columns = ['order_number'];
                $placeholders = [':order_number'];
                $values = [':order_number' => $orderNumber];

                if (!is_null($filePath)) {
                    $columns[] = 'preformar';
                    $placeholders[] = ':filePath';
                    $values[':filePath'] = $filePath;
                }

                if (!is_null($miniaturePath)) {
                    $columns[] = 'miniatura';
                    $placeholders[] = ':miniaturePath';
                    $values[':miniaturePath'] = $miniaturePath;
                }

                if (!is_null($purchaseCompra)) {
                    $columns[] = 'number_purchase';
                    $placeholders[] = ':purchaseCompra';
                    $values[':purchaseCompra'] = $purchaseCompra;
                }

                $insertQuery = "INSERT INTO {$this->prefix}preforma (" . implode(', ', $columns) . ") 
                           VALUES (" . implode(', ', $placeholders) . ")";

                $insertStmt = $this->db->prepare($insertQuery);
                $insertStmt->execute($values);

                $newId = $this->db->lastInsertId();

                $this->updateHikashopOrder($orderNumber, $clienteId, $operador);

                error_log("✅ Preforma creada para orden: {$orderNumber} con ID: {$newId}");

                // ✅ ENVIAR EMAIL DE CREACIÓN
                $this->sendPreformaEmail($orderNumber);

                return [
                    'success' => true,
                    'message' => 'Registro de preforma creado',
                    'preforma_id' => $newId
                ];
            }
        } catch (Exception $e) {
            error_log("❌ Error en createOrUpdatePreforma: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear/actualizar preforma: ' . $e->getMessage(),
                'preforma_id' => null
            ];
        }
    }

    /**
     * Método auxiliar para enviar email de preforma
     */
    private function sendPreformaEmail(string $orderNumber): void
    {
        try {
            require_once __DIR__ . '/../utils/emailer.php';
            $emailer = new EmailSender();

            // Obtener datos del cliente incluyendo number_purchase
            $customerData = $emailer->getCustomerDataForEmail($this->db, $this->prefix, $orderNumber);

            if (is_array($customerData) && !empty($customerData['customer_email'])) {
                $operadorNombre = ($customerData['operado_mwt'] == 1)
                    ? 'Muito Work Limitada'
                    : 'Cliente';

                // ✅ Usar number_purchase en lugar de order_number
                $purchaseNumber = $customerData['number_purchase'] ?? $orderNumber;

                $emailSent = $emailer->sendPreformaCreationAlert(
                    $customerData['customer_email'],
                    $purchaseNumber,  // ✅ Aquí va el number_purchase
                    $customerData['customer_name'],
                    $operadorNombre
                );

                if ($emailSent) {
                    error_log("✅ Email de preforma enviado a: {$customerData['customer_email']} - Purchase: {$purchaseNumber}");
                } else {
                    error_log("⚠️ No se pudo enviar el email de preforma");
                }
            } else {
                error_log("⚠️ No se pudo enviar email: datos de cliente no encontrados para orden {$orderNumber}");
            }
        } catch (Exception $e) {
            error_log("❌ Error enviando email de preforma: " . $e->getMessage());
        }
    }


    /**
     * Actualiza hikashop_order con customer e operador_mwt
     */
    private function updateHikashopOrder(?string $orderNumber, ?int $clienteId, ?int $operador): bool
    {
        if (is_null($orderNumber)) {
            return false;
        }

        try {
            $updateFields = [];
            $params = [':order_number' => $orderNumber];

            if (!is_null($clienteId)) {
                $updateFields[] = 'customer = :clienteId';
                $params[':clienteId'] = $clienteId;
            }

            if (!is_null($operador)) {
                $updateFields[] = 'operado_mwt = :operador';
                $params[':operador'] = ($operador > 0) ? 1 : 0;
            }

            if (empty($updateFields)) {
                return true;
            }

            $updateQuery = "UPDATE {$this->prefix}hikashop_order SET " . implode(', ', $updateFields) . " WHERE order_number = :order_number";
            $stmt = $this->db->prepare($updateQuery);
            return $stmt->execute($params);
        } catch (Exception $e) {
            error_log("Error actualizando hikashop_order: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Cambia el estado de confirmed a credito
     */
    public function changeStatus(string $orderNumber): array
    {
        try {
            $checkStmt = $this->db->prepare(
                "SELECT order_status, customer FROM {$this->prefix}hikashop_order WHERE order_number = :order_number LIMIT 1"
            );
            $checkStmt->execute([':order_number' => $orderNumber]);
            $order = $checkStmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Orden no encontrada'];
            }

            if ($order['order_status'] !== 'confirmed') {
                return ['success' => false, 'message' => 'El estado actual no es "confirmed". Estado actual: ' . $order['order_status']];
            }

            // Actualizar estado
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}hikashop_order SET order_status = :newStatus, order_modified = NOW() WHERE order_number = :order_number"
            );
            $updateStmt->execute([':newStatus' => 'credito', ':order_number' => $orderNumber]);

            error_log("✅ Estado cambiado de confirmed a credito para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE CAMBIO DE ESTADO
            $this->sendStatusChangeEmail($orderNumber, 'credito');

            return ['success' => true, 'message' => 'Estado cambiado de confirmed a credito'];
        } catch (Exception $e) {
            error_log("❌ Error en changeStatus: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error al cambiar estado: ' . $e->getMessage()];
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
            'pending' => 'Pendiente',
            'shipped' => 'Enviado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado'
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }


    /**
     * Elimina el registro de preforma y sus archivos asociados
     */
    public function deletePreformaRecord(string $orderNumber): array
    {
        try {
            $getStmt = $this->db->prepare(
                "SELECT preformar, miniatura FROM {$this->prefix}preforma WHERE order_number = :order_number LIMIT 1"
            );
            $getStmt->execute([':order_number' => $orderNumber]);
            $preformaRecord = $getStmt->fetch();

            if (!$preformaRecord) {
                return ['success' => false, 'message' => 'Registro de preforma no encontrado'];
            }

            // Eliminar archivos del servidor usando ruta física
            if (!empty($preformaRecord['preformar'])) {
                $filePath = MWT_STORAGE_ROOT . $preformaRecord['preformar'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                    error_log("✅ Archivo PDF eliminado: {$filePath}");
                }
            }

            if (!empty($preformaRecord['miniatura'])) {
                $miniaturePath = MWT_STORAGE_ROOT . $preformaRecord['miniatura'];
                if (file_exists($miniaturePath)) {
                    unlink($miniaturePath);
                    error_log("✅ Miniatura eliminada: {$miniaturePath}");
                }
            }

            $deleteStmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}preforma WHERE order_number = :order_number"
            );
            $deleteStmt->execute([':order_number' => $orderNumber]);

            error_log("✅ Registro de preforma eliminado para orden: {$orderNumber}");

            return ['success' => true, 'message' => 'Registro de preforma y archivos eliminados'];
        } catch (Exception $e) {
            error_log("❌ Error en deletePreformaRecord: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error al eliminar preforma: ' . $e->getMessage()];
        }
    }

    /**
     * Elimina solo los archivos pero mantiene el registro sin archivos
     */
    public function deleteFilesOnly(string $orderNumber): array
    {
        try {
            $getStmt = $this->db->prepare(
                "SELECT preformar, miniatura FROM {$this->prefix}preforma WHERE order_number = :order_number LIMIT 1"
            );
            $getStmt->execute([':order_number' => $orderNumber]);
            $preformaRecord = $getStmt->fetch();

            if (!$preformaRecord) {
                return ['success' => false, 'message' => 'Registro de preforma no encontrado'];
            }

            // Eliminar archivos
            if (!empty($preformaRecord['preformar'])) {
                $filePath = MWT_STORAGE_ROOT . $preformaRecord['preformar'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                    error_log("✅ Archivo PDF eliminado: {$filePath}");
                }
            }

            if (!empty($preformaRecord['miniatura'])) {
                $miniaturePath = MWT_STORAGE_ROOT . $preformaRecord['miniatura'];
                if (file_exists($miniaturePath)) {
                    unlink($miniaturePath);
                    error_log("✅ Miniatura eliminada: {$miniaturePath}");
                }
            }

            // Actualizar registro
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}preforma SET preformar = NULL, miniatura = NULL WHERE order_number = :order_number"
            );
            $updateStmt->execute([':order_number' => $orderNumber]);

            error_log("✅ Archivos eliminados pero registro mantenido para orden: {$orderNumber}");

            return ['success' => true, 'message' => 'Archivos eliminados. Registro mantiene otros datos'];
        } catch (Exception $e) {
            error_log("❌ Error en deleteFilesOnly: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error al eliminar archivos: ' . $e->getMessage()];
        }
    }
    /**
     * Obtiene el registro completo de preforma por order_number con datos relacionados
     * @param string $orderNumber
     * @return array|null Retorna el registro completo o null si no existe
     */
    public function getPreforma(string $orderNumber): ?array
    {
        try {
            // Query con JOINs para traer todo en una sola consulta
            $stmt = $this->db->prepare("
            SELECT 
                p.*,
                h.customer,
                h.operado_mwt,
                c.customer_name
            FROM {$this->prefix}preforma p
            LEFT JOIN {$this->prefix}hikashop_order h ON p.order_number = h.order_number
            LEFT JOIN {$this->prefix}customer c ON h.customer = c.customer_id
            WHERE p.order_number = :order_number
            LIMIT 1
        ");

            $stmt->execute([':order_number' => $orderNumber]);
            $preforma = $stmt->fetch();

            if (!$preforma) {
                error_log("⚠️ Preforma no encontrada para orden: {$orderNumber}");
                return null;
            }

            // Construir URLs completas para PDF y miniatura
            $baseUrl = 'https://mwt.one';

            if (!empty($preforma['preformar'])) {
                $preforma['preformar_url'] = $baseUrl . $preforma['preformar'];
            } else {
                $preforma['preformar_url'] = null;
            }

            if (!empty($preforma['miniatura'])) {
                $preforma['miniatura_url'] = $baseUrl . $preforma['miniatura'];
            } else {
                $preforma['miniatura_url'] = null;
            }

            // Transformar operado_mwt a texto legible
            $preforma['operador_nombre'] = ($preforma['operado_mwt'] == 1)
                ? 'Muito Work Limitada'
                : 'Cliente';

            // Mantener customer_name del JOIN
            $preforma['customer_name'] = $preforma['customer_name'] ?? 'N/A';

            error_log("✅ Preforma encontrada para orden: {$orderNumber}");
            return $preforma;
        } catch (Exception $e) {
            error_log("❌ Error en getPreforma: " . $e->getMessage());
            return null;
        }
    }
}
