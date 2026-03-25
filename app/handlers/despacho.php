<?php
require_once __DIR__ . '/handler-base.php';

class HandlerDespacho extends HandlerBase
{
    /**
     * Procesa una orden en estado "despacho"
     */
    public function process(array $orderData, object $payload): array
    {
        try {
            $orderNumber = $orderData['order_number'];

            // Listar información actualizada
            return $this->listDespachoInfo($orderNumber);
        } catch (Exception $e) {
            error_log("Error en despacho handler: " . $e->getMessage());
            return [
                'http_code' => 500,
                'success' => false,
                'error' => 'Error procesando orden en despacho'
            ];
        }
    }

    /**
     * Crea o actualiza información de despacho
     */
    public function crearDespacho(
        string $orderNumber,
        ?string $nomber = null,
        ?string $numberGuia = null,
        ?string $nomberDespacho = null,
        ?string $nomberArribo = null,
        ?string $guiaFile = null,
        ?string $guiaMiniatura = null,
        ?string $adiccional = null,
        ?string $fechas = null,
        ?string $link = null,
        ?string $numberInvoice = null,
        ?string $invoiceFile = null,
        ?string $invoiceMiniatura = null,
        ?string $certificadoFile = null,
        ?string $certificadoMiniatura = null,
        ?string $numberInvoiceMwt = null,
        ?string $invoiceMwtFile = null,
        ?string $invoiceMwtMiniatura = null
    ): array {
        try {
            $this->db->beginTransaction();

            // 1. Crear/actualizar shipping
            $this->upsertShipping(
                $orderNumber,
                $nomber,
                $numberGuia,
                $nomberDespacho,
                $nomberArribo,
                $guiaFile,
                $guiaMiniatura,
                $adiccional,
                $fechas,
                $link
            );

            // 2. Crear/actualizar invoice
            $this->upsertInvoice(
                $orderNumber,
                $numberInvoice,
                $invoiceFile,
                $invoiceMiniatura,
                $certificadoFile,
                $certificadoMiniatura,
                $numberInvoiceMwt,
                $invoiceMwtFile,
                $invoiceMwtMiniatura
            );

            $this->db->commit();

            error_log("✅ Despacho actualizado para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE DESPACHO
            $this->sendDespachoEmail(
                $orderNumber,
                $nomber,
                $numberGuia,
                $nomberDespacho,
                $nomberArribo,
                $fechas,
                $numberInvoice,
                $adiccional
            );

            return [
                'success' => true,
                'message' => 'Información de despacho actualizada'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("❌ Error en crearDespacho: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear despacho: ' . $e->getMessage()
            ];
        }
    }
    /**
     * Método auxiliar para enviar email de despacho
     */
    private function sendDespachoEmail(
        string $orderNumber,
        ?string $nomber,
        ?string $numberGuia,
        ?string $nomberDespacho,
        ?string $nomberArribo,
        ?string $fechas,
        ?string $numberInvoice,
        ?string $adiccional
    ): void {
        try {
            require_once __DIR__ . '/../utils/emailer.php';
            $emailer = new EmailSender();

            // Obtener datos del cliente y purchase number
            $customerData = $emailer->getCustomerDataForEmail($this->db, $this->prefix, $orderNumber);

            if ($customerData && !empty($customerData['customer_email'])) {
                // Usar number_purchase si existe
                $purchaseNumber = $customerData['number_purchase'] ?? $orderNumber;

                $emailSent = $emailer->sendDespachoAlert(
                    $customerData['customer_email'],
                    $purchaseNumber,
                    $nomber ?? 'N/A',
                    $numberGuia ?? 'N/A',
                    $nomberDespacho ?? 'N/A',
                    $nomberArribo ?? 'N/A',
                    $fechas ?? 'N/A',
                    $numberInvoice ?? 'N/A',
                    $adiccional ?? 'N/A'
                );

                if ($emailSent) {
                    error_log("✅ Email de despacho enviado a: {$customerData['customer_email']}");
                } else {
                    error_log("⚠️ No se pudo enviar el email de despacho");
                }
            } else {
                error_log("⚠️ No se pudo enviar email: datos de cliente no encontrados para orden {$orderNumber}");
            }
        } catch (Exception $e) {
            error_log("❌ Error enviando email de despacho: " . $e->getMessage());
        }
    }


    /**
     * Crea o actualiza shipping
     */
    private function upsertShipping(
        string $orderNumber,
        ?string $nomber,
        ?string $numberGuia,
        ?string $nomberDespacho,
        ?string $nomberArribo,
        ?string $guiaFile,
        ?string $guiaMiniatura,
        ?string $adiccional,
        ?string $fechas,
        ?string $link
    ): void {
        $checkStmt = $this->db->prepare(
            "SELECT id FROM {$this->prefix}shipping WHERE order_number = :order_number LIMIT 1"
        );
        $checkStmt->execute([':order_number' => $orderNumber]);
        $exists = $checkStmt->fetch();

        $fields = [];
        $params = [':order_number' => $orderNumber];

        if ($nomber !== null) {
            $fields['nomber'] = ':nomber';
            $params[':nomber'] = $nomber;
        }
        if ($numberGuia !== null) {
            $fields['number_guia'] = ':number_guia';
            $params[':number_guia'] = $numberGuia;
        }
        if ($nomberDespacho !== null) {
            $fields['nomber_despacho'] = ':nomber_despacho';
            $params[':nomber_despacho'] = $nomberDespacho;
        }
        if ($nomberArribo !== null) {
            $fields['nomber_arribo'] = ':nomber_arribo';
            $params[':nomber_arribo'] = $nomberArribo;
        }
        if ($guiaFile !== null) {
            $fields['guia'] = ':guia';
            $params[':guia'] = $guiaFile;
        }
        if ($guiaMiniatura !== null) {
            $fields['miniatura'] = ':miniatura';
            $params[':miniatura'] = $guiaMiniatura;
        }
        if ($adiccional !== null) {
            $fields['adiccional'] = ':adiccional';
            $params[':adiccional'] = $adiccional;
        }
        if ($fechas !== null) {
            $fields['fechas'] = ':fechas';
            $params[':fechas'] = $fechas;
        }
        if ($link !== null) {
            $fields['link'] = ':link';
            $params[':link'] = $link;
        }

        if ($exists) {
            // Actualizar
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
        } else {
            // Insertar
            $fields['order_number'] = ':order_number';
            $columns = implode(', ', array_keys($fields));
            $placeholders = implode(', ', $fields);

            $insertStmt = $this->db->prepare(
                "INSERT INTO {$this->prefix}shipping ({$columns}) VALUES ({$placeholders})"
            );
            $insertStmt->execute($params);
        }
    }

    /**
     * Crea o actualiza invoice
     */
    private function upsertInvoice(
        string $orderNumber,
        ?string $numberInvoice,
        ?string $invoiceFile,
        ?string $invoiceMiniatura,
        ?string $certificadoFile,
        ?string $certificadoMiniatura,
        ?string $numberInvoiceMwt,
        ?string $invoiceMwtFile,
        ?string $invoiceMwtMiniatura
    ): void {
        $checkStmt = $this->db->prepare(
            "SELECT id FROM {$this->prefix}invoice WHERE order_number = :order_number LIMIT 1"
        );
        $checkStmt->execute([':order_number' => $orderNumber]);
        $exists = $checkStmt->fetch();

        $fields = [];
        $params = [':order_number' => $orderNumber];

        if ($numberInvoice !== null) {
            $fields['number_invoice'] = ':number_invoice';
            $params[':number_invoice'] = $numberInvoice;
        }
        if ($invoiceFile !== null) {
            $fields['invoice'] = ':invoice';
            $params[':invoice'] = $invoiceFile;
        }
        if ($invoiceMiniatura !== null) {
            $fields['miniatura1'] = ':miniatura1';
            $params[':miniatura1'] = $invoiceMiniatura;
        }
        if ($certificadoFile !== null) {
            $fields['certificado'] = ':certificado';
            $params[':certificado'] = $certificadoFile;
        }
        if ($certificadoMiniatura !== null) {
            $fields['miniatura2'] = ':miniatura2';
            $params[':miniatura2'] = $certificadoMiniatura;
        }
        if ($numberInvoiceMwt !== null) {
            $fields['number_invoice_mwt'] = ':number_invoice_mwt';
            $params[':number_invoice_mwt'] = $numberInvoiceMwt;
        }
        if ($invoiceMwtFile !== null) {
            $fields['invoice_mwt'] = ':invoice_mwt';
            $params[':invoice_mwt'] = $invoiceMwtFile;
        }
        if ($invoiceMwtMiniatura !== null) {
            $fields['miniaturafmwt'] = ':miniaturafmwt';
            $params[':miniaturafmwt'] = $invoiceMwtMiniatura;
        }

        if ($exists) {
            // Actualizar
            if (!empty($fields)) {
                // ✅ Construir SET clause manualmente (compatible con PHP 7.3+)
                $setClauses = [];
                foreach ($fields as $column => $placeholder) {
                    $setClauses[] = "$column = $placeholder";
                }
                $setClause = implode(', ', $setClauses);

                $updateStmt = $this->db->prepare(
                    "UPDATE {$this->prefix}invoice SET {$setClause} WHERE order_number = :order_number"
                );
                $updateStmt->execute($params);
            }
        } else {
            // Insertar
            $fields['order_number'] = ':order_number';
            $columns = implode(', ', array_keys($fields));
            $placeholders = implode(', ', $fields);

            $insertStmt = $this->db->prepare(
                "INSERT INTO {$this->prefix}invoice ({$columns}) VALUES ({$placeholders})"
            );
            $insertStmt->execute($params);
        }
    }

    /**
     * Sube archivo de guía
     */
    public function uploadGuiaFile(string $tempFilePath): array
    {
        return $this->uploadFile($tempFilePath, '/images/guias/');
    }

    /**
     * Sube archivo de invoice
     */
    public function uploadInvoiceFile(string $tempFilePath): array
    {
        return $this->uploadFile($tempFilePath, '/images/invoice/', 'pdf_invoice_');
    }

    /**
     * Sube archivo de certificado
     */
    public function uploadCertificadoFile(string $tempFilePath): array
    {
        return $this->uploadFile($tempFilePath, '/images/certificado/', 'pdf_certificado_');
    }

    /**
     * Método genérico para subir archivos
     */
    private function uploadFile(string $tempFilePath, string $webPath, string $prefix = 'pdf_'): array
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

            $filename = $prefix . time() . '.pdf';
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
     * Elimina invoice y miniatura1
     */
    public function deleteInvoice(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT invoice, miniatura1 FROM {$this->prefix}invoice WHERE order_number = :order_number LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $data = $stmt->fetch();

            if (!$data) {
                return ['success' => false, 'message' => 'Invoice no encontrado'];
            }

            $this->deletePhysicalFile($data['invoice']);
            $this->deletePhysicalFile($data['miniatura1']);

            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}invoice SET invoice = NULL, miniatura1 = NULL WHERE order_number = :order_number"
            );
            $updateStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Invoice eliminado'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina invoice_mwt y miniaturafmwt
     */
    public function deleteInvoiceMwt(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT invoice_mwt, miniaturafmwt FROM {$this->prefix}invoice WHERE order_number = :order_number LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $data = $stmt->fetch();

            if (!$data) {
                return ['success' => false, 'message' => 'Invoice MWT no encontrado'];
            }

            $this->deletePhysicalFile($data['invoice_mwt']);
            $this->deletePhysicalFile($data['miniaturafmwt']);

            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}invoice SET invoice_mwt = NULL, miniaturafmwt = NULL WHERE order_number = :order_number"
            );
            $updateStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Invoice MWT eliminado'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina certificado y miniatura2
     */
    public function deleteCertificado(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT certificado, miniatura2 FROM {$this->prefix}invoice WHERE order_number = :order_number LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $data = $stmt->fetch();

            if (!$data) {
                return ['success' => false, 'message' => 'Certificado no encontrado'];
            }

            $this->deletePhysicalFile($data['certificado']);
            $this->deletePhysicalFile($data['miniatura2']);

            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}invoice SET certificado = NULL, miniatura2 = NULL WHERE order_number = :order_number"
            );
            $updateStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Certificado eliminado'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina guía y miniatura
     */
    public function deleteGuia(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT guia, miniatura FROM {$this->prefix}shipping WHERE order_number = :order_number LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $data = $stmt->fetch();

            if (!$data) {
                return ['success' => false, 'message' => 'Guía no encontrada'];
            }

            $this->deletePhysicalFile($data['guia']);
            $this->deletePhysicalFile($data['miniatura']);

            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}shipping SET guia = NULL, miniatura = NULL WHERE order_number = :order_number"
            );
            $updateStmt->execute([':order_number' => $orderNumber]);

            return ['success' => true, 'message' => 'Guía eliminada'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina completamente registros de shipping e invoice con todos los archivos
     */
    public function deleteComplete(string $orderNumber): array
    {
        try {
            $this->db->beginTransaction();

            // Obtener archivos de invoice
            $invoiceStmt = $this->db->prepare(
                "SELECT invoice, miniatura1, certificado, miniatura2, invoice_mwt, miniaturafmwt 
                 FROM {$this->prefix}invoice WHERE order_number = :order_number LIMIT 1"
            );
            $invoiceStmt->execute([':order_number' => $orderNumber]);
            $invoiceData = $invoiceStmt->fetch();

            if ($invoiceData) {
                $this->deletePhysicalFile($invoiceData['invoice']);
                $this->deletePhysicalFile($invoiceData['miniatura1']);
                $this->deletePhysicalFile($invoiceData['certificado']);
                $this->deletePhysicalFile($invoiceData['miniatura2']);
                $this->deletePhysicalFile($invoiceData['invoice_mwt']);
                $this->deletePhysicalFile($invoiceData['miniaturafmwt']);
            }

            // Obtener archivos de shipping
            $shippingStmt = $this->db->prepare(
                "SELECT guia, miniatura FROM {$this->prefix}shipping WHERE order_number = :order_number LIMIT 1"
            );
            $shippingStmt->execute([':order_number' => $orderNumber]);
            $shippingData = $shippingStmt->fetch();

            if ($shippingData) {
                $this->deletePhysicalFile($shippingData['guia']);
                $this->deletePhysicalFile($shippingData['miniatura']);
            }

            // Eliminar registros
            $deleteInvoiceStmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}invoice WHERE order_number = :order_number"
            );
            $deleteInvoiceStmt->execute([':order_number' => $orderNumber]);

            $deleteShippingStmt = $this->db->prepare(
                "DELETE FROM {$this->prefix}shipping WHERE order_number = :order_number"
            );
            $deleteShippingStmt->execute([':order_number' => $orderNumber]);

            $this->db->commit();

            return ['success' => true, 'message' => 'Registros y archivos eliminados completamente'];
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
     * Lista información completa de despacho con fecha_pago calculada
     */
    public function listDespachoInfo(string $orderNumber): array
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

            // Obtener shipping (usando order_number efectivo)
            $shippingStmt = $this->db->prepare(
                "SELECT * FROM {$this->prefix}shipping WHERE order_number = :order_number LIMIT 1"
            );
            $shippingStmt->execute([':order_number' => $effectiveOrderNumber]);
            $shipping = $shippingStmt->fetch();

            if ($shipping) {
                $shipping['guia_url'] = !empty($shipping['guia']) ? $baseUrl . $shipping['guia'] : null;
                $shipping['miniatura_url'] = !empty($shipping['miniatura']) ? $baseUrl . $shipping['miniatura'] : null;
            }

            // Obtener invoice (usando order_number efectivo)
            $invoiceStmt = $this->db->prepare(
                "SELECT * FROM {$this->prefix}invoice WHERE order_number = :order_number LIMIT 1"
            );
            $invoiceStmt->execute([':order_number' => $effectiveOrderNumber]);
            $invoice = $invoiceStmt->fetch();

            if ($invoice) {
                $invoice['invoice_url'] = !empty($invoice['invoice']) ? $baseUrl . $invoice['invoice'] : null;
                $invoice['miniatura1_url'] = !empty($invoice['miniatura1']) ? $baseUrl . $invoice['miniatura1'] : null;
                $invoice['certificado_url'] = !empty($invoice['certificado']) ? $baseUrl . $invoice['certificado'] : null;
                $invoice['miniatura2_url'] = !empty($invoice['miniatura2']) ? $baseUrl . $invoice['miniatura2'] : null;
                $invoice['invoice_mwt_url'] = !empty($invoice['invoice_mwt']) ? $baseUrl . $invoice['invoice_mwt'] : null;
                $invoice['miniaturafmwt_url'] = !empty($invoice['miniaturafmwt']) ? $baseUrl . $invoice['miniaturafmwt'] : null;
            }

            // Calcular fecha_pago (usando order_number efectivo)
            $fechaPago = null;
            if ($shipping && !empty($shipping['fechas'])) {
                // Obtener customer_payment_time (usando order_number efectivo)
                $customerStmt = $this->db->prepare(
                    "SELECT c.customer_payment_time 
                 FROM {$this->prefix}hikashop_order h
                 JOIN {$this->prefix}customer c ON h.customer = c.customer_id
                 WHERE h.order_number = :order_number LIMIT 1"
                );
                $customerStmt->execute([':order_number' => $effectiveOrderNumber]);
                $customerData = $customerStmt->fetch();

                if ($customerData && !empty($customerData['customer_payment_time'])) {
                    $paymentDays = (int)$customerData['customer_payment_time'];
                    $fechaBase = new DateTime($shipping['fechas']);
                    $fechaBase->modify("+{$paymentDays} days");
                    $fechaPago = $fechaBase->format('Y-m-d');
                }
            }

            return [
                'http_code' => 200,
                'success' => true,
                'message' => 'Información de despacho obtenida',
                'original_order_number' => $orderNumber,
                'effective_order_number' => $effectiveOrderNumber,
                'is_child_order' => (!empty($parentCheck['order_parent_id'])),
                'parent_order_id' => $parentCheck['order_parent_id'] ?? null,
                'data' => [
                    'shipping' => $shipping,
                    'invoice' => $invoice,
                    'fecha_pago' => $fechaPago
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
     * Cambia estado de despacho a transito
     */
    public function changeStatusToTransito(string $orderNumber): array
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

            if ($order['order_status'] !== 'despacho') {
                return [
                    'success' => false,
                    'message' => 'El estado actual no es "despacho". Estado actual: ' . $order['order_status']
                ];
            }

            // Actualizar estado
            $updateStmt = $this->db->prepare(
                "UPDATE {$this->prefix}hikashop_order 
             SET order_status = :new_status, order_modified = NOW() 
             WHERE order_number = :order_number"
            );
            $updateStmt->execute([
                ':new_status' => 'transito',
                ':order_number' => $orderNumber
            ]);

            error_log("✅ Estado cambiado de despacho a transito para orden: {$orderNumber}");

            // ✅ ENVIAR EMAIL DE CAMBIO DE ESTADO
            $this->sendStatusChangeEmail($orderNumber, 'transito');

            return [
                'success' => true,
                'message' => 'Estado cambiado a tránsito',
                'new_status' => 'transito'
            ];
        } catch (Exception $e) {
            error_log("❌ Error en changeStatusToTransito: " . $e->getMessage());
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
            'pending' => 'Pendiente',
            'shipped' => 'Enviado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado'
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }
}
