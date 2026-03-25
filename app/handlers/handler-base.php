<?php
abstract class HandlerBase
{
    protected $db;
    protected $prefix;

    public function __construct(PDO $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Método que debe implementar cada handler
     */
    abstract public function process(array $orderData, object $payload): array;

    /**
     * Obtiene items de la orden
     */
    protected function getOrderItems(int $orderId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->prefix}order_product WHERE order_id = :order_id"
        );
        $stmt->execute([':order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Obtiene información del cliente
     */
    protected function getCustomerInfo(int $customerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->prefix}customer WHERE customer_id = :customer_id LIMIT 1"
        );
        $stmt->execute([':customer_id' => $customerId]);
        return $stmt->fetch();
    }

    /**
     * Actualiza estado de la orden
     */
    protected function updateOrderStatus(int $orderId, string $newStatus): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->prefix}order SET order_status = :status, order_modified = NOW() WHERE order_id = :order_id"
            );
            return $stmt->execute([
                ':status' => $newStatus,
                ':order_id' => $orderId
            ]);
        } catch (Exception $e) {
            error_log("Error actualizando estado: " . $e->getMessage());
            return false;
        }
    }
}
