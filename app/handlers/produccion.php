<?php
require_once __DIR__ . '/handler-base.php';

class HandlerProduccion extends HandlerBase
{
    /**
     * Procesa una orden en estado "produccion"
     */
    public function process(array $orderData, object $payload): array
    {
        try {
            $orderNumber = $orderData['order_number'];

            // Listar información actualizada
            return $this->listProduccionInfo($orderNumber);
        } catch (Exception $e) {
            error_log("Error en produccion handler: " . $e->getMessage());
            return [
                'http_code' => 500,
                'success' => false,
                'error' => 'Error procesando orden en producción'
            ];
        }
    }
    /**
     * Sube y guarda un archivo PDF en /images/preformar/
     * Retorna URL para guardar en BD
     */
    public function uploadAndSaveFile(string $tempFilePath, string $type = 'preforma'): array
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

            // ✅ Rutas separadas: física y web (preformar en lugar de orders)
            $webPath = '/images/preformar/';  // Lo que va en BD
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
     * Crea o actualiza registro en SAP y Producción
     */
    public function createSAP(
        string $orderNumber,
        ?string $numeroSap = null,
        ?string $numeroProforma = null,
        ?string $numeroProformaMwt = null,
        ?int $productId = null,
        ?string $fechaInicio = null,
        ?string $fechaFinal = null,
        ?string $preformaFile = null,
        ?string $preformaMwtFile = null,
        ?string $miniaturaFile = null,
        ?string $miniaturaMwtFile = null
    ): array {
        try {
            $this->db->beginTransaction();

            // 1. Obtener number_purchase desde preforma
            $purchaseStmt = $this->db->prepare(
                "SELECT number_purchase FROM {$this->prefix}preforma WHERE order_number = :order_number LIMIT 1"
            );
            $purchaseStmt->execute([':order_number' => $orderNumber]);
            $purchaseData = $purchaseStmt->fetch();
            $numberPurchase = $purchaseData['number_purchase'] ?? null;

            // 2. Obtener product_ids si no viene especificado
            $productIds = [];
            if ($productId === null) {
                $productIds = $this->getUniqueProductParents($orderNumber);
            } else {
                $productIds = [$productId];
            }

            // 3. Procesar cada product_id (crear/actualizar registro SAP)
            $sapRecords = [];
            foreach ($productIds as $pid) {
                $sapRecord = $this->upsertSAPRecord(
                    $orderNumber,
                    $numeroSap,
                    $numeroProforma,
                    $numberPurchase,
                    $pid,
                    $numeroProformaMwt,
                    $preformaFile,
                    $preformaMwtFile,
                    $miniaturaFile,
                    $miniaturaMwtFile
                );
                $sapRecords[] = $sapRecord;
            }

            // 4. Crear/actualizar registro en produccion
            $produccionRecord = $this->upsertProduccionRecord(
                $orderNumber,
                $fechaInicio,
                $fechaFinal
            );

            $this->db->commit();

            error_log("✅ SAP y Producción actualizados para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE PRODUCCIÓN
            $this->sendProduccionEmail(
                $orderNumber,
                $numeroSap,
                $numeroProforma,
                $fechaInicio,
                $fechaFinal
            );

            return [
                'success' => true,
                'message' => 'Registros SAP y Producción creados/actualizados',
                'data' => [
                    'sap_records' => $sapRecords,
                    'produccion' => $produccionRecord
                ]
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("❌ Error en createSAP: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear/actualizar SAP: ' . $e->getMessage()
            ];
        }
    }
    /**
     * Obtiene product_parent_id únicos de la orden
     */
    private function getUniqueProductParents(string $orderNumber): array
    {
        try {
            // Obtener order_id
            $orderStmt = $this->db->prepare(
                "SELECT order_id FROM {$this->prefix}hikashop_order WHERE order_number = :order_number LIMIT 1"
            );
            $orderStmt->execute([':order_number' => $orderNumber]);
            $orderData = $orderStmt->fetch();

            if (!$orderData) {
                return [];
            }

            $orderId = $orderData['order_id'];

            // Obtener productos de la orden con sus parents
            $stmt = $this->db->prepare(
                "SELECT DISTINCT COALESCE(p.product_parent_id, op.product_id) as parent_id
                 FROM {$this->prefix}hikashop_order_product op
                 LEFT JOIN {$this->prefix}hikashop_product p ON op.product_id = p.product_id
                 WHERE op.order_id = :order_id"
            );
            $stmt->execute([':order_id' => $orderId]);
            $results = $stmt->fetchAll();

            return array_column($results, 'parent_id');
        } catch (Exception $e) {
            error_log("Error obteniendo product parents: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Inserta o actualiza registro en tabla SAP
     */
    private function upsertSAPRecord(
        string $orderNumber,
        ?string $numeroSap,
        ?string $numeroProforma,
        ?string $numberPurchase,
        ?int $productId,
        ?string $preformaMwt,
        ?string $preformaFile,
        ?string $preformaMwtFile,
        ?string $miniaturaFile,
        ?string $miniaturaMwtFile
    ): array {
        // Verificar si existe
        $checkStmt = $this->db->prepare(
            "SELECT id FROM {$this->prefix}sap WHERE order_number = :order_number AND product_id = :product_id LIMIT 1"
        );
        $checkStmt->execute([':order_number' => $orderNumber, ':product_id' => $productId]);
        $exists = $checkStmt->fetch();

        if ($exists) {
            // Actualizar
            $updateFields = [];
            $params = [':order_number' => $orderNumber, ':product_id' => $productId];

            if ($numeroSap !== null) {
                $updateFields[] = 'number_sap = :number_sap';
                $params[':number_sap'] = $numeroSap;
            }
            if ($numeroProforma !== null) {
                $updateFields[] = 'number_preforma = :number_preforma';
                $params[':number_preforma'] = $numeroProforma;
            }
            if ($preformaMwt !== null) {
                $updateFields[] = 'number_preforma_mwt = :number_preforma_mwt';
                $params[':number_preforma_mwt'] = $preformaMwt;
            }
            if ($preformaFile !== null) {
                $updateFields[] = 'preforma = :preforma';
                $params[':preforma'] = $preformaFile;
            }
            if ($preformaMwtFile !== null) {
                $updateFields[] = 'preforma_mwt = :preforma_mwt';
                $params[':preforma_mwt'] = $preformaMwtFile;
            }
            if ($miniaturaFile !== null) {
                $updateFields[] = 'miniatura = :miniatura';
                $params[':miniatura'] = $miniaturaFile;
            }
            if ($miniaturaMwtFile !== null) {
                $updateFields[] = 'miniatura_mwt = :miniatura_mwt';
                $params[':miniatura_mwt'] = $miniaturaMwtFile;
            }

            if (!empty($updateFields)) {
                $updateQuery = "UPDATE {$this->prefix}sap SET " . implode(', ', $updateFields) .
                    " WHERE order_number = :order_number AND product_id = :product_id";
                $updateStmt = $this->db->prepare($updateQuery);
                $updateStmt->execute($params);
            }

            return ['id' => $exists['id'], 'action' => 'updated'];
        } else {
            // Insertar
            $insertStmt = $this->db->prepare(
                "INSERT INTO {$this->prefix}sap 
                (order_number, number_sap, number_preforma, number_purchase, product_id, number_preforma_mwt, 
                 preforma, preforma_mwt, miniatura, miniatura_mwt)
                VALUES (:order_number, :number_sap, :number_preforma, :number_purchase, :product_id, :number_preforma_mwt,
                        :preforma, :preforma_mwt, :miniatura, :miniatura_mwt)"
            );
            $insertStmt->execute([
                ':order_number' => $orderNumber,
                ':number_sap' => $numeroSap,
                ':number_preforma' => $numeroProforma,
                ':number_purchase' => $numberPurchase,
                ':product_id' => $productId,
                ':number_preforma_mwt' => $preformaMwt,
                ':preforma' => $preformaFile,
                ':preforma_mwt' => $preformaMwtFile,
                ':miniatura' => $miniaturaFile,
                ':miniatura_mwt' => $miniaturaMwtFile
            ]);

            return ['id' => $this->db->lastInsertId(), 'action' => 'created'];
        }
    }

    /**
     * Inserta o actualiza registro en tabla produccion
     */
    /**
     * Inserta o actualiza registro en tabla produccion
     */
    private function upsertProduccionRecord(
        string $orderNumber,
        ?string $fechaInicio,
        ?string $fechaFinal
    ): array {
        // Calcular status según fechas
        $status = 'No liberado';
        if ($fechaFinal !== null) {
            $currentDate = date('Y-m-d');
            $status = ($currentDate >= $fechaFinal) ? 'Liberado' : 'No liberado';
        }

        // Verificar si existe
        $checkStmt = $this->db->prepare(
            "SELECT id FROM {$this->prefix}produccion WHERE order_number = :order_number LIMIT 1"
        );
        $checkStmt->execute([':order_number' => $orderNumber]);
        $exists = $checkStmt->fetch();

        if ($exists) {
            // Actualizar (usando fechai y fechaf)
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}produccion 
             SET fechai = COALESCE(:fechai, fechai),
                 fechaf = COALESCE(:fechaf, fechaf),
                 status = :status
             WHERE order_number = :order_number"
            );
            $updateStmt->execute([
                ':fechai' => $fechaInicio,
                ':fechaf' => $fechaFinal,
                ':status' => $status,
                ':order_number' => $orderNumber
            ]);

            return ['id' => $exists['id'], 'status' => $status, 'action' => 'updated'];
        } else {
            // Insertar (usando fechai y fechaf)
            $insertStmt = $this->db->prepare(
                "INSERT INTO {$this->prefix}produccion (order_number, fechai, fechaf, status)
             VALUES (:order_number, :fechai, :fechaf, :status)"
            );
            $insertStmt->execute([
                ':order_number' => $orderNumber,
                ':fechai' => $fechaInicio,
                ':fechaf' => $fechaFinal,
                ':status' => $status
            ]);

            return ['id' => $this->db->lastInsertId(), 'status' => $status, 'action' => 'created'];
        }
    }
    /**
     * Elimina archivos preforma y miniatura
     */
    public function deletePreformaFiles(string $orderNumber, ?int $productId = null): array
    {
        try {
            if ($productId === null) {
                // Eliminar de todos los productos
                return $this->deleteAllPreformaFiles($orderNumber);
            }

            $stmt = $this->db->prepare(
                "SELECT preforma, miniatura FROM {$this->prefix}sap 
             WHERE order_number = :order_number AND product_id = :product_id LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber, ':product_id' => $productId]);
            $sapData = $stmt->fetch();

            if (!$sapData) {
                return ['success' => false, 'message' => 'Registro SAP no encontrado'];
            }

            // Eliminar archivos físicos
            $this->deletePhysicalFile($sapData['preforma']);
            $this->deletePhysicalFile($sapData['miniatura']);

            // Actualizar BD
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}sap SET preforma = NULL, miniatura = NULL 
             WHERE order_number = :order_number AND product_id = :product_id"
            );
            $updateStmt->execute([':order_number' => $orderNumber, ':product_id' => $productId]);

            return ['success' => true, 'message' => 'Archivos preforma eliminados'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina archivos preforma_mwt y miniatura_mwt
     */
    public function deletePreformaMwtFiles(string $orderNumber, ?int $productId = null): array
    {
        try {
            if ($productId === null) {
                // Eliminar de todos los productos
                return $this->deleteAllPreformaMwtFiles($orderNumber);
            }

            $stmt = $this->db->prepare(
                "SELECT preforma_mwt, miniatura_mwt FROM {$this->prefix}sap 
             WHERE order_number = :order_number AND product_id = :product_id LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber, ':product_id' => $productId]);
            $sapData = $stmt->fetch();

            if (!$sapData) {
                return ['success' => false, 'message' => 'Registro SAP no encontrado'];
            }

            // Eliminar archivos físicos
            $this->deletePhysicalFile($sapData['preforma_mwt']);
            $this->deletePhysicalFile($sapData['miniatura_mwt']);

            // Actualizar BD
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}sap SET preforma_mwt = NULL, miniatura_mwt = NULL 
             WHERE order_number = :order_number AND product_id = :product_id"
            );
            $updateStmt->execute([':order_number' => $orderNumber, ':product_id' => $productId]);

            return ['success' => true, 'message' => 'Archivos preforma MWT eliminados'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina preforma de TODOS los productos de la orden
     */
    private function deleteAllPreformaFiles(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT preforma, miniatura FROM {$this->prefix}sap WHERE order_number = :order_number"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $records = $stmt->fetchAll();

            foreach ($records as $record) {
                $this->deletePhysicalFile($record['preforma']);
                $this->deletePhysicalFile($record['miniatura']);
            }

            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}sap SET preforma = NULL, miniatura = NULL WHERE order_number = :order_number"
            );
            $updateStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Archivos preforma eliminados de todos los productos'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina preforma_mwt de TODOS los productos de la orden
     */
    private function deleteAllPreformaMwtFiles(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT preforma_mwt, miniatura_mwt FROM {$this->prefix}sap WHERE order_number = :order_number"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $records = $stmt->fetchAll();

            foreach ($records as $record) {
                $this->deletePhysicalFile($record['preforma_mwt']);
                $this->deletePhysicalFile($record['miniatura_mwt']);
            }

            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}sap SET preforma_mwt = NULL, miniatura_mwt = NULL WHERE order_number = :order_number"
            );
            $updateStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Archivos preforma MWT eliminados de todos los productos'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina completamente registros SAP, Producción y todos los archivos
     */
    public function deleteComplete(string $orderNumber): array
    {
        try {
            $this->db->beginTransaction();

            // Obtener todos los archivos de SAP
            $sapStmt = $this->db->prepare(
                "SELECT preforma, preforma_mwt, miniatura, miniatura_mwt FROM {$this->prefix}sap 
                 WHERE order_number = :order_number"
            );
            $sapStmt->execute([':order_number' => $orderNumber]);
            $sapRecords = $sapStmt->fetchAll();

            // Eliminar todos los archivos físicos
            foreach ($sapRecords as $record) {
                $this->deletePhysicalFile($record['preforma']);
                $this->deletePhysicalFile($record['preforma_mwt']);
                $this->deletePhysicalFile($record['miniatura']);
                $this->deletePhysicalFile($record['miniatura_mwt']);
            }

            // Eliminar registros de SAP
            $deleteSapStmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}sap WHERE order_number = :order_number"
            );
            $deleteSapStmt->execute([':order_number' => $orderNumber]);

            // Eliminar registro de Produccion
            $deleteProduccionStmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}produccion WHERE order_number = :order_number"
            );
            $deleteProduccionStmt->execute([':order_number' => $orderNumber]);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Registros y archivos eliminados completamente'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
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
     * Lista información completa de producción (actualiza status automáticamente)
     */
    /**
     * Lista información completa de producción (actualiza status automáticamente)
     */
    public function listProduccionInfo(string $orderNumber): array
    {
        try {
            // Actualizar status automáticamente
            $this->updateProduccionStatus($orderNumber);

            // Obtener datos de produccion
            $produccionStmt = $this->db->prepare(
                "SELECT * FROM {$this->prefix}produccion WHERE order_number = :order_number LIMIT 1"
            );
            $produccionStmt->execute([':order_number' => $orderNumber]);
            $produccion = $produccionStmt->fetch();

            // Obtener datos de SAP
            $sapStmt = $this->db->prepare(
                "SELECT * FROM {$this->prefix}sap WHERE order_number = :order_number"
            );
            $sapStmt->execute([':order_number' => $orderNumber]);
            $sapRecords = $sapStmt->fetchAll();

            // ✅ Construir URLs completas para cada registro SAP
            $baseUrl = 'https://mwt.one';

            foreach ($sapRecords as &$record) {
                // Preforma
                if (!empty($record['preforma'])) {
                    $record['preforma_url'] = $baseUrl . $record['preforma'];
                } else {
                    $record['preforma_url'] = null;
                }

                // Miniatura
                if (!empty($record['miniatura'])) {
                    $record['miniatura_url'] = $baseUrl . $record['miniatura'];
                } else {
                    $record['miniatura_url'] = null;
                }

                // Preforma MWT
                if (!empty($record['preforma_mwt'])) {
                    $record['preforma_mwt_url'] = $baseUrl . $record['preforma_mwt'];
                } else {
                    $record['preforma_mwt_url'] = null;
                }

                // Miniatura MWT
                if (!empty($record['miniatura_mwt'])) {
                    $record['miniatura_mwt_url'] = $baseUrl . $record['miniatura_mwt'];
                } else {
                    $record['miniatura_mwt_url'] = null;
                }
            }

            return [
                'http_code' => 200,
                'success' => true,
                'message' => 'Información de producción obtenida',
                'data' => [
                    'produccion' => $produccion,
                    'sap_records' => $sapRecords
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
     * Actualiza status de producción según fechas
     */
    /**
     * Actualiza status de producción según fechas
     */
    private function updateProduccionStatus(string $orderNumber): void
    {
        try {
            $currentDate = date('Y-m-d');

            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}produccion 
             SET status = CASE 
                 WHEN :current_date >= fechaf THEN 'Liberado'
                 ELSE 'No liberado'
             END
             WHERE order_number = :order_number AND fechaf IS NOT NULL"
            );
            $updateStmt->execute([
                ':current_date' => $currentDate,
                ':order_number' => $orderNumber
            ]);
        } catch (Exception $e) {
            error_log("Error actualizando status: " . $e->getMessage());
        }
    }


    /**
     * Cambia estado de producción a preparación
     */
    public function changeStatusToPreparacion(string $orderNumber): array
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

            if ($order['order_status'] !== 'produccion') {
                return [
                    'success' => false,
                    'message' => 'El estado actual no es "produccion". Estado actual: ' . $order['order_status']
                ];
            }

            // Actualizar estado
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}hikashop_order 
             SET order_status = :new_status, order_modified = NOW() 
             WHERE order_number = :order_number"
            );
            $updateStmt->execute([
                ':new_status' => 'preparacion',
                ':order_number' => $orderNumber
            ]);

            error_log("✅ Estado cambiado de produccion a preparacion para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE CAMBIO DE ESTADO
            $this->sendStatusChangeEmail($orderNumber, 'preparacion');

            return [
                'success' => true,
                'message' => 'Estado cambiado a preparación',
                'new_status' => 'preparacion'
            ];
        } catch (Exception $e) {
            error_log("❌ Error en changeStatusToPreparacion: " . $e->getMessage());
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
            'pending' => 'Pendiente',
            'shipped' => 'Enviado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado'
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }

    /**
     * Método auxiliar para enviar email de producción
     */
    private function sendProduccionEmail(
        string $orderNumber,
        ?string $numeroSap,
        ?string $numeroProforma,
        ?string $fechaInicio,
        ?string $fechaFinal
    ): void {
        try {
            require_once __DIR__ . '/../utils/emailer.php';
            $emailer = new EmailSender();

            // Obtener datos del cliente y purchase number
            $customerData = $emailer->getCustomerDataForEmail($this->db, $this->prefix, $orderNumber);

            if ($customerData && !empty($customerData['customer_email'])) {
                // Usar number_purchase si existe
                $purchaseNumber = $customerData['number_purchase'] ?? $orderNumber;

                $emailSent = $emailer->sendProduccionAlert(
                    $customerData['customer_email'],
                    $purchaseNumber,
                    $numeroSap ?? 'N/A',
                    $numeroProforma ?? 'N/A',
                    $fechaInicio ?? 'N/A',
                    $fechaFinal ?? 'N/A'
                );

                if ($emailSent) {
                    error_log("✅ Email de producción enviado a: {$customerData['customer_email']}");
                } else {
                    error_log("⚠️ No se pudo enviar el email de producción");
                }
            } else {
                error_log("⚠️ No se pudo enviar email: datos de cliente no encontrados para orden {$orderNumber}");
            }
        } catch (Exception $e) {
            error_log("❌ Error enviando email de producción: " . $e->getMessage());
        }
    }
}
