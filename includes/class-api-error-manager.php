<?php
class Woo_Update_API_Error_Manager {

    private $error_count = 0;
    private $last_error_time = 0;
    private $error_threshold = 5;
    private $time_window = 300; // 5 minutos

    public function __construct() {
        // No mostrar errores al usuario
        add_filter('woocommerce_get_price_html', [$this, 'handle_price_display_errors'], 10, 2);
    }

    /**
     * Manejar errores en display de precios
     */
    public function handle_price_display_errors($price_html, $product) {
        // Si hay un error, solo mostrar el precio normal sin errores
        return $price_html;
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
     * Enviar alerta (puede ser extendido para email, Slack, etc)
     */
    private function send_alert($error_message) {
        // Log para debug
        Woo_Update_API()->log('ALERTA: Múltiples errores detectados - ' . $error_message, 'error');
        
        // Aquí se podría implementar notificación por email
        // wp_mail($admin_email, 'Woo Update API Alert', $error_message);
    }

    /**
     * Obtener estado de errores
     */
    public function get_error_status() {
        return [
            'error_count' => $this->error_count,
            'last_error_time' => $this->last_error_time,
            'threshold_reached' => $this->should_send_alert()
        ];
    }
}