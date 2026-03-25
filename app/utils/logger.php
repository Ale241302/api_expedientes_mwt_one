<?php
class APILogger
{
    private $db;
    private $prefix;
    private $startTime;

    public function __construct(PDO $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->startTime = microtime(true);
    }

    /**
     * Registra la llamada al API
     */
    public function logRequest(
        int $userId,
        string $handler,
        string $funcionHandler,
        object $payload,
        int $responseStatus = 200,
        ?string $orderNumber = null
    ): bool {
        try {
            // Validar userId
            if ($userId === 0) {
                error_log("⚠️ Warning: userId es 0, no se guardará el log");
                return false;
            }

            $responseTime = round((microtime(true) - $this->startTime) * 1000, 2);
            $ipAddress = $this->getClientIP();
            $bodyJson = json_encode($payload);

            $stmt = $this->db->prepare(
                "INSERT INTO log_apitracking 
            (id_user, handler, funcion_handler, body, response_status, response_time, ip_address, order_number) 
            VALUES (:id_user, :handler, :funcion_handler, :body, :response_status, :response_time, :ip_address, :order_number)"
            );

            $result = $stmt->execute([
                ':id_user' => $userId,
                ':handler' => $handler,
                ':funcion_handler' => $funcionHandler,
                ':body' => $bodyJson,
                ':response_status' => $responseStatus,
                ':response_time' => $responseTime,
                ':ip_address' => $ipAddress,
                ':order_number' => $orderNumber
            ]);

            // Verificar resultado
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                error_log("❌ Error SQL en log: " . print_r($errorInfo, true));
                return false;
            }

            error_log("✅ Log guardado correctamente - ID: " . $this->db->lastInsertId());
            return true;
        } catch (Exception $e) {
            error_log("❌ Error en logging: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Obtiene la IP del cliente
     */
    private function getClientIP(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }

    /**
     * Obtiene logs de un usuario
     */
    public function getLogsForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM log_apitracking 
                 WHERE id_user = :id_user 
                 ORDER BY fecha_creacion DESC 
                 LIMIT :limit OFFSET :offset"
            );

            $stmt->bindValue(':id_user', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error obteniendo logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene logs de una orden específica
     */
    public function getLogsForOrder(string $orderNumber): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM log_apitracking 
                 WHERE order_number = :order_number 
                 ORDER BY fecha_creacion DESC"
            );

            $stmt->execute([':order_number' => $orderNumber]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error obteniendo logs de orden: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene estadísticas
     */
    public function getStats(int $horasAtras = 24): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT 
                    COUNT(*) as total_requests,
                    COUNT(DISTINCT id_user) as unique_users,
                    COUNT(DISTINCT order_number) as unique_orders,
                    handler,
                    AVG(response_time) as avg_response_time,
                    MAX(response_time) as max_response_time
                 FROM log_apitracking 
                 WHERE fecha_creacion >= DATE_SUB(NOW(), INTERVAL :horas HOUR)
                 GROUP BY handler"
            );

            $stmt->bindValue(':horas', $horasAtras, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error obteniendo estadísticas: " . $e->getMessage());
            return [];
        }
    }
}
