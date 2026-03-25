<?php
// cron_email.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/utils/emailer.php';

try {
    $pdo = db_connect($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
    $emailer = new EmailSender(); // ✅ Instanciar EmailSender

    // 1. Obtener órdenes en estados específicos
    $stmt = $pdo->prepare("
        SELECT order_number, customer, operado_mwt, order_full_price, order_full_price_diferido
        FROM {$DB_PREFIX}hikashop_order
        WHERE order_status IN ('despacho', 'transito', 'pagado')
    ");
    $stmt->execute();
    $ordenes = $stmt->fetchAll();

    $procesados = 0;
    $enviados = 0;

    foreach ($ordenes as $orden) {
        $procesados++;
        $order_number = $orden['order_number'];
        $customer_id = $orden['customer'];
        $operado_mwt = $orden['operado_mwt'];
        $order_full_price = $orden['order_full_price'];
        $order_full_price_diferido = $orden['order_full_price_diferido'];

        // 2. Obtener fechas de shipping
        $stmt_shipping = $pdo->prepare("
            SELECT fechas FROM {$DB_PREFIX}shipping 
            WHERE order_number = ?
        ");
        $stmt_shipping->execute([$order_number]);
        $shipping = $stmt_shipping->fetch();

        if (!$shipping) continue;

        $fechas = $shipping['fechas'];

        // 3. Validar customer según operado_mwt
        if ($operado_mwt == 1) {
            $customer_id = 11;
        }

        // 4. Obtener datos del cliente
        $stmt_customer = $pdo->prepare("
            SELECT customer_name, customer_payment_time 
            FROM {$DB_PREFIX}customer 
            WHERE customer_id = ?
        ");
        $stmt_customer->execute([$customer_id]);
        $customer = $stmt_customer->fetch();

        if (!$customer) continue;

        $customer_name = $customer['customer_name'];
        $customer_payment_time = (int)$customer['customer_payment_time'];

        // 5. Calcular fecha de pago y validar diferencia con fecha actual
        $fecha_base = new DateTime($fechas);
        $fecha_pago = clone $fecha_base;
        $fecha_pago->modify("+{$customer_payment_time} days");

        $fecha_actual = new DateTime();
        $diferencia = $fecha_actual->diff($fecha_pago);
        $dias_diferencia = (int)$diferencia->format('%r%a');

        // Si la diferencia es menor a 15 días, saltar
        if ($dias_diferencia < 15) continue;

        // 6. Verificar pagos en josmwt_pago_order
        $stmt_pago = $pdo->prepare("
            SELECT tipo_pago, status, cantidad_pago, fecha_pago 
            FROM {$DB_PREFIX}pago_order 
            WHERE order_number = ?
        ");
        $stmt_pago->execute([$order_number]);
        $pagos = $stmt_pago->fetchAll();

        $monto_pendiente = null;

        if (count($pagos) > 0) {
            $tipo_pago = $pagos[0]['tipo_pago'];
            $status = $pagos[0]['status'];

            if ($tipo_pago === 'Completo') continue;

            if ($tipo_pago === 'Parcial') {
                if ($status === 'Credito Liberado') continue;

                if ($status === 'Pago Incompleto') {
                    $suma_pagos = 0;
                    foreach ($pagos as $pago) {
                        $suma_pagos += (float)$pago['cantidad_pago'];
                    }

                    $monto_pendiente = $order_full_price_diferido > 0
                        ? $order_full_price_diferido - $suma_pagos
                        : $order_full_price - $suma_pagos;
                }
            }
        } else {
            $monto_pendiente = $order_full_price_diferido > 0
                ? $order_full_price_diferido
                : $order_full_price;
        }

        // 7. Obtener number_purchase de preforma
        $stmt_preforma = $pdo->prepare("
            SELECT number_purchase 
            FROM {$DB_PREFIX}preforma 
            WHERE order_number = ?
        ");
        $stmt_preforma->execute([$order_number]);
        $preforma = $stmt_preforma->fetch();
        $number_purchase = $preforma['number_purchase'] ?? '';

        // 8. Obtener datos de SAP
        $stmt_sap = $pdo->prepare("
            SELECT number_sap, number_preforma 
            FROM {$DB_PREFIX}sap 
            WHERE order_number = ?
        ");
        $stmt_sap->execute([$order_number]);
        $sap = $stmt_sap->fetch();
        $number_sap = $sap['number_sap'] ?? '';
        $number_preforma_sap = $sap['number_preforma'] ?? '';

        // 9. Preparar datos para email
        $email_data = [
            'order_number' => $order_number,
            'customer_name' => $customer_name,
            'number_purchase' => $number_purchase,
            'number_sap' => $number_sap,
            'number_preforma' => $number_preforma_sap,
            'fecha_pago' => $fecha_pago->format('d/m/Y'),
            'monto_pendiente' => formatearMonto($monto_pendiente),
            'dias_vencimiento' => $dias_diferencia
        ];

        // 10. Enviar email - ✅ CORREGIDO: pasar $emailer
        $email_enviado = enviarEmailCobranza($emailer, $email_data);

        if ($email_enviado) {
            $enviados++;
            // 11. Registrar en log
            registrarEnvioEmail($pdo, $DB_PREFIX, $order_number, $email_data);
        }
    }

    echo "✅ Proceso completado: " . date('Y-m-d H:i:s') . "<br>";
    echo "Órdenes procesadas: {$procesados}<br>";
    echo "Emails enviados: {$enviados}<br>";
} catch (Exception $e) {
    error_log("Error en cron_email.php: " . $e->getMessage());
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
// Función para formatear monto sin decimales innecesarios
function formatearMonto($monto)
{
    // Si el monto tiene decimales, mostrarlos, sino solo entero
    if (floor($monto) == $monto) {
        // No tiene decimales
        return number_format($monto, 0, ',', '.');
    } else {
        // Tiene decimales
        return number_format($monto, 2, ',', '.');
    }
}

// ✅ CORREGIDO: Función recibe $emailer
function enviarEmailCobranza($emailer, $data)
{
    return $emailer->sendCobranzaAlert(
        $data['order_number'],
        $data['customer_name'],
        $data['number_purchase'],
        $data['number_sap'],
        $data['number_preforma'],
        $data['fecha_pago'],
        $data['monto_pendiente'],
        $data['dias_vencimiento']
    );
}

function registrarEnvioEmail($pdo, $prefix, $order_number, $data)
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}email_cobranza_log 
            (order_number, customer_name, fecha_envio, monto_enviado, datos_json)
            VALUES (?, ?, NOW(), ?, ?)
        ");

        // Limpiar formato de número para guardar en DB
        $monto_limpio = str_replace(['.', ','], ['', '.'], $data['monto_pendiente']);

        $stmt->execute([
            $order_number,
            $data['customer_name'],
            $monto_limpio,
            json_encode($data, JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $e) {
        error_log("Error guardando log: " . $e->getMessage());
    }
}
