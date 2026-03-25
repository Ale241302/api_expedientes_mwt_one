<?php
require_once __DIR__ . '/handler-base.php';

class HandlerTransito extends HandlerBase
{
    /**
     * Procesa una orden en estado "transito"
     */
    public function process(array $orderData, object $payload): array
    {
        try {
            $orderNumber = $orderData['order_number'];

            // Listar información actualizada
            return $this->listTransitoInfo($orderNumber);
        } catch (Exception $e) {
            error_log("Error en transito handler: " . $e->getMessage());
            return [
                'http_code' => 500,
                'success' => false,
                'error' => 'Error procesando orden en tránsito'
            ];
        }
    }

    /**
     * Crea o actualiza información de tránsito
     */
    /**
     * Crea o actualiza información de tránsito
     */
    public function crearTransito(
        string $orderNumber,
        ?string $fechaArribo = null,
        ?string $puertoIntermedio = null,
        ?string $packFile = null,
        ?string $packMiniatura = null
    ): array {
        try {
            $this->db->beginTransaction();

            // 1. Verificar que existe registro en shipping
            $checkShipping = $this->db->prepare(
                "SELECT id FROM {$this->prefix}shipping WHERE order_number = :order_number LIMIT 1"
            );
            $checkShipping->execute([':order_number' => $orderNumber]);
            $shippingExists = $checkShipping->fetch();

            if (!$shippingExists) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => 'No existe registro de shipping para esta orden'
                ];
            }

            // 2. Actualizar shipping
            $this->updateShipping($orderNumber, $fechaArribo, $puertoIntermedio);

            // 3. Crear/actualizar pack
            if ($packFile !== null) {
                $this->upsertPack($orderNumber, $packFile, $packMiniatura);
            }

            $this->db->commit();

            error_log("✅ Tránsito actualizado para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE TRÁNSITO
            $this->sendTransitoEmail(
                $orderNumber,
                $fechaArribo,
                $puertoIntermedio
            );

            return [
                'success' => true,
                'message' => 'Información de tránsito actualizada'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("❌ Error en crearTransito: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear tránsito: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Método auxiliar para enviar email de tránsito
     */
    private function sendTransitoEmail(
        string $orderNumber,
        ?string $fechaArribo,
        ?string $puertoIntermedio
    ): void {
        try {
            require_once __DIR__ . '/../utils/emailer.php';
            $emailer = new EmailSender();

            // Obtener datos del cliente y purchase number
            $customerData = $emailer->getCustomerDataForEmail($this->db, $this->prefix, $orderNumber);

            if ($customerData && !empty($customerData['customer_email'])) {
                // Usar number_purchase si existe
                $purchaseNumber = $customerData['number_purchase'] ?? $orderNumber;

                $emailSent = $emailer->sendTransitoAlert(
                    $customerData['customer_email'],
                    $purchaseNumber,
                    $fechaArribo ?? 'N/A',
                    $puertoIntermedio ?? 'N/A'
                );

                if ($emailSent) {
                    error_log("✅ Email de tránsito enviado a: {$customerData['customer_email']}");
                } else {
                    error_log("⚠️ No se pudo enviar el email de tránsito");
                }
            } else {
                error_log("⚠️ No se pudo enviar email: datos de cliente no encontrados para orden {$orderNumber}");
            }
        } catch (Exception $e) {
            error_log("❌ Error enviando email de tránsito: " . $e->getMessage());
        }
    }

    /**
     * Actualiza campos de shipping
     */
    private function updateShipping(
        string $orderNumber,
        ?string $fechaArribo,
        ?string $puertoIntermedio
    ): void {
        $fields = [];
        $params = [':order_number' => $orderNumber];

        if ($fechaArribo !== null) {
            $fields['fecha_arribo'] = ':fecha_arribo';
            $params[':fecha_arribo'] = $fechaArribo;
        }

        if ($puertoIntermedio !== null) {
            $fields['puerto_intermedio'] = ':puerto_intermedio';
            $params[':puerto_intermedio'] = $puertoIntermedio;
        }

        if (!empty($fields)) {
            $setClauses = [];
            foreach ($fields as $column => $placeholder) {
                $setClauses[] = "$column = $placeholder";
            }
            $setClause = implode(', ', $setClauses);

            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}shipping SET {$setClause} WHERE order_number = :order_number"
            );
            $updateStmt->execute($params);
        }
    }

    /**
     * Crea o actualiza pack
     */
    private function upsertPack(
        string $orderNumber,
        string $packFile,
        ?string $miniatura
    ): void {
        $checkStmt = $this->db->prepare(
            "SELECT id FROM {$this->prefix}pack WHERE order_number = :order_number LIMIT 1"
        );
        $checkStmt->execute([':order_number' => $orderNumber]);
        $exists = $checkStmt->fetch();

        if ($exists) {
            // Actualizar
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}pack 
                 SET pack = :pack, nomb_pack = 'PL', miniatura = :miniatura 
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
                "INSERT INTO {$this->prefix}pack (order_number, pack, nomb_pack, miniatura)
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
     * Sube archivo de pack
     */
    public function uploadPackFile(string $tempFilePath): array
    {
        return $this->uploadFile($tempFilePath, '/images/pack/');
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
     * Elimina pack (registro y archivos)
     */
    public function deletePack(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT pack, miniatura FROM {$this->prefix}pack WHERE order_number = :order_number LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $packData = $stmt->fetch();

            if (!$packData) {
                return ['success' => false, 'message' => 'Pack no encontrado'];
            }

            // Eliminar archivos físicos
            $this->deletePhysicalFile($packData['pack']);
            $this->deletePhysicalFile($packData['miniatura']);

            // Eliminar registro
            $deleteStmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}pack WHERE order_number = :order_number"
            );
            $deleteStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Pack eliminado'];
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
     * Lista información completa de tránsito
     */
    public function listTransitoInfo(string $orderNumber): array
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

            // Obtener shipping (fecha_arribo y puerto_intermedio) - usando order_number efectivo
            $shippingStmt = $this->db->prepare(
                "SELECT fecha_arribo, puerto_intermedio 
             FROM {$this->prefix}shipping 
             WHERE order_number = :order_number 
             LIMIT 1"
            );
            $shippingStmt->execute([':order_number' => $effectiveOrderNumber]);
            $shipping = $shippingStmt->fetch();

            // Obtener pack - usando order_number efectivo
            $packStmt = $this->db->prepare(
                "SELECT * FROM {$this->prefix}pack WHERE order_number = :order_number LIMIT 1"
            );
            $packStmt->execute([':order_number' => $effectiveOrderNumber]);
            $pack = $packStmt->fetch();

            if ($pack) {
                $pack['pack_url'] = !empty($pack['pack']) ? $baseUrl . $pack['pack'] : null;
                $pack['miniatura_url'] = !empty($pack['miniatura']) ? $baseUrl . $pack['miniatura'] : null;
            }

            return [
                'http_code' => 200,
                'success' => true,
                'message' => 'Información de tránsito obtenida',
                'original_order_number' => $orderNumber,
                'effective_order_number' => $effectiveOrderNumber,
                'is_child_order' => (!empty($parentCheck['order_parent_id'])),
                'parent_order_id' => $parentCheck['order_parent_id'] ?? null,
                'data' => [
                    'shipping' => $shipping,
                    'pack' => $pack
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


    /**
     * Cambia estado de transito a pagado
     */
    public function changeStatusToPagado(string $orderNumber): array
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

            if ($order['order_status'] !== 'transito') {
                return [
                    'success' => false,
                    'message' => 'El estado actual no es "transito". Estado actual: ' . $order['order_status']
                ];
            }

            // Actualizar estado
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}hikashop_order 
             SET order_status = :new_status, order_modified = NOW() 
             WHERE order_number = :order_number"
            );
            $updateStmt->execute([
                ':new_status' => 'pagado',
                ':order_number' => $orderNumber
            ]);

            error_log("✅ Estado cambiado de transito a pagado para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE CAMBIO DE ESTADO
            $this->sendStatusChangeEmail($orderNumber, 'pagado');

            return [
                'success' => true,
                'message' => 'Estado cambiado a pagado',
                'new_status' => 'pagado'
            ];
        } catch (Exception $e) {
            error_log("❌ Error en changeStatusToPagado: " . $e->getMessage());
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
            'pending' => 'Pendiente',
            'shipped' => 'Enviado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado'
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }
}
