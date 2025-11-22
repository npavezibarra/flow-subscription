<?php
/**
 * Plugin Name: FlowSubscription
 * Description: Integración básica con Flow para suscripciones.
 * Version: 0.1.0
 * Author: Nicolás Pavez
 */

if (!defined('ABSPATH')) {
    exit;
}

class FlowSubscription {
    public function __construct() {
        // Inicialización del plugin
        add_action('init', [$this, 'register_endpoints']);
    }

    public function register_endpoints() {
        // Registrar endpoints necesarios
    }
}

new FlowSubscription();
