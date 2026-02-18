<?php
class Woo_Update_API_Error_Manager {

    private $error_count = 0;
    private $last_error_time = 0;
    private $last_success_time = 0;
    private $last_api_timestamp = '';
    private $error_threshold = 5;
    private $time_window = 300; // 5 minutos

    public function __construct() {
        // No mostrar errores al usuario
        add_filter('woocommerce_get_price_html', [$this, 'handle_price_display_errors'], 10, 2);
    }

    /**
     * Registrar éxito de API con timestamp
     */
    public function log_success($sku, $timestamp) {
        $this->last_success_time = time();
        $this->last_api_timestamp = $timestamp;
        
        Woo_Update_API()->log('API exitosa para SKU: ' . $sku . ' - Timestamp: ' . $timestamp, 'api');
        
        // Resetear contador de errores si ha pasado suficiente tiempo
        if (time() - $this->last_error_time > $this->time_window) {
            $this->error_count = 0;
        }
    }

    /**
     * Registrar error
     */
    public function log_error($error_message, $context = []) {
        $this->error_count++;
        $this->last_error_time = time();

        Woo_Update_API()->log('Error registrado: ' . $error_message, 'error');

        // Si superamos el umbral, enviar notificación
        if ($this->should_send_alert()) {
            $this->send_alert($error_message);
        }
    }

    /**
     * Verificar si debemos enviar alerta
     */
    private function should_send_alert() {
        $current_time = time();
        
        // Resetear contador si pasó la ventana de tiempo
        if ($current_time - $this->last_error_time > $this->time_window) {
            $this->error_count = 0;
            return false;
        }

        return $this->error_count >= $this->error_threshold;
    }

    /**
     * Enviar alerta
     */
    private function send_alert($error_message) {
        Woo_Update_API()->log('ALERTA: Múltiples errores detectados - ' . $error_message, 'error');
    }

    /**
     * Obtener estado
     */
    public function get_status() {
        return [
            'error_count' => $this->error_count,
            'last_error_time' => $this->last_error_time,
            'last_success_time' => $this->last_success_time,
            'last_api_timestamp' => $this->last_api_timestamp,
            'threshold_reached' => $this->should_send_alert()
        ];
    }
}