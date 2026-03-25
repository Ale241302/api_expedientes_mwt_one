<?php
require_once __DIR__ . '/handler-base.php';

class HandlerCredito extends HandlerBase
{
    /**
     * Procesa una orden en estado "credito"
     */
    public function process(array $orderData, object $payload): array
    {
        try {
            $orderNumber = $orderData['order_number'];

            // Obtener datos completos de la orden y cliente
            $creditData = $this->getCreditOrderData($orderNumber);

            if (!$creditData) {
                return [
                    'http_code' => 404,
                    'success' => false,
                    'error' => 'No se encontraron datos para esta orden'
                ];
            }

            // Calcular crédito disponible
            $creditAvailable = $creditData['customer_credit'] - $creditData['order_full_price'];
            $hasEnoughCredit = $creditAvailable >= 0;

            return [
                'http_code' => 200,
                'success' => true,
                'message' => 'Orden en estado crédito',
                'status' => 'credito',
                'order' => [
                    'order_number' => $orderNumber,
                    'order_full_price' => $creditData['order_full_price'],
                    'operado_mwt' => $creditData['operado_mwt'],
                    'operador_nombre' => ($creditData['operado_mwt'] == 1) ? 'Muito Work Limitada' : 'Cliente'
                ],
                'customer' => [
                    'customer_id' => $creditData['customer'],
                    'customer_name' => $creditData['customer_name'],
                    'customer_credit' => $creditData['customer_credit'],
                    'customer_payment_time' => $creditData['customer_payment_time'],
                    'credit_available' => $creditAvailable,
                    'has_enough_credit' => $hasEnoughCredit
                ]
            ];
        } catch (Exception $e) {
            error_log("Error en credito handler: " . $e->getMessage());
            return [
                'http_code' => 500,
                'success' => false,
                'error' => 'Error procesando orden en crédito'
            ];
        }
    }

    /**
     * Obtiene datos de la orden y cliente con JOIN
     * @param string $orderNumber
     * @return array|null
     */
    public function getCreditOrderData(string $orderNumber, int $userId): array
    {
        try {
            // ✅ Obtener datos de la orden primero
            $stmt = $this->db->prepare(
                "SELECT 
                h.customer,
                h.order_full_price,
                h.operado_mwt
             FROM {$this->prefix}hikashop_order h
             WHERE h.order_number = :order_number
             LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            $orderData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$orderData) {
                error_log("⚠️ No se encontró orden: {$orderNumber}");
                return [
                    'success' => false,
                    'error' => 'No se encontró la orden especificada'
                ];
            }

            // ✅ Determinar qué customer_id usar
            $targetCustomerId = $orderData['customer']; // Por defecto, el de la orden
            $useUserCredit = false;

            // ✅ Solo verificar customer del usuario si operado_mwt = 1
            if ($orderData['operado_mwt'] == 1) {
                // Buscar en customer_user si el usuario tiene customer_id = 11
                $stmtUserCustomer = $this->db->prepare(
                    "SELECT customer_id 
                 FROM {$this->prefix}customer_user
                 WHERE user_id = :user_id AND customer_id = 11
                 LIMIT 1"
                );
                $stmtUserCustomer->execute([':user_id' => $userId]);
                $userCustomerData = $stmtUserCustomer->fetch(PDO::FETCH_ASSOC);

                if ($userCustomerData) {
                    // Usuario SÍ tiene customer 11 Y operado_mwt = 1
                    $targetCustomerId = 11;
                    $useUserCredit = true;
                    error_log("🔍 Usuario {$userId} tiene customer 11 y operado_mwt=1, usando crédito del customer 11");
                }
            }

            // ✅ Obtener información del customer (del usuario o de la orden)
            $stmtCustomer = $this->db->prepare(
                "SELECT 
                customer_name,
                customer_credit,
                customer_payment_time
             FROM {$this->prefix}customer
             WHERE customer_id = :customer_id
             LIMIT 1"
            );
            $stmtCustomer->execute([':customer_id' => $targetCustomerId]);
            $customerData = $stmtCustomer->fetch(PDO::FETCH_ASSOC);

            if (!$customerData) {
                error_log("⚠️ No se encontró customer con ID: {$targetCustomerId}");
                return [
                    'success' => false,
                    'error' => 'No se encontró información del cliente'
                ];
            }

            error_log("✅ Datos de crédito obtenidos para orden: {$orderNumber}" .
                ($useUserCredit ? " (usando crédito de customer 11)" : " (usando crédito de orden)"));

            return [
                'success' => true,
                'data' => [
                    'customer' => $targetCustomerId,
                    'order_full_price' => $orderData['order_full_price'],
                    'operado_mwt' => $orderData['operado_mwt'],
                    'customer_name' => $customerData['customer_name'],
                    'customer_credit' => $customerData['customer_credit'],
                    'customer_payment_time' => $customerData['customer_payment_time'],
                    'using_user_credit' => $useUserCredit
                ]
            ];
        } catch (Exception $e) {
            error_log("❌ Error obteniendo datos de crédito: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error al obtener datos de crédito: ' . $e->getMessage()
            ];
        }
    }



    /**
     * Actualiza el crédito del cliente y cambia estado a producción
     */
    public function updateCustomerCredit(string $orderNumber): array
    {
        try {
            // Paso 1: Obtener datos de la orden
            $orderStmt = $this->db->prepare(
                "SELECT order_full_price, customer, operado_mwt, order_status 
             FROM {$this->prefix}hikashop_order 
             WHERE order_number = :order_number 
             LIMIT 1"
            );
            $orderStmt->execute([':order_number' => $orderNumber]);
            $orderData = $orderStmt->fetch();

            if (!$orderData) {
                return ['success' => false, 'message' => 'Orden no encontrada'];
            }

            // Validar que el estado sea 'credito'
            if ($orderData['order_status'] !== 'credito') {
                return [
                    'success' => false,
                    'message' => 'El estado actual no es "credito". Estado actual: ' . $orderData['order_status']
                ];
            }

            // Paso 2: Determinar el customer_id según operado_mwt
            $customerId = ($orderData['operado_mwt'] == 1) ? 11 : $orderData['customer'];

            error_log("🔍 Orden: {$orderNumber} - Operado MWT: {$orderData['operado_mwt']} - Customer ID: {$customerId}");

            // Paso 3: Obtener crédito actual del cliente
            $customerStmt = $this->db->prepare(
                "SELECT customer_credit, customer_name 
             FROM {$this->prefix}customer 
             WHERE customer_id = :customer_id 
             LIMIT 1"
            );
            $customerStmt->execute([':customer_id' => $customerId]);
            $customerData = $customerStmt->fetch();

            if (!$customerData) {
                return [
                    'success' => false,
                    'message' => 'Cliente no encontrado (ID: ' . $customerId . ')'
                ];
            }

            $currentCredit = (float)$customerData['customer_credit'];
            $orderPrice = (float)$orderData['order_full_price'];
            $newCredit = $currentCredit - $orderPrice;
            // Paso 4: Iniciar transacción para actualización atómica
            $this->db->beginTransaction();

            try {
                // Actualizar crédito del cliente
                $updateCreditStmt = $this->db->prepare(
                    "UPDATE {$this->prefix}customer 
                 SET customer_credit = :new_credit 
                 WHERE customer_id = :customer_id"
                );
                $updateCreditStmt->execute([
                    ':new_credit' => $newCredit,
                    ':customer_id' => $customerId
                ]);

                // Paso 5: Cambiar estado de orden a 'produccion'
                $updateStatusStmt = $this->db->prepare(
                    "UPDATE {$this->prefix}hikashop_order 
                 SET order_status = :new_status, order_modified = NOW() 
                 WHERE order_number = :order_number"
                );
                $updateStatusStmt->execute([
                    ':new_status' => 'produccion',
                    ':order_number' => $orderNumber
                ]);

                // Confirmar transacción
                $this->db->commit();

                error_log("✅ Crédito actualizado - Cliente: {$customerData['customer_name']} ({$customerId}) - Anterior: {$currentCredit} - Nuevo: {$newCredit}");
                error_log("✅ Estado cambiado de credito a produccion para orden: {$orderNumber}");

                // Paso 6: Enviar email de cambio de estado
                $this->sendStatusChangeEmail($orderNumber, 'produccion');

                return [
                    'success' => true,
                    'message' => 'Crédito actualizado y orden movida a producción',
                    'data' => [
                        'customer_id' => $customerId,
                        'customer_name' => $customerData['customer_name'],
                        'previous_credit' => $currentCredit,
                        'order_price' => $orderPrice,
                        'new_credit' => $newCredit,
                        'new_status' => 'produccion'
                    ]
                ];
            } catch (Exception $e) {
                // Revertir transacción en caso de error
                $this->db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("❌ Error en updateCustomerCredit: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al actualizar crédito: ' . $e->getMessage()
            ];
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
            'pending' => 'Pendiente',
            'shipped' => 'Enviado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado'
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }
}
