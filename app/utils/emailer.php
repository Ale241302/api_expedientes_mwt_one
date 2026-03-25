<?php
class EmailSender
{
    private $templatePath;
    private $fromEmail;
    private $fromName;
    private $bccEmails; // ✅ Nuevos BCC

    public function __construct(string $templatePath = null)
    {
        $this->templatePath = $templatePath ?? __DIR__ . '/../../email/';
        $this->fromEmail = '506@muitowork.com';
        $this->fromName = 'Muito Work';
        $this->bccEmails = ['alejandro@muitowork.com', 'jorjecasanova@gmail.com']; // ✅ BCC fijos
    }

    /**
     * Envía email de cambio de estado usando alerta_status.html
     */
    public function sendStatusChangeAlert(string $toEmail, string $purchaseNumber, string $newStatus): bool
    {
        try {
            $templateFile = $this->templatePath . 'alerta_status.html';

            if (!file_exists($templateFile)) {
                error_log("❌ Template no encontrado: {$templateFile}");
                return false;
            }

            $htmlContent = file_get_contents($templateFile);

            $replacements = [
                '[purchase_number]' => $purchaseNumber,
                '[n_status]' => $newStatus
            ];

            foreach ($replacements as $placeholder => $value) {
                $htmlContent = str_replace($placeholder, $value, $htmlContent);
            }

            return $this->sendEmail(
                $toEmail,
                "Actualización de pedido {$purchaseNumber}",
                $htmlContent
            );
        } catch (Exception $e) {
            error_log("❌ Error enviando email de estado: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía email de creación/actualización de preforma usando alerta_status_creacion.html
     */
    public function sendPreformaCreationAlert(
        string $toEmail,
        string $purchaseNumber,
        string $customerName,
        string $operadorName
    ): bool {
        try {
            $templateFile = $this->templatePath . 'alerta_status_creacion.html';

            if (!file_exists($templateFile)) {
                error_log("❌ Template no encontrado: {$templateFile}");
                return false;
            }

            $htmlContent = file_get_contents($templateFile);

            $replacements = [
                '[purchase_number]' => $purchaseNumber,
                '[customer_name]' => $customerName,
                '[operado_mwt]' => $operadorName
            ];

            foreach ($replacements as $placeholder => $value) {
                $htmlContent = str_replace($placeholder, $value, $htmlContent);
            }

            return $this->sendEmail(
                $toEmail,
                "Pedido actualizado - {$purchaseNumber}",
                $htmlContent
            );
        } catch (Exception $e) {
            error_log("❌ Error enviando email de preforma: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Método privado para enviar el email con BCC
     */
    private function sendEmail(string $to, string $subject, string $htmlContent): bool
    {
        // ✅ Headers con BCC incluido
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
        $headers .= "From: {$this->fromName} <{$this->fromEmail}>" . "\r\n";
        $headers .= "Reply-To: {$this->fromEmail}" . "\r\n";

        // ✅ Añadir BCC (copias ocultas)
        if (!empty($this->bccEmails)) {
            $headers .= "Bcc: " . implode(', ', $this->bccEmails) . "\r\n";
        }

        $headers .= "X-Mailer: PHP/" . phpversion();

        $result = mail($to, $subject, $htmlContent, $headers);

        if ($result) {
            error_log("✅ Email enviado a: {$to} (con BCC a: " . implode(', ', $this->bccEmails) . ") - Asunto: {$subject}");
        } else {
            error_log("❌ Fallo al enviar email a: {$to}");
        }

        return $result;
    }

    /**
     * Obtiene datos del cliente para email incluyendo number_purchase de preforma
     */
    public function getCustomerDataForEmail(PDO $db, string $prefix, string $orderNumber): ?array
    {
        try {
            $stmt = $db->prepare(
                "SELECT c.customer_email, c.customer_name, c.customer_id, h.operado_mwt, p.number_purchase
             FROM {$prefix}hikashop_order h
             JOIN {$prefix}customer c ON h.customer = c.customer_id
             LEFT JOIN {$prefix}preforma p ON h.order_number = p.order_number
             WHERE h.order_number = :order_number 
             LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? $data : [];
        } catch (Exception $e) {
            error_log("Error obteniendo datos del cliente: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Envía email de actualización de producción usando alerta_status_produccion.html
     */
    public function sendProduccionAlert(
        string $toEmail,
        string $purchaseNumber,
        string $numeroSap,
        string $numeroProforma,
        string $fechaInicio,
        string $fechaFinal
    ): bool {
        try {
            $templateFile = $this->templatePath . 'alerta_status_produccion.html';

            if (!file_exists($templateFile)) {
                error_log("❌ Template no encontrado: {$templateFile}");
                return false;
            }

            $htmlContent = file_get_contents($templateFile);

            $replacements = [
                '[purchase_number]' => $purchaseNumber,
                '[numero_sap]' => $numeroSap,
                '[numero_proforma]' => $numeroProforma,
                '[fecha_inicio]' => $fechaInicio,
                '[fecha_final]' => $fechaFinal
            ];

            foreach ($replacements as $placeholder => $value) {
                $htmlContent = str_replace($placeholder, $value, $htmlContent);
            }

            return $this->sendEmail(
                $toEmail,
                "Información de producción - {$purchaseNumber}",
                $htmlContent
            );
        } catch (Exception $e) {
            error_log("❌ Error enviando email de producción: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Envía email de actualización de preparación usando alerta_status_preparacion.html
     */
    public function sendPreparacionAlert(
        string $toEmail,
        string $purchaseNumber,
        string $orderShippingMethod,
        string $operator,
        string $orderShippingPrice,
        string $incoterms,
        string $manejoPago
    ): bool {
        try {
            $templateFile = $this->templatePath . 'alerta_status_preparacion.html';

            if (!file_exists($templateFile)) {
                error_log("❌ Template no encontrado: {$templateFile}");
                return false;
            }

            $htmlContent = file_get_contents($templateFile);

            $replacements = [
                '[purchase_number]' => $purchaseNumber,
                '[orderShippingMethod]' => $orderShippingMethod,
                '[operator]' => $operator,
                '[orderShippingPrice]' => $orderShippingPrice,
                '[incotermscod]' => $incoterms,
                '[manejo_pago]' => $manejoPago
            ];

            foreach ($replacements as $placeholder => $value) {
                $htmlContent = str_replace($placeholder, $value, $htmlContent);
            }

            return $this->sendEmail(
                $toEmail,
                "Información de preparación - {$purchaseNumber}",
                $htmlContent
            );
        } catch (Exception $e) {
            error_log("❌ Error enviando email de preparación: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Envía email de actualización de despacho usando alerta_status_despacho.html
     */
    public function sendDespachoAlert(
        string $toEmail,
        string $purchaseNumber,
        string $nomber,
        string $numberGuia,
        string $nomberDespacho,
        string $nomberArribo,
        string $fechas,
        string $numberInvoice,
        string $adiccional
    ): bool {
        try {
            $templateFile = $this->templatePath . 'alerta_status_despacho.html';

            if (!file_exists($templateFile)) {
                error_log("❌ Template no encontrado: {$templateFile}");
                return false;
            }

            $htmlContent = file_get_contents($templateFile);

            $replacements = [
                '[purchase_number]' => $purchaseNumber,
                '[nomber]' => $nomber,
                '[numberGuia]' => $numberGuia,
                '[nomberDespacho]' => $nomberDespacho,
                '[nomberArribo]' => $nomberArribo,
                '[fechas]' => $fechas,
                '[numberInvoice]' => $numberInvoice,
                '[adiccional]' => $adiccional
            ];

            foreach ($replacements as $placeholder => $value) {
                $htmlContent = str_replace($placeholder, $value, $htmlContent);
            }

            return $this->sendEmail(
                $toEmail,
                "Información de despacho - {$purchaseNumber}",
                $htmlContent
            );
        } catch (Exception $e) {
            error_log("❌ Error enviando email de despacho: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Envía email de actualización de tránsito usando alerta_status_transito.html
     */
    public function sendTransitoAlert(
        string $toEmail,
        string $purchaseNumber,
        string $fechaArribo,
        string $puertoIntermedio
    ): bool {
        try {
            $templateFile = $this->templatePath . 'alerta_status_transito.html';

            if (!file_exists($templateFile)) {
                error_log("❌ Template no encontrado: {$templateFile}");
                return false;
            }

            $htmlContent = file_get_contents($templateFile);

            $replacements = [
                '[purchase_number]' => $purchaseNumber,
                '[fechaArribo]' => $fechaArribo,
                '[puertoIntermedio]' => $puertoIntermedio
            ];

            foreach ($replacements as $placeholder => $value) {
                $htmlContent = str_replace($placeholder, $value, $htmlContent);
            }

            return $this->sendEmail(
                $toEmail,
                "Información de tránsito - {$purchaseNumber}",
                $htmlContent
            );
        } catch (Exception $e) {
            error_log("❌ Error enviando email de tránsito: " . $e->getMessage());
            return false;
        }
    }
    public function sendPagadoAlert(
        string $toEmail,
        string $purchaseNumber,
        string $tipoPago,
        string $metodoPago,
        ?float $cantidadPago,
        ?string $fechaPago
    ): bool {
        try {
            $templateFile = $this->templatePath . 'alerta_status_pagado.html';

            if (!file_exists($templateFile)) {
                error_log("❌ Template no encontrado: {$templateFile}");
                return false;
            }

            $htmlContent = file_get_contents($templateFile);

            $replacements = [
                '[purchase_number]' => $purchaseNumber,
                '[status]' => $tipoPago,
                '[tipoPago]' => $tipoPago,
                '[metodoPago]' => $metodoPago,
                '[cantidad_pago]' => $cantidadPago !== null ? number_format($cantidadPago, 2) : 'N/A',
                '[fecha_pago]' => $fechaPago ?? 'N/A'
            ];

            foreach ($replacements as $placeholder => $value) {
                $htmlContent = str_replace($placeholder, $value, $htmlContent);
            }

            return $this->sendEmail(
                $toEmail,
                "Actualización de pago - {$purchaseNumber}",
                $htmlContent
            );
        } catch (Exception $e) {
            error_log("❌ Error enviando email de pago: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Envía email de alerta de cobranza usando alerta_cobranza.html
     */
    /**
     * Envía email de alerta de cobranza usando alerta_cobranza.html
     */
    public function sendCobranzaAlert(
        string $orderNumber,
        string $customerName,
        string $numberPurchase,
        string $numberSap,
        string $numberPreforma,
        string $fechaPago,
        string $montoPendiente,
        int $diasVencimiento
    ): bool {
        try {
            $templateFile = $this->templatePath . 'alerta_cobranza.html';

            if (!file_exists($templateFile)) {
                error_log("❌ Template no encontrado: {$templateFile}");
                return false;
            }

            $htmlContent = file_get_contents($templateFile);

            $replacements = [
                '[order_number]' => $orderNumber,
                '[customer_name]' => $customerName,
                '[number_purchase]' => $numberPurchase,
                '[number_sap]' => $numberSap,
                '[number_preforma]' => $numberPreforma,
                '[fecha_pago]' => $fechaPago,
                '[monto_pendiente]' => $montoPendiente,
                '[dias_vencimiento]' => $diasVencimiento
            ];

            foreach ($replacements as $placeholder => $value) {
                $htmlContent = str_replace($placeholder, $value, $htmlContent);
            }

            // Envía solo a BCC (usa fromEmail como destinatario formal)
            return $this->sendEmail(
                $this->fromEmail,
                "Alerta de Pago Pendiente - Orden {$orderNumber}",
                $htmlContent
            );
        } catch (Exception $e) {
            error_log("❌ Error enviando email de cobranza: " . $e->getMessage());
            return false;
        }
    }
}
