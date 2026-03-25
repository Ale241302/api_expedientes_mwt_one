<?php
class ActionMapper
{
    private $db;
    private $prefix;
    private $handlers = [];

    // Mapeo de acciones a sus handlers responsables
    private const ACTION_HANDLER_MAP = [
        // Preforma actions
        'createPreforma' => 'confirmed',
        'getPreforma' => 'confirmed',
        'deletePreforma' => 'confirmed',
        'deleteFiles' => 'confirmed',
        'changeStatus' => 'confirmed',

        // Credito actions
        'updateCustomerCredit' => 'credito',
        'getCreditOrderData' => 'credito',

        // Produccion/SAP actions
        'createSAP' => 'produccion',
        'listProduccionInfo' => 'produccion',
        'deletePreformaFiles' => 'produccion',
        'deletePreformaMwtFiles' => 'produccion',
        'deleteComplete' => 'produccion',
        'changeStatusToPreparacion' => 'produccion',

        // Preparacion actions
        'crearPreparacion' => 'preparacion',
        'listPreparacionInfo' => 'preparacion',
        'deletePackDetallado' => 'preparacion',
        'deleteCotizacion' => 'preparacion',
        'changeStatusToDespacho' => 'preparacion',

        // Despacho actions
        'crearDespacho' => 'despacho',
        'listDespachoInfo' => 'despacho',
        'deleteInvoice' => 'despacho',
        'deleteInvoiceMwt' => 'despacho',
        'deleteCertificado' => 'despacho',
        'deleteGuia' => 'despacho',
        'deleteCompleteDespacho' => 'despacho',
        'changeStatusToTransito' => 'despacho',

        // Transito actions
        'crearTransito' => 'transito',
        'listTransitoInfo' => 'transito',
        'deletePack' => 'transito',
        'changeStatusToPagado' => 'transito',

        // Pagado actions
        'createPago' => 'pagado',
        'listPagoInfo' => 'pagado',
        'deleteComprobante' => 'pagado',
        'deletePago' => 'pagado',
        'changeStatusToArchivada' => 'pagado',
    ];

    // Jerarquía de estados (para validaciones de workflow)
    private const STATE_HIERARCHY = [
        'preforma' => 0,
        'credito' => 1,
        'produccion' => 2,
        'preparacion' => 3,
        'despacho' => 4,
        'transito' => 5,
        'pagado' => 6,
        'Archivada' => 7
    ];

    // Acciones de solo lectura (pueden ejecutarse desde cualquier estado posterior)
    private const READ_ONLY_ACTIONS = [
        'getPreforma',
        'getCreditOrderData',
        'listProduccionInfo',
        'listPreparacionInfo',
        'listDespachoInfo',
        'listTransitoInfo',
        'listPagoInfo'
    ];

    public function __construct(PDO $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Obtiene el handler apropiado para una acción
     */
    public function getHandlerForAction(string $action, string $currentState): array
    {
        // Si es 'process' o acción no mapeada, usar handler del estado actual
        if ($action === 'process' || !isset(self::ACTION_HANDLER_MAP[$action])) {
            return [
                'state' => $currentState,
                'allowed' => true
            ];
        }

        $targetState = self::ACTION_HANDLER_MAP[$action];

        // Validar si la acción es permitida según el flujo
        $allowed = $this->isActionAllowed($action, $targetState, $currentState);

        return [
            'state' => $targetState,
            'allowed' => $allowed,
            'reason' => $allowed ? null : $this->getReasonDenied($action, $targetState, $currentState)
        ];
    }

    /**
     * Valida si una acción es permitida según el estado actual
     */
    private function isActionAllowed(string $action, string $targetState, string $currentState): bool
    {
        // Permitir acciones de solo lectura si el estado actual es igual o superior al requerido
        if (in_array($action, self::READ_ONLY_ACTIONS, true)) {
            return $this->getStateLevel($currentState) >= $this->getStateLevel($targetState);
        }

        // Acciones autorizadas adicionales para estado Archivada
        $allowedActionsForArchivada = [
            'getPreforma',
            'getCreditOrderData',
            'listProduccionInfo',
            'listPreparacionInfo',
            'listDespachoInfo',
            'listTransitoInfo',
            'listPagoInfo'
        ];

        if ($currentState === 'Archivada' && in_array($action, $allowedActionsForArchivada, true)) {
            return true;
        }

        // Para acciones de escritura, solo permitido en estado exacto
        return $currentState === $targetState;
    }

    /**
     * Obtiene el nivel jerárquico de un estado
     */
    private function getStateLevel(string $state): int
    {
        return self::STATE_HIERARCHY[$state] ?? -1;
    }

    /**
     * Genera mensaje de error descriptivo
     */
    private function getReasonDenied(string $action, string $targetState, string $currentState): string
    {
        if (in_array($action, self::READ_ONLY_ACTIONS, true)) {
            return "La acción '{$action}' requiere que la orden esté al menos en estado '{$targetState}', pero está en '{$currentState}'";
        }

        return "La acción '{$action}' solo puede ejecutarse en estado '{$targetState}', estado actual: '{$currentState}'";
    }

    /**
     * Carga y retorna instancia del handler
     */
    public function loadHandler(string $state): object
    {
        // Cache de handlers para evitar múltiples instancias
        if (isset($this->handlers[$state])) {
            return $this->handlers[$state];
        }

        $handlerFile = __DIR__ . '/../handlers/' . $state . '.php';

        if (!file_exists($handlerFile)) {
            throw new Exception("Handler file not found for state: {$state}");
        }

        require_once $handlerFile;

        $handlerClass = 'Handler' . ucfirst(str_replace('_', '', $state));

        if (!class_exists($handlerClass)) {
            throw new Exception("Handler class not found: {$handlerClass}");
        }

        $this->handlers[$state] = new $handlerClass($this->db, $this->prefix);

        return $this->handlers[$state];
    }
}
