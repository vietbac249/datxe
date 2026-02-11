<?php
/**
 * Plugin Name: Đặt Xe Nội Bài
 * Plugin URI: https://noibai.vn
 * Description: Plugin đặt xe sân bay Nội Bài và đường dài với tính năng tính km và giá tiền tự động
 * Version: 1.0.0
 * Author: NoiBai.vn
 * Author URI: https://noibai.vn
 * License: GPL v2 or later
 * Text Domain: booking-plugin
 */

// Ngăn truy cập trực tiếp
if (!defined('ABSPATH')) {
    exit;
}

// Định nghĩa constants
define('BOOKING_PLUGIN_VERSION', '1.0.0');
define('BOOKING_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BOOKING_PLUGIN_URL', plugin_dir_url(__FILE__));

class BookingPlugin {
    
    public function __construct() {
        // Enqueue scripts và styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Đăng ký shortcode
        add_shortcode('dat_xe', array($this, 'render_booking_form'));
        
        // Đăng ký AJAX handlers
        add_action('wp_ajax_calculate_distance', array($this, 'ajax_calculate_distance'));
        add_action('wp_ajax_nopriv_calculate_distance', array($this, 'ajax_calculate_distance'));
        
        add_action('wp_ajax_submit_booking', array($this, 'ajax_submit_booking'));
        add_action('wp_ajax_nopriv_submit_booking', array($this, 'ajax_submit_booking'));
        
        // Thêm menu admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Đăng ký settings
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    // Enqueue CSS và JavaScript
    public function enqueue_assets() {
        wp_enqueue_style(
            'booking-plugin-style',
            BOOKING_PLUGIN_URL . 'assets/css/style.css',
            array(),
            BOOKING_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'booking-plugin-script',
            BOOKING_PLUGIN_URL . 'assets/js/script.js',
            array('jquery'),
            BOOKING_PLUGIN_VERSION,
            true
        );
        
        // Google Maps API
        $google_api_key = get_option('booking_google_api_key', '');
        if (!empty($google_api_key)) {
            wp_enqueue_script(
                'google-maps',
                'https://maps.googleapis.com/maps/api/js?key=' . $google_api_key . '&libraries=places',
                array(),
                null,
                true
            );
        }
        
        // Localize script
        wp_localize_script('booking-plugin-script', 'bookingAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('booking_nonce'),
            'pricing' => array(
                'airport' => array(
                    'basePrice' => floatval(get_option('booking_airport_price', 15000)),
                    'roundTripMultiplier' => floatval(get_option('booking_roundtrip_multiplier', 1.8)),
                    'vatRate' => floatval(get_option('booking_vat_rate', 0.1))
                ),
                'longDistance' => array(
                    'basePrice' => floatval(get_option('booking_long_price', 12000)),
                    'vatRate' => floatval(get_option('booking_vat_rate', 0.1))
                )
            )
        ));
    }
    
    // Render form đặt xe
    public function render_booking_form($atts) {
        ob_start();
        include BOOKING_PLUGIN_PATH . 'templates/booking-form.php';
        return ob_get_clean();
    }
    
    // AJAX: Tính khoảng cách
    public function ajax_calculate_distance() {
        check_ajax_referer('booking_nonce', 'nonce');
        
        $origin = sanitize_text_field($_POST['origin']);
        $destination = sanitize_text_field($_POST['destination']);
        $google_api_key = get_option('booking_google_api_key', '');
        
        if (empty($google_api_key)) {
            wp_send_json_error(array('message' => 'Chưa cấu hình Google API Key'));
        }
        
        // Gọi Google Distance Matrix API
        $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?';
        $url .= 'origins=' . urlencode($origin);
        $url .= '&destinations=' . urlencode($destination);
        $url .= '&key=' . $google_api_key;
        
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Không thể kết nối đến Google Maps'));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($data['status'] === 'OK') {
            $distance = $data['rows'][0]['elements'][0]['distance']['value'] / 1000; // km
            wp_send_json_success(array('distance' => round($distance, 1)));
        } else {
            wp_send_json_error(array('message' => 'Không thể tính khoảng cách'));
        }
    }
    
    // AJAX: Submit booking
    public function ajax_submit_booking() {
        check_ajax_referer('booking_nonce', 'nonce');
        
        $booking_data = array(
            'type' => sanitize_text_field($_POST['type']),
            'phone' => sanitize_text_field($_POST['phone']),
            'name' => sanitize_text_field($_POST['name']),
            'from' => sanitize_text_field($_POST['from']),
            'to' => sanitize_text_field($_POST['to']),
            'car_type' => sanitize_text_field($_POST['car_type']),
            'datetime' => sanitize_text_field($_POST['datetime']),
            'price' => sanitize_text_field($_POST['price']),
            'created_at' => current_time('mysql')
        );
        
        // Tạo nội dung thông báo
        $message = "🚗 ĐƠN ĐẶT XE MỚI\n\n";
        $message .= "👤 Họ tên: " . $booking_data['name'] . "\n";
        $message .= "📞 SĐT: " . $booking_data['phone'] . "\n";
        $message .= "📍 Từ: " . $booking_data['from'] . "\n";
        $message .= "📍 Đến: " . $booking_data['to'] . "\n";
        $message .= "🚙 Loại xe: " . $booking_data['car_type'] . "\n";
        $message .= "🕐 Thời gian: " . $booking_data['datetime'] . "\n";
        $message .= "💰 Giá: " . $booking_data['price'] . "\n";
        $message .= "⏰ Đặt lúc: " . current_time('d/m/Y H:i') . "\n";
        
        // Gửi email
        $admin_email = get_option('admin_email');
        $subject = '🚗 Đơn đặt xe mới từ ' . $booking_data['name'];
        wp_mail($admin_email, $subject, $message);
        
        // Gửi Telegram
        $this->send_telegram_notification($message);
        
        // Gửi Zalo
        $this->send_zalo_notification($message);
        
        wp_send_json_success(array('message' => 'Đặt xe thành công'));
    }
    
    // Gửi thông báo qua Telegram
    private function send_telegram_notification($message) {
        $bot_token = get_option('booking_telegram_bot_token', '');
        $chat_id = get_option('booking_telegram_chat_id', '');
        
        if (empty($bot_token) || empty($chat_id)) {
            return false;
        }
        
        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        
        $data = array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        );
        
        $response = wp_remote_post($url, array(
            'body' => $data,
            'timeout' => 10
        ));
        
        return !is_wp_error($response);
    }
    
    // Gửi thông báo qua Zalo
    private function send_zalo_notification($message) {
        $access_token = get_option('booking_zalo_access_token', '');
        $phone = get_option('booking_zalo_phone', '');
        
        if (empty($access_token) || empty($phone)) {
            return false;
        }
        
        $url = "https://openapi.zalo.me/v2.0/oa/message";
        
        $data = array(
            'recipient' => array(
                'user_id' => $phone
            ),
            'message' => array(
                'text' => $message
            )
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'access_token' => $access_token
            ),
            'body' => json_encode($data),
            'timeout' => 10
        ));
        
        return !is_wp_error($response);
    }
    
    // Thêm menu admin
    public function add_admin_menu() {
        add_menu_page(
            'Cài Đặt Đặt Xe',
            'Đặt Xe',
            'manage_options',
            'booking-plugin',
            array($this, 'admin_settings_page'),
            'dashicons-car',
            30
        );
    }
    
    // Đăng ký settings
    public function register_settings() {
        register_setting('booking_plugin_settings', 'booking_google_api_key');
        register_setting('booking_plugin_settings', 'booking_airport_price');
        register_setting('booking_plugin_settings', 'booking_long_price');
        register_setting('booking_plugin_settings', 'booking_roundtrip_multiplier');
        register_setting('booking_plugin_settings', 'booking_vat_rate');
        register_setting('booking_plugin_settings', 'booking_telegram_bot_token');
        register_setting('booking_plugin_settings', 'booking_telegram_chat_id');
        register_setting('booking_plugin_settings', 'booking_zalo_access_token');
        register_setting('booking_plugin_settings', 'booking_zalo_phone');
    }
    
    // Trang cài đặt admin
    public function admin_settings_page() {
        include BOOKING_PLUGIN_PATH . 'templates/admin-settings.php';
    }
}

// Khởi tạo plugin
new BookingPlugin();
