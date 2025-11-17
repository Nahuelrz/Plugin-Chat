<?php
/**
 * Plugin Name: Activity Chat
 * Description: Chat en vivo entre clientes y colaboradores
 * Version: 1.0
 * Author: Tu Nombre
 */

if (!defined('ABSPATH')) exit;

// Activar plugin: crear tabla
register_activation_hook(__FILE__, 'ac_activate');
function ac_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    $charset = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        activity_id bigint(20) UNSIGNED NOT NULL,
        sender_id bigint(20) UNSIGNED NOT NULL,
        recipient_id bigint(20) UNSIGNED NOT NULL,
        message text NOT NULL,
        is_read tinyint(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY activity_id (activity_id),
        KEY sender_id (sender_id),
        KEY recipient_id (recipient_id)
    ) $charset;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Enqueue scripts
add_action('wp_enqueue_scripts', 'ac_enqueue');
function ac_enqueue() {
    if (!is_user_logged_in()) return;
    
    wp_enqueue_style('ac-style', plugin_dir_url(__FILE__) . 'ac-style.css', [], '1.0');
    wp_enqueue_script('ac-script', plugin_dir_url(__FILE__) . 'ac-script.js', ['jquery'], '1.0', true);
    
    wp_localize_script('ac-script', 'acVars', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'restUrl' => rest_url('activity-chat/v1/'),
        'nonce' => wp_create_nonce('wp_rest'),
        'userId' => get_current_user_id(),
        'isAdmin' => current_user_can('administrator')
    ]);
}

// REST API
add_action('rest_api_init', 'ac_register_routes');
function ac_register_routes() {
    register_rest_route('activity-chat/v1', '/send', [
        'methods' => 'POST',
        'callback' => 'ac_send_message',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    
    register_rest_route('activity-chat/v1', '/fetch', [
        'methods' => 'GET',
        'callback' => 'ac_fetch_messages',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    
    register_rest_route('activity-chat/v1', '/conversations', [
        'methods' => 'GET',
        'callback' => 'ac_get_conversations',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    
    register_rest_route('activity-chat/v1', '/clear-all', [
        'methods' => 'POST',
        'callback' => 'ac_clear_all_messages',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    
    register_rest_route('activity-chat/v1', '/cambiar-autor', [
        'methods' => 'POST',
        'callback' => 'ac_cambiar_autor_producto',
        'permission_callback' => function() { return current_user_can('administrator'); }
    ]);
    
    register_rest_route('activity-chat/v1', '/mark-read', [
        'methods' => 'POST',
        'callback' => 'ac_mark_messages_read',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    
    register_rest_route('activity-chat/v1', '/email-log', [
        'methods' => 'GET',
        'callback' => 'ac_get_email_log',
        'permission_callback' => function() { return current_user_can('administrator'); }
    ]);
    
    register_rest_route('activity-chat/v1', '/admin/all-conversations', [
        'methods' => 'GET',
        'callback' => 'ac_get_all_conversations_admin',
        'permission_callback' => function() { return current_user_can('administrator'); }
    ]);
    
    register_rest_route('activity-chat/v1', '/admin/conversation-messages', [
        'methods' => 'GET',
        'callback' => 'ac_get_conversation_messages_admin',
        'permission_callback' => function() { return current_user_can('administrator'); }
    ]);
}

function ac_send_message($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    
    $activity_id = intval($request->get_param('activity_id'));
    $recipient_id = intval($request->get_param('recipient_id'));
    $message = sanitize_text_field($request->get_param('message'));
    $sender_id = get_current_user_id();
    
    if (!$activity_id || !$recipient_id || !$message) {
        return new WP_Error('missing_params', 'Faltan parámetros', ['status' => 400]);
    }
    
    // Log para debug
    error_log("AC SEND: activity_id=$activity_id, sender=$sender_id, recipient=$recipient_id, msg=$message");
    
    $data_to_insert = [
        'activity_id' => $activity_id,
        'sender_id' => $sender_id,
        'recipient_id' => $recipient_id,
        'message' => $message
    ];
    
    $inserted = $wpdb->insert($table, $data_to_insert);
    
    if ($inserted) {
        error_log("AC SEND: Mensaje guardado con ID=" . $wpdb->insert_id);
        
        // Enviar email de notificación al destinatario si no está conectado
        error_log("AC SEND: Llamando a función de email para recipient_id=$recipient_id");
        ac_send_email_notification($recipient_id, $sender_id, $activity_id, $message);
        
        return ['success' => true, 'id' => $wpdb->insert_id, 'debug' => [
            'sender' => $sender_id,
            'recipient' => $recipient_id,
            'activity' => $activity_id,
            'inserted' => $inserted,
            'insert_id' => $wpdb->insert_id
        ]];
    }
    
    error_log("AC SEND ERROR: " . $wpdb->last_error);
    return new WP_Error('db_error', 'Error al guardar mensaje: ' . $wpdb->last_error, ['status' => 500]);
}

function ac_fetch_messages($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    
    $activity_id = intval($request->get_param('activity_id'));
    $other_user = intval($request->get_param('other_user'));
    $current_user = get_current_user_id();
    
    if (!$activity_id || !$other_user) {
        return new WP_Error('missing_params', 'Faltan parámetros', ['status' => 400]);
    }
    
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table 
        WHERE activity_id = %d 
        AND ((sender_id = %d AND recipient_id = %d) OR (sender_id = %d AND recipient_id = %d))
        ORDER BY created_at ASC",
        $activity_id, $current_user, $other_user, $other_user, $current_user
    ));
    
    return $messages;
}

function ac_mark_messages_read($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    
    $activity_id = intval($request->get_param('activity_id'));
    $other_user = intval($request->get_param('other_user'));
    $current_user = get_current_user_id();
    
    if (!$activity_id || !$other_user) {
        return new WP_Error('missing_params', 'Faltan parámetros', ['status' => 400]);
    }
    
    // Marcar como leídos los mensajes que me enviaron
    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET is_read = 1 
        WHERE activity_id = %d 
        AND sender_id = %d 
        AND recipient_id = %d 
        AND is_read = 0",
        $activity_id, $other_user, $current_user
    ));
    
    return ['success' => true];
}

function ac_get_email_log($request) {
    $email_log = get_option('ac_email_log', []);
    return [
        'success' => true,
        'emails' => array_reverse($email_log), // Mostrar más recientes primero
        'total' => count($email_log)
    ];
}

function ac_get_all_conversations_admin($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    
    // Obtener todas las conversaciones únicas del sistema
    $conversations = $wpdb->get_results(
        "SELECT DISTINCT 
            activity_id,
            LEAST(sender_id, recipient_id) as user1,
            GREATEST(sender_id, recipient_id) as user2
        FROM $table
        ORDER BY id DESC"
    );
    
    $result = [];
    $seen = [];
    
    foreach ($conversations as $conv) {
        // Evitar duplicados
        $key = $conv->activity_id . '-' . $conv->user1 . '-' . $conv->user2;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        
        $user1 = get_userdata($conv->user1);
        $user2 = get_userdata($conv->user2);
        $product = get_post($conv->activity_id);
        
        // Obtener último mensaje
        $last_msg_data = $wpdb->get_row($wpdb->prepare(
            "SELECT message, created_at, sender_id FROM $table 
            WHERE activity_id = %d AND (
                (sender_id = %d AND recipient_id = %d) OR 
                (sender_id = %d AND recipient_id = %d)
            ) ORDER BY created_at DESC LIMIT 1",
            $conv->activity_id, $conv->user1, $conv->user2, $conv->user2, $conv->user1
        ));
        
        // Contar total de mensajes
        $total_messages = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
            WHERE activity_id = %d AND (
                (sender_id = %d AND recipient_id = %d) OR 
                (sender_id = %d AND recipient_id = %d)
            )",
            $conv->activity_id, $conv->user1, $conv->user2, $conv->user2, $conv->user1
        ));
        
        $result[] = [
            'activity_id' => $conv->activity_id,
            'user1_id' => $conv->user1,
            'user1_name' => $user1 ? $user1->display_name : 'Usuario eliminado',
            'user2_id' => $conv->user2,
            'user2_name' => $user2 ? $user2->display_name : 'Usuario eliminado',
            'product_name' => $product ? $product->post_title : 'Producto eliminado',
            'product_link' => $product ? get_permalink($product->ID) : '',
            'last_message' => $last_msg_data ? $last_msg_data->message : '',
            'last_message_date' => $last_msg_data ? $last_msg_data->created_at : '',
            'last_sender' => $last_msg_data ? ($last_msg_data->sender_id == $conv->user1 ? $user1->display_name : $user2->display_name) : '',
            'total_messages' => intval($total_messages)
        ];
    }
    
    return $result;
}

function ac_get_conversation_messages_admin($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    
    $activity_id = intval($request->get_param('activity_id'));
    $user1 = intval($request->get_param('user1'));
    $user2 = intval($request->get_param('user2'));
    
    if (!$activity_id || !$user1 || !$user2) {
        return new WP_Error('missing_params', 'Faltan parámetros', ['status' => 400]);
    }
    
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT m.*, 
            u.display_name as sender_name
        FROM $table m
        LEFT JOIN {$wpdb->users} u ON m.sender_id = u.ID
        WHERE activity_id = %d 
        AND ((sender_id = %d AND recipient_id = %d) OR (sender_id = %d AND recipient_id = %d))
        ORDER BY created_at ASC",
        $activity_id, $user1, $user2, $user2, $user1
    ));
    
    return $messages;
}

function ac_get_conversations($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    $current_user = get_current_user_id();
    
    // Log para debug
    error_log("AC CONVERSATIONS: Buscando para user_id=$current_user");
    
    // Consulta simplificada sin GROUP BY problemático
    $sql = $wpdb->prepare(
        "SELECT DISTINCT
            activity_id,
            CASE 
                WHEN sender_id = %d THEN recipient_id 
                ELSE sender_id 
            END as other_user
        FROM $table
        WHERE sender_id = %d OR recipient_id = %d
        ORDER BY id DESC",
        $current_user, $current_user, $current_user
    );
    
    $conversations = $wpdb->get_results($sql);
    
    error_log("AC CONVERSATIONS: Encontradas " . count($conversations) . " conversaciones");
    error_log("AC CONVERSATIONS SQL: " . $sql);
    
    foreach ($conversations as &$conv) {
        $user = get_userdata($conv->other_user);
        $conv->other_user_name = $user ? $user->display_name : 'Usuario';
        
        $product = get_post($conv->activity_id);
        $conv->product_name = $product ? $product->post_title : 'Producto';
        
        // Obtener último mensaje y contar no leídos
        $last_msg = $wpdb->get_var($wpdb->prepare(
            "SELECT message FROM $table WHERE activity_id = %d AND (
                (sender_id = %d AND recipient_id = %d) OR 
                (sender_id = %d AND recipient_id = %d)
            ) ORDER BY created_at DESC LIMIT 1",
            $conv->activity_id, $current_user, $conv->other_user, $conv->other_user, $current_user
        ));
        $conv->last_message = $last_msg;
        
        $unread = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE activity_id = %d AND recipient_id = %d AND is_read = 0",
            $conv->activity_id, $current_user
        ));
        $conv->unread_count = intval($unread);
    }
    
    return $conversations;
}

function ac_clear_all_messages($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    
    $result = $wpdb->query("TRUNCATE TABLE $table");
    
    return ['success' => true, 'message' => 'Todos los mensajes borrados'];
}

function ac_cambiar_autor_producto($request) {
    $producto_id = 66;
    $nuevo_autor_id = 3; // Kevin
    
    $resultado = wp_update_post([
        'ID' => $producto_id,
        'post_author' => $nuevo_autor_id
    ]);
    
    if ($resultado) {
        $producto = get_post($producto_id);
        $autor = get_userdata($producto->post_author);
        return [
            'success' => true, 
            'message' => 'Producto actualizado',
            'autor_actual' => $autor->display_name,
            'autor_id' => $producto->post_author
        ];
    }
    
    return ['success' => false, 'message' => 'Error al actualizar'];
}

// Enviar notificación por email
function ac_send_email_notification($recipient_id, $sender_id, $activity_id, $message) {
    error_log("AC EMAIL: Iniciando función - recipient=$recipient_id, sender=$sender_id");
    
    // Verificar si el usuario está activo (última actividad en los últimos 5 minutos)
    $last_seen = get_user_meta($recipient_id, 'ac_last_seen', true);
    $current_time = current_time('timestamp');
    
    error_log("AC EMAIL: Last seen=$last_seen, Current time=$current_time, Diff=" . ($current_time - $last_seen));
    
    // Si el usuario estuvo activo hace menos de 5 minutos, no enviar email
    if ($last_seen && ($current_time - $last_seen) < 300) {
        error_log("AC EMAIL: Usuario $recipient_id está activo, no se envía email (estuvo activo hace " . ($current_time - $last_seen) . " segundos)");
        return;
    }
    
    $recipient = get_userdata($recipient_id);
    $sender = get_userdata($sender_id);
    $product = get_post($activity_id);
    
    if (!$recipient || !$sender || !$product) {
        error_log("AC EMAIL: Error - datos no encontrados (recipient=" . ($recipient ? 'OK' : 'NULL') . ", sender=" . ($sender ? 'OK' : 'NULL') . ", product=" . ($product ? 'OK' : 'NULL') . ")");
        return;
    }
    
    error_log("AC EMAIL: Preparando envío a {$recipient->user_email}");
    
    $subject = 'Nuevo mensaje en ' . get_bloginfo('name');
    $message_preview = mb_substr($message, 0, 100);
    
    $body = "Hola " . $recipient->display_name . ",\n\n";
    $body .= $sender->display_name . " te ha enviado un mensaje sobre \"" . $product->post_title . "\":\n\n";
    $body .= "\"" . $message_preview . "\"\n\n";
    $body .= "Responde aquí: " . get_permalink($activity_id) . "\n\n";
    $body .= "Saludos,\n";
    $body .= get_bloginfo('name');
    
    $email_sent = wp_mail($recipient->user_email, $subject, $body);
    
    error_log("AC EMAIL: Intentando enviar a {$recipient->user_email} - Resultado: " . ($email_sent ? 'ENVIADO ✓' : 'FALLÓ ✗'));
    
    // Guardar log del email para debug
    $email_log = get_option('ac_email_log', []);
    $email_log[] = [
        'fecha' => date('Y-m-d H:i:s'),
        'destinatario' => $recipient->user_email,
        'nombre' => $recipient->display_name,
        'de' => $sender->display_name,
        'producto' => $product->post_title,
        'mensaje' => $message_preview,
        'enviado' => $email_sent,
        'last_seen' => $last_seen ? date('Y-m-d H:i:s', $last_seen) : 'nunca',
        'inactivo_segundos' => $last_seen ? ($current_time - $last_seen) : 'N/A'
    ];
    // Mantener solo los últimos 10 emails
    if (count($email_log) > 10) {
        $email_log = array_slice($email_log, -10);
    }
    update_option('ac_email_log', $email_log);
    
    error_log("AC EMAIL: Log guardado, total emails en log: " . count($email_log));
}

// Actualizar última vez visto (se llama desde JavaScript)
add_action('wp_ajax_ac_update_last_seen', 'ac_update_last_seen');
function ac_update_last_seen() {
    $user_id = get_current_user_id();
    if ($user_id) {
        update_user_meta($user_id, 'ac_last_seen', current_time('timestamp'));
        wp_send_json_success();
    }
    wp_send_json_error();
}

// Botón de chat en productos
add_action('woocommerce_single_product_summary', 'ac_add_button', 35);
function ac_add_button() {
    if (!is_user_logged_in() || !is_product()) return;
    
    global $product;
    if (!$product) return;
    
    $author_id = get_post_field('post_author', $product->get_id());
    $current_user_id = get_current_user_id();
    
    // No mostrar si es el mismo usuario
    if ($current_user_id == $author_id) return;
    
    echo '
    <button id="ac-open-chat" 
            data-activity-id="' . esc_attr($product->get_id()) . '" 
            data-collab-id="' . esc_attr($author_id) . '"
            style="background:#0073aa;color:white;padding:10px 20px;border:none;cursor:pointer;border-radius:5px;margin-top:10px;">
        💬 Chat con el colaborador
    </button>';
}

// Dashboard de colaborador
add_shortcode('chat_dashboard_colaborador', 'ac_dashboard_shortcode');
function ac_dashboard_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Debes iniciar sesión.</p>';
    }
    
    ob_start();
    ?>
    <div id="ac-dashboard">
        <h2>Mis Conversaciones</h2>
        <div id="ac-conversations-list">Cargando...</div>
    </div>
    <?php
    return ob_get_clean();
}

// Dashboard de administrador - Ver todos los chats
add_shortcode('chat_dashboard_admin', 'ac_admin_dashboard_shortcode');
function ac_admin_dashboard_shortcode() {
    if (!is_user_logged_in() || !current_user_can('administrator')) {
        return '<p>No tienes permisos para acceder a esta sección.</p>';
    }
    
    ob_start();
    ?>
    <div id="ac-admin-dashboard">
        <h2>Todas las Conversaciones</h2>
        <div id="ac-admin-conversations-list">Cargando...</div>
    </div>
    <?php
    return ob_get_clean();
}

// Modal HTML (se inyecta en footer)
add_action('wp_footer', 'ac_add_modal');
function ac_add_modal() {
    if (!is_user_logged_in()) return;
    ?>
    <div id="ac-modal" style="display:none;">
        <div id="ac-header">
            <span id="ac-chat-title">Chat</span>
            <button id="ac-close">×</button>
        </div>
        <div id="ac-messages"></div>
        <div id="ac-input-wrap">
            <input type="text" id="ac-input" placeholder="Escribe un mensaje...">
            <button id="ac-send">Enviar</button>
        </div>
    </div>
    <?php
}
