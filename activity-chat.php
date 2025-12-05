<?php
/**
 * Plugin Name: Activity Chat
 * Description: Chat en vivo entre clientes y colaboradores
 * Version: 1.0
 * Author: Tu Nombre
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * CRON PARA CIERRE AUTOMÁTICO DE CHATS
 * - Se ejecuta 1 vez al día
 * - Cierra chats con más de 5 días sin actividad
 * ============================================================ */

// Registrar cron al activar plugin
register_activation_hook(__FILE__, 'ac_schedule_close_cron');
function ac_schedule_close_cron() {
    if (!wp_next_scheduled('ac_daily_close_check')) {
        wp_schedule_event(time(), 'daily', 'ac_daily_close_check');
    }
}

// Cancelar cron al desactivar plugin
register_deactivation_hook(__FILE__, 'ac_unschedule_close_cron');
function ac_unschedule_close_cron() {
    $timestamp = wp_next_scheduled('ac_daily_close_check');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ac_daily_close_check');
    }
}

/* ============================================================
 * CREACIÓN DE TABLA Y COLUMNAS (ACTIVACIÓN DEL PLUGIN)
 * ============================================================ */

register_activation_hook(__FILE__, 'ac_activate');
function ac_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    $charset = $wpdb->get_charset_collate();
    
    // Crear tabla si no existe
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

    // NUEVO: columnas para cierre de chat
    // dbDelta NO agrega columnas nuevas → usar ALTER TABLE
// Agregar columna is_closed si no existe
if (!$wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'is_closed'")) {
    $wpdb->query("ALTER TABLE $table ADD COLUMN is_closed TINYINT(1) DEFAULT 0");
}

// Agregar columna closed_at si no existe
if (!$wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'closed_at'")) {
    $wpdb->query("ALTER TABLE $table ADD COLUMN closed_at DATETIME NULL");
}

}

/* ============================================================
 * ENQUEUE ⇒ Cargar CSS + JS del plugin
 * ============================================================ */

add_action('wp_enqueue_scripts', 'ac_enqueue');
function ac_enqueue() {
    if (!is_user_logged_in()) return;
    
    // CSS
    wp_enqueue_style('ac-style', plugin_dir_url(__FILE__) . 'ac-style.css', [], '1.0');
    
    // JS
    wp_enqueue_script('ac-script', plugin_dir_url(__FILE__) . 'ac-script.js', ['jquery'], '1.0', true);
    
    // Variables disponibles en JS
    wp_localize_script('ac-script', 'acVars', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'restUrl' => rest_url('activity-chat/v1/'),
        'nonce' => wp_create_nonce('wp_rest'),
        'userId' => get_current_user_id(),
        'isAdmin' => current_user_can('administrator')
    ]);
}

/* ============================================================
 * REST API → Endpoint del chat
 * ============================================================ */

add_action('rest_api_init', 'ac_register_routes');
function ac_register_routes() {

    // Enviar mensaje
    register_rest_route('activity-chat/v1', '/send', [
        'methods' => 'POST',
        'callback' => 'ac_send_message',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Obtener mensajes
    register_rest_route('activity-chat/v1', '/fetch', [
        'methods' => 'GET',
        'callback' => 'ac_fetch_messages',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Listado de conversaciones
    register_rest_route('activity-chat/v1', '/conversations', [
        'methods' => 'GET',
        'callback' => 'ac_get_conversations',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Borrar TODO el chat
    register_rest_route('activity-chat/v1', '/clear-all', [
        'methods' => 'POST',
        'callback' => 'ac_clear_all_messages',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Cambiar autor producto (admin)
    register_rest_route('activity-chat/v1', '/cambiar-autor', [
        'methods' => 'POST',
        'callback' => 'ac_cambiar_autor_producto',
        'permission_callback' => function() { return current_user_can('administrator'); }
    ]);

    // Marcar mensajes como leídos
    register_rest_route('activity-chat/v1', '/mark-read', [
        'methods' => 'POST',
        'callback' => 'ac_mark_messages_read',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Log de emails
    register_rest_route('activity-chat/v1', '/email-log', [
        'methods' => 'GET',
        'callback' => 'ac_get_email_log',
        'permission_callback' => function() { return current_user_can('administrator'); }
    ]);

    // Admin → Lista de todas las conversaciones
    register_rest_route('activity-chat/v1', '/admin/all-conversations', [
        'methods' => 'GET',
        'callback' => 'ac_get_all_conversations_admin',
        'permission_callback' => function() { return current_user_can('administrator'); }
    ]);

    // Admin → mensajes de una conversación
    register_rest_route('activity-chat/v1', '/admin/conversation-messages', [
        'methods' => 'GET',
        'callback' => 'ac_get_conversation_messages_admin',
        'permission_callback' => function() { return current_user_can('administrator'); }
    ]);

    // Cierre manual de chat
    register_rest_route('activity-chat/v1', '/close', [
        'methods' => 'POST',
        'callback' => 'ac_close_chat',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
}

/* ============================================================
 * ENVÍO DE MENSAJE
 * ============================================================ */

function ac_send_message($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    
    $activity_id = intval($request->get_param('activity_id'));
    $recipient_id = intval($request->get_param('recipient_id'));
    $message = sanitize_text_field($request->get_param('message'));
    $sender_id = get_current_user_id();
    
    if (!$activity_id || !$recipient_id || !$message) {
        return new WP_Error('missing_params', 'Faltan parámetros', 400);
    }

    // No permitir enviar si el chat ya está cerrado
    $closed = $wpdb->get_var($wpdb->prepare(
        "SELECT is_closed FROM $table WHERE activity_id = %d LIMIT 1",
        $activity_id
    ));

    if ($closed == 1) {
        return new WP_Error('chat_closed', 'El chat ya está cerrado.', 403);
    }

    // Insertar mensaje
    $data = [
        'activity_id' => $activity_id,
        'sender_id' => $sender_id,
        'recipient_id' => $recipient_id,
        'message' => $message
    ];
    
    $inserted = $wpdb->insert($table, $data);
    
    if ($inserted) {
        ac_send_email_notification($recipient_id, $sender_id, $activity_id, $message);
        return ['success' => true];
    }

    return new WP_Error('db_error', 'Error al guardar mensaje', 500);
}

/* ============================================================
 * OBTENER MENSAJES
 * ============================================================ */

// Obtener mensajes de un chat (y estado de cierre)
function ac_fetch_messages($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    
    // ID del producto/actividad
    $activity_id = intval($request->get_param('activity_id'));
    // ID del otro usuario con el que chateo
    $other_user = intval($request->get_param('other_user'));
    // Usuario actual (logueado)
    $current_user = get_current_user_id();
    
    if (!$activity_id || !$other_user) {
        return new WP_Error('missing_params', 'Faltan parámetros', ['status' => 400]);
    }
    
    // Mensajes entre el usuario actual y el otro usuario, para esa actividad
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE activity_id = %d 
         AND (
              (sender_id = %d AND recipient_id = %d)
           OR (sender_id = %d AND recipient_id = %d)
         )
         ORDER BY created_at ASC",
        $activity_id, $current_user, $other_user, $other_user, $current_user
    ));
    
    // Estado de cierre del chat (cogemos cualquier fila de esa actividad)
$closed = $wpdb->get_var($wpdb->prepare(
    "SELECT MAX(is_closed)
     FROM $table
     WHERE activity_id = %d",
    $activity_id
));


    // Normalizar nulos (por si había registros antiguos sin valor)
    if ($closed === null) {
        $closed = 0;
    }

    // Devolvemos un objeto con el estado y la lista de mensajes
    return [
        'closed'   => intval($closed),
        'messages' => $messages
    ];
}


/* ============================================================
 * MARCAR MENSAJES COMO LEÍDOS
 * ============================================================ */

function ac_mark_messages_read($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';

    $activity_id = intval($request->get_param('activity_id'));
    $other_user = intval($request->get_param('other_user'));
    $current_user = get_current_user_id();

    if (!$activity_id || !$other_user) {
        return new WP_Error('missing_params', 'Faltan parámetros', 400);
    }

    // Marcar mensajes del otro usuario como leídos
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

/* ============================================================
 * EMAILS DE NOTIFICACIÓN
 * ============================================================ */

function ac_get_email_log($request) {
    $email_log = get_option('ac_email_log', []);
    return [
        'success' => true,
        'emails' => array_reverse($email_log),
        'total' => count($email_log)
    ];
}

/* ============================================================
 * TODAS LAS FUNCIONES DE ADMIN (listado, mensajes…)
 * ============================================================ */

/* ============================================================
 * ADMIN → Lista de todas las conversaciones
 * ============================================================ */

function ac_get_all_conversations_admin($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';

    // Obtener todas las combinaciones únicas de conversaciones
    $conversations = $wpdb->get_results("
        SELECT DISTINCT 
            activity_id,
            LEAST(sender_id, recipient_id) AS user1,
            GREATEST(sender_id, recipient_id) AS user2
        FROM $table
        ORDER BY id DESC
    ");

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

        // Último mensaje
        $last_msg_data = $wpdb->get_row($wpdb->prepare(
            "SELECT message, created_at, sender_id FROM $table
             WHERE activity_id = %d
             AND ((sender_id = %d AND recipient_id = %d) 
             OR  (sender_id = %d AND recipient_id = %d))
             ORDER BY created_at DESC LIMIT 1",
            $conv->activity_id, $conv->user1, $conv->user2,
            $conv->user2, $conv->user1
        ));

        // Total mensajes en la conversación
        $total_messages = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE activity_id = %d AND 
             ((sender_id = %d AND recipient_id = %d)
             OR  (sender_id = %d AND recipient_id = %d))",
            $conv->activity_id, $conv->user1, $conv->user2,
            $conv->user2, $conv->user1
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

/* ============================================================
 * ADMIN → Obtener mensajes de una conversación concreta
 * ============================================================ */

function ac_get_conversation_messages_admin($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';

    $activity_id = intval($request->get_param('activity_id'));
    $user1 = intval($request->get_param('user1'));
    $user2 = intval($request->get_param('user2'));

    if (!$activity_id || !$user1 || !$user2) {
        return new WP_Error('missing_params', 'Faltan parámetros', 400);
    }

    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT m.*, u.display_name AS sender_name
         FROM $table m
         LEFT JOIN {$wpdb->users} u ON m.sender_id = u.ID
         WHERE activity_id = %d
         AND ((sender_id = %d AND recipient_id = %d)
         OR  (sender_id = %d AND recipient_id = %d))
         ORDER BY created_at ASC",
        $activity_id, $user1, $user2, $user2, $user1
    ));

    return $messages;
}

/* ============================================================
 * LISTA DE CONVERSACIONES DEL USUARIO ACTUAL
 * ============================================================ */

function ac_get_conversations($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';
    $current_user = get_current_user_id();

    error_log("AC CONVERSATIONS: Buscando para user_id=$current_user");

    // Obtener todas las conversaciones en las que participa el usuario
    $sql = $wpdb->prepare(
        "SELECT DISTINCT
            activity_id,
            CASE 
                WHEN sender_id = %d THEN recipient_id
                ELSE sender_id 
            END AS other_user
         FROM $table
         WHERE sender_id = %d OR recipient_id = %d
         ORDER BY id DESC",
        $current_user, $current_user, $current_user
    );

    $conversations = $wpdb->get_results($sql);

    foreach ($conversations as &$conv) {

        // Datos del otro usuario
        $user = get_userdata($conv->other_user);
        $conv->other_user_name = $user ? $user->display_name : 'Usuario';

        // Producto asociado
        $product = get_post($conv->activity_id);
        $conv->product_name = $product ? $product->post_title : 'Producto';

        // Último mensaje
        $last_msg = $wpdb->get_var($wpdb->prepare(
            "SELECT message FROM $table
             WHERE activity_id = %d
             AND ((sender_id = %d AND recipient_id = %d)
             OR  (sender_id = %d AND recipient_id = %d))
             ORDER BY created_at DESC LIMIT 1",
            $conv->activity_id, $current_user, $conv->other_user,
            $conv->other_user, $current_user
        ));
        $conv->last_message = $last_msg;

        // Contador de mensajes no leídos
        $unread = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE activity_id = %d
             AND recipient_id = %d
             AND is_read = 0",
            $conv->activity_id, $current_user
        ));
        $conv->unread_count = intval($unread);
    }

    return $conversations;
}

/* ============================================================
 * BORRAR TODOS LOS MENSAJES (solo para debug)
 * ============================================================ */

function ac_clear_all_messages($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';

    $wpdb->query("TRUNCATE TABLE $table");

    return ['success' => true, 'message' => 'Todos los mensajes borrados'];
}

/* ============================================================
 * CAMBIAR AUTOR DE UN PRODUCTO (ejemplo)
 * ============================================================ */

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

/* ============================================================
 * ENVÍO DE EMAIL AL USUARIO CUANDO TIENE MENSAJES SIN LEER
 * ============================================================ */

function ac_send_email_notification($recipient_id, $sender_id, $activity_id, $message) {

    // Comprobar si el usuario estuvo activo recientemente
    $last_seen = get_user_meta($recipient_id, 'ac_last_seen', true);
    $current_time = current_time('timestamp');

    if ($last_seen && ($current_time - $last_seen) < 300) {
        return; // no enviar email si estuvo activo en los últimos 5 min
    }

    $recipient = get_userdata($recipient_id);
    $sender = get_userdata($sender_id);
    $product = get_post($activity_id);

    if (!$recipient || !$sender || !$product) return;

    $subject = 'Nuevo mensaje en ' . get_bloginfo('name');
    $message_preview = mb_substr($message, 0, 100);

    $body = "Hola " . $recipient->display_name . ",\n\n";
    $body .= $sender->display_name . " te ha enviado un mensaje sobre \"" . $product->post_title . "\":\n\n";
    $body .= "\"" . $message_preview . "\"\n\n";
    $body .= "Responde aquí: " . get_permalink($activity_id) . "\n\n";
    $body .= "Saludos,\n" . get_bloginfo('name');

    wp_mail($recipient->user_email, $subject, $body);
}

/* ============================================================
 * ACTUALIZAR "última vez visto"
 * ============================================================ */

add_action('wp_ajax_ac_update_last_seen', 'ac_update_last_seen');
function ac_update_last_seen() {
    $user_id = get_current_user_id();

    if ($user_id) {
        update_user_meta($user_id, 'ac_last_seen', current_time('timestamp'));
        wp_send_json_success();
    }

    wp_send_json_error();
}

/* ============================================================
 * CIERRE MANUAL DE CHAT (usuario o admin)
 * ============================================================ */

function ac_close_chat($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';

    $activity_id = intval($request->get_param('activity_id'));
    $user_id = get_current_user_id();

    if (!$activity_id) {
        return new WP_Error('missing_params', 'Faltan parámetros', 400);
    }

    // Validar si pertenece al chat
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table
         WHERE activity_id = %d
         AND (sender_id = %d OR recipient_id = %d)",
        $activity_id, $user_id, $user_id
    ));

    if (!$exists && !current_user_can('administrator')) {
        return new WP_Error('no_perm', 'No tienes permiso para cerrar este chat.', 403);
    }

    // Marcar como cerrado
    $wpdb->update(
        $table,
        ['is_closed' => 1, 'closed_at' => current_time('mysql')],
        ['activity_id' => $activity_id]
    );

    return ['success' => true, 'message' => 'Chat cerrado correctamente'];
}

/* ============================================================
 * BOTÓN PARA ABRIR CHAT EN LA PÁGINA DE PRODUCTO
 * ============================================================ */

add_action('woocommerce_single_product_summary', 'ac_add_button', 35);
function ac_add_button() {
    if (!is_user_logged_in() || !is_product()) return;

    global $product;
    if (!$product) return;

    $author_id = get_post_field('post_author', $product->get_id());
    $current_user_id = get_current_user_id();

    // No mostrar si el usuario es el dueño del producto
    if ($current_user_id == $author_id) return;

    echo '
    <button id="ac-open-chat"
            data-activity-id="' . esc_attr($product->get_id()) . '"
            data-collab-id="' . esc_attr($author_id) . '"
            style="background:#0073aa;color:white;padding:10px 20px;border:none;
                   cursor:pointer;border-radius:5px;margin-top:10px;">
        💬 Chat con el colaborador
    </button>';
}

/* ============================================================
 * SHORTCODE → Panel del colaborador
 * ============================================================ */

add_shortcode('chat_dashboard_colaborador', 'ac_dashboard_shortcode');
function ac_dashboard_shortcode() {
    if (!is_user_logged_in()) return '<p>Debes iniciar sesión.</p>';

    ob_start(); ?>
        <div id="ac-dashboard">
            <h2>Mis Conversaciones</h2>
            <div id="ac-conversations-list">Cargando...</div>
        </div>
    <?php
    return ob_get_clean();
}

/* ============================================================
 * SHORTCODE → Panel del administrador
 * ============================================================ */

add_shortcode('chat_dashboard_admin', 'ac_admin_dashboard_shortcode');
function ac_admin_dashboard_shortcode() {
    if (!is_user_logged_in() || !current_user_can('administrator')) {
        return '<p>No tienes permisos para acceder a esta sección.</p>';
    }

    ob_start(); ?>
        <div id="ac-admin-dashboard">
            <h2>Todas las Conversaciones</h2>
            <div id="ac-admin-conversations-list">Cargando...</div>
        </div>
    <?php
    return ob_get_clean();
}

/* ============================================================
 * MODAL HTML DEL CHAT (se inyecta en el footer)
 * ============================================================ */

add_action('wp_footer', 'ac_add_modal');
function ac_add_modal() {
    if (!is_user_logged_in()) return; ?>

    <div id="ac-modal" style="display:none;">
        
        <!-- CABECERA DEL CHAT -->
        <div id="ac-header">
            <span id="ac-chat-title">Chat</span>

            <!-- BOTÓN: Finalizar chat -->
            <button id="ac-finish-chat"
                style="background:#d63638;color:white;border:none;
                padding:6px 12px;border-radius:6px;
                cursor:pointer;font-size:13px;margin-right:10px;">
                Finalizar chat
            </button>

            <!-- Botón cerrar ventana -->
            <button id="ac-close">×</button>
        </div>

        <!-- MENSAJES -->
        <div id="ac-messages"></div>

        <!-- CAJA DE TEXTO + BOTÓN ENVIAR -->
        <div id="ac-input-wrap">
            <input type="text" id="ac-input" placeholder="Escribe un mensaje...">
            <button id="ac-send">Enviar</button>
        </div>

    </div>

<?php }

/* ============================================================
 * CIERRE AUTOMÁTICO DE CHATS (más de 5 días sin actividad)
 * ============================================================ */

add_action('ac_daily_close_check', 'ac_close_old_chats');
function ac_close_old_chats() {
    global $wpdb;
    $table = $wpdb->prefix . 'activity_chat';

    // Buscar chats con más de 5 días sin mensajes
    $old_chats = $wpdb->get_results("
        SELECT activity_id, MAX(created_at) AS last_msg
        FROM $table
        WHERE is_closed = 0
        GROUP BY activity_id
        HAVING last_msg < DATE_SUB(NOW(), INTERVAL 5 DAY)
    ");

    // Cerrar cada uno
    foreach ($old_chats as $chat) {
        $wpdb->update(
            $table,
            ['is_closed' => 1, 'closed_at' => current_time('mysql')],
            ['activity_id' => $chat->activity_id]
        );
    }
}

