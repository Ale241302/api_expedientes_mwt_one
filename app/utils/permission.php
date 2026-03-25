<?php
class PermissionValidator
{
    private $db;
    private $prefix;

    public function __construct(PDO $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Obtiene las funciones permitidas para un usuario
     * @param int $userId ID del usuario
     * @return array Array de nombres de funciones permitidas
     */
    public function getUserAllowedFunctions(int $userId): array
    {
        try {
            // Paso 1: Obtener id_funcion de fuciones_apitracking_user
            $stmt = $this->db->prepare(
                "SELECT id_funcion FROM {$this->prefix}fuciones_apitracking_user 
                 WHERE id_user = :id_user"
            );
            $stmt->execute([':id_user' => $userId]);
            $funcionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($funcionIds)) {
                return [];
            }

            // Paso 2: Crear placeholders para IN clause
            $placeholders = str_repeat('?,', count($funcionIds) - 1) . '?';

            // Paso 3: Obtener nombres de funciones
            $stmt = $this->db->prepare(
                "SELECT nombre_funcion FROM {$this->prefix}funciones_apitraking 
                 WHERE id IN ({$placeholders})"
            );
            $stmt->execute($funcionIds);

            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log("❌ Error obteniendo funciones permitidas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Valida si un usuario tiene permiso para ejecutar una función específica
     * @param int $userId ID del usuario
     * @param string $functionName Nombre de la función a validar
     * @return bool True si tiene permiso, False si no
     */
    public function hasPermissionForFunction(int $userId, string $functionName): bool
    {
        $allowedFunctions = $this->getUserAllowedFunctions($userId);
        return in_array($functionName, $allowedFunctions, true);
    }

    /**
     * Valida que un usuario (por keyuser) tenga acceso a una orden
     */
    public function validateUserOrderAccess(string $keyuser, string $orderNumber): array
    {
        try {
            // Paso 1: Buscar usuario en tabla 'users' por keyuser
            $userStmt = $this->db->prepare(
                "SELECT id FROM josmwt_users WHERE keyuser = :keyuser LIMIT 1"
            );
            $userStmt->execute([':keyuser' => $keyuser]);
            $user = $userStmt->fetch();

            if (!$user) {
                return [
                    'allowed' => false,
                    'reason' => 'Usuario no encontrado'
                ];
            }

            $userId = $user['id'];

            // Paso 2: Obtener customer_id(s) asociados a este usuario
            $customerStmt = $this->db->prepare(
                "SELECT customer_id FROM {$this->prefix}customer_user WHERE user_id = :user_id"
            );
            $customerStmt->execute([':user_id' => $userId]);
            $customers = $customerStmt->fetchAll();

            if (empty($customers)) {
                return [
                    'allowed' => false,
                    'reason' => 'Usuario no tiene clientes asociados'
                ];
            }

            $customerIds = array_column($customers, 'customer_id');

            // Paso 3: Obtener orden y verificar que el customer coincida
            $orderStmt = $this->db->prepare(
                "SELECT order_id, order_status, customer FROM {$this->prefix}hikashop_order 
             WHERE order_number = :order_number LIMIT 1"
            );
            $orderStmt->execute([':order_number' => $orderNumber]);
            $order = $orderStmt->fetch();

            if (!$order) {
                return [
                    'allowed' => false,
                    'reason' => 'Orden no encontrada'
                ];
            }

            // NUEVO: Si no hay customer asignado, permitir acceso
            if (empty($order['customer']) || $order['customer'] === null) {
                return [
                    'allowed' => true,
                    'customer_id' => null,
                    'order_id' => $order['order_id']
                ];
            }

            // Paso 4: Validar que el customer de la orden esté en la lista de clientes del usuario
            if (!in_array($order['customer'], $customerIds)) {
                return [
                    'allowed' => false,
                    'reason' => 'El customer de esta orden no está asociado a tu usuario'
                ];
            }

            return [
                'allowed' => true,
                'customer_id' => $order['customer'],
                'order_id' => $order['order_id']
            ];
        } catch (Exception $e) {
            error_log("❌ Error validando permisos: " . $e->getMessage());
            return [
                'allowed' => false,
                'reason' => 'Error en validación de permisos'
            ];
        }
    }


    /**
     * Obtiene datos de la orden
     */
    public function getOrderData(string $orderNumber): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM {$this->prefix}hikashop_order WHERE order_number = :order_number LIMIT 1"
            );
            $stmt->execute([':order_number' => $orderNumber]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Error obteniendo orden: " . $e->getMessage());
            return null;
        }
    }
}
