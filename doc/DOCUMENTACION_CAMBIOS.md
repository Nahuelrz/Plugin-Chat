# 📋 Documentación de Cambios - Activity Chat Plugin

## 🎯 Resumen General

Se implementaron mejoras críticas para solucionar errores 500 y añadir funcionalidades completas de notificaciones por email y gestión de conversaciones para administradores.

---

## 🔧 Problemas Solucionados

### 1. **Error 500 en endpoint `/admin/all-conversations`**

**Problema:** El endpoint REST estaba registrado pero las funciones callback no existían.

**Solución:** Se crearon todas las funciones faltantes:

- ✅ `ac_get_all_conversations_admin()` - Obtiene todas las conversaciones del sistema
- ✅ `ac_get_conversation_messages_admin()` - Obtiene mensajes de una conversación específica
- ✅ `ac_get_conversations()` - Obtiene conversaciones del usuario actual
- ✅ `ac_clear_all_messages()` - Limpia todos los mensajes (desarrollo)

---

### 2. **Error "conversations.forEach is not a function"**

**Problema:** Las funciones PHP retornaban `{conversations: [...]}` pero JavaScript esperaba un array directo.

**Solución:** Se modificó el retorno de las funciones para devolver arrays directamente:

```php
// Antes
return ['conversations' => $conversations];

// Después  
return $conversations;
```

---

### 3. **Consultas SQL problemáticas**

**Problema:** Las subconsultas SQL usaban referencias a alias que causaban errores.

**Solución:** Se separaron las consultas complejas en queries más simples:

```php
// Query principal simplificada
$results = $wpdb->get_results("SELECT activity_id, ... FROM {$table}");

// Último mensaje obtenido en bucle PHP
$last_msg = $wpdb->get_row($wpdb->prepare("SELECT message FROM ..."));
```

---

### 4. **Error "loadAllConversationsAdmin is not defined"**

**Problema:** Inconsistencia en nombres de funciones JavaScript.

**Solución:** Se corrigieron las llamadas para usar el nombre correcto:

```javascript
// Antes
loadAllConversationsAdmin();

// Después
loadAdminConversations();
```

---

## 🆕 Funcionalidades Añadidas

### **Sistema de Notificaciones por Email**

#### 📧 Notificación Inmediata al Recibir Mensaje

**Función:** `ac_send_email_notification()`

**Cómo funciona:**
- Se envía email solo si el destinatario está inactivo por más de 5 minutos
- Verifica el meta `ac_last_seen` del usuario
- Registra el envío en un log (`ac_email_log`)

**Código:**
```php
function ac_send_email_notification($recipient_id, $sender_id, $activity_id, $message) {
    $last_seen = get_user_meta($recipient_id, 'ac_last_seen', true);
    
    if (!$last_seen || (time() - $last_seen) > 300) {
        // Enviar email
        wp_mail($recipient->user_email, $subject, $body);
    }
}
```

---

#### ⏰ Notificación Automática por Mensajes Sin Leer (CRON)

**Función:** `ac_check_unread_messages()`

**Frecuencia:** Cada 15 minutos

**Cómo funciona:**
- Busca mensajes no leídos con más de 30 minutos de antigüedad
- Agrupa por destinatario y producto
- Envía un email consolidado con el total de mensajes pendientes

**Configuración del CRON:**
```php
add_action('ac_check_unread_messages_event', 'ac_check_unread_messages');

add_filter('cron_schedules', function($s) {
    $s['fifteen_minutes'] = [
        'interval' => 15 * 60,
        'display'  => 'Cada 15 minutos'
    ];
    return $s;
});
```

---

#### 📊 Registro de Última Actividad

**Función:** `ac_update_last_seen()`

**Cómo funciona:**
- JavaScript llama vía AJAX cada 2 minutos
- Actualiza `user_meta` con timestamp actual
- Permite determinar si el usuario está activo

**JavaScript:**
```javascript
function updateLastSeen() {
    $.post(acVars.ajaxUrl, {
        action: 'ac_update_last_seen'
    });
}

setInterval(updateLastSeen, 120000); // Cada 2 minutos
```

---

### **Dashboard de Administrador Completo**

#### 👁️ Ver Todas las Conversaciones

**Función:** `ac_get_all_conversations_admin()`

**Características:**
- Muestra TODAS las conversaciones del sistema
- Agrupa por producto y par de usuarios
- Incluye información completa:
  - Nombres de ambos usuarios
  - Producto relacionado
  - Total de mensajes
  - Último mensaje con remitente
  - Fecha del último mensaje
  - Estado (abierto/cerrado)

**Datos retornados:**
```php
[
    'activity_id' => 123,
    'user1_id' => 5,
    'user2_id' => 8,
    'user1_name' => 'Juan Pérez',
    'user2_name' => 'María López',
    'product_name' => 'Producto X',
    'last_message' => 'Texto del último mensaje',
    'last_message_date' => '2025-12-02 14:30:00',
    'last_sender' => 'Juan Pérez',
    'total_messages' => 15,
    'is_closed' => 0
]
```

---

#### 💬 Ver Conversación Completa (Modo Admin)

**Función:** `ac_get_conversation_messages_admin()`

**Características:**
- Vista de solo lectura para administradores
- Muestra todos los mensajes entre dos usuarios
- No permite responder (solo monitoreo)
- Se actualiza automáticamente cada 3 segundos

**Uso en JavaScript:**
```javascript
function openChatAdmin(activityId, user1, user2, user1Name, user2Name, productName) {
    currentChat = {
        activity_id: activityId,
        user1: user1,
        user2: user2,
        admin_mode: true
    };
    
    // Ocultar input de mensaje
    $('#ac-input-wrap').hide();
    
    fetchMessagesAdmin();
    pollInterval = setInterval(fetchMessagesAdmin, 3000);
}
```

---

## 📁 Estructura de Archivos Modificados

### **activity-chat.php**

#### Nuevas Funciones Añadidas:

1. **`ac_get_conversations()`** - Línea ~295
   - Obtiene conversaciones del usuario actual
   - Filtra solo conversaciones no cerradas
   - Cuenta mensajes no leídos

2. **`ac_get_all_conversations_admin()`** - Línea ~352
   - Obtiene todas las conversaciones del sistema
   - Sin filtros por usuario
   - Información completa incluyendo contadores

3. **`ac_get_conversation_messages_admin()`** - Línea ~430
   - Obtiene mensajes entre dos usuarios específicos
   - Solo lectura para administrador

4. **`ac_send_email_notification()`** - Línea ~170
   - Envía email si usuario inactivo >5 min
   - Registra en log de emails

5. **`ac_update_last_seen()`** - Línea ~200
   - Acción AJAX para actualizar actividad
   - Guarda timestamp en user_meta

6. **`ac_clear_all_messages()`** - Línea ~280
   - Limpia tabla completa (solo desarrollo)
   - Usa TRUNCATE TABLE

---

### **ac-script.js**

#### Funciones Añadidas/Modificadas:

1. **`updateLastSeen()`** - Línea ~6
   - Llama cada 2 minutos
   - Mantiene registro de actividad

2. **`loadAdminConversations()`** - Línea ~242
   - Carga todas las conversaciones para admin
   - Se ejecuta cada 10 segundos
   - Renderiza lista completa con contadores

3. **`openChatAdmin()`** - Línea ~320
   - Abre chat en modo solo lectura
   - Oculta input de mensaje
   - Polling cada 3 segundos

4. **`fetchMessagesAdmin()`** - Línea ~340
   - Obtiene mensajes para vista admin
   - Usa endpoint especial de admin

---

## 🎨 Mejoras en Interfaz de Usuario

### Dashboard de Administrador

**Elementos visuales añadidos:**

- 🔒 Badge para chats cerrados
- 📦 Icono de producto
- 💬 Contador de mensajes totales
- 📅 Fecha y hora del último mensaje
- 👤 Nombre del remitente del último mensaje
- 🔄 Actualización automática cada 10 segundos

**HTML generado:**
```html
<div class="ac-admin-conversation-item">
    <div class="ac-admin-conv-header">
        <strong>Usuario1 ↔ Usuario2</strong> 🔒 Cerrado
        <span class="ac-admin-msg-count">15 mensajes</span>
    </div>
    <div class="ac-admin-conv-details">
        <div class="ac-admin-product">📦 Producto X</div>
        <div class="ac-admin-last-msg">
            <strong>Usuario1:</strong> Último mensaje...
        </div>
        <div class="ac-admin-date">02/12/2025 14:30:00</div>
    </div>
    <button class="ac-admin-view-btn">Ver conversación</button>
</div>
```

---

## 🔐 Endpoints REST API

### Nuevos Endpoints:

| Endpoint | Método | Permiso | Función |
|----------|--------|---------|---------|
| `/conversations` | GET | Usuario logueado | `ac_get_conversations()` |
| `/admin/all-conversations` | GET | Administrador | `ac_get_all_conversations_admin()` |
| `/admin/conversation-messages` | GET | Administrador | `ac_get_conversation_messages_admin()` |
| `/clear-all` | POST | Usuario logueado | `ac_clear_all_messages()` |

---

## ⚙️ Configuración de CRON Jobs

### Jobs Activos:

1. **`ac_check_unread_messages_event`**
   - Frecuencia: Cada 15 minutos
   - Función: Enviar emails por mensajes sin leer

2. **`ac_close_inactive_chats_event`**
   - Frecuencia: Diaria
   - Función: Cerrar chats inactivos por más de 5 días

---

## 🐛 Debugging y Logs

### Logs Implementados:

```php
// En ac_get_all_conversations_admin()
if ($wpdb->last_error) {
    return new WP_Error('sql_error', 'Error SQL: ' . $wpdb->last_error);
}

if (empty($results)) {
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    return new WP_Error('no_data', "No hay conversaciones. Total: {$count}");
}
```

### Logs en JavaScript:

```javascript
console.log('Conversaciones admin:', conversations);
console.error('Status:', xhr.status);
console.error('Response:', xhr.responseJSON || xhr.responseText);
```

---

## 📊 Base de Datos

### Tabla: `wp_activity_chat`

**Campos modificados/añadidos:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `is_closed` | tinyint(1) | Indica si el chat está cerrado |

### User Meta Añadido:

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `ac_last_seen` | timestamp | Última actividad del usuario |

---

## 🚀 Mejoras de Rendimiento

1. **Consultas SQL optimizadas** - Separadas en queries simples
2. **Polling inteligente** - 10s para admin, 3s para chat activo
3. **Actualización condicional** - Solo si hay cambios en el HTML
4. **Logs limitados** - Solo últimos 50 emails en log

---

## 📝 Shortcodes Disponibles

### Para Colaboradores:
```php
[chat_dashboard_colaborador]
```
Muestra las conversaciones del usuario actual.

### Para Administradores:
```php
[chat_dashboard_admin]
```
Muestra TODAS las conversaciones del sistema.

---

## ✅ Checklist de Funcionalidades

- [x] Chat en tiempo real entre usuarios
- [x] Notificaciones inmediatas por email (si usuario inactivo)
- [x] Notificaciones automáticas por mensajes sin leer (CRON)
- [x] Dashboard de colaborador con sus conversaciones
- [x] Dashboard de administrador con TODAS las conversaciones
- [x] Vista de solo lectura para administrador
- [x] Cierre manual de chats
- [x] Cierre automático de chats inactivos (5 días)
- [x] Registro de última actividad del usuario
- [x] Log de emails enviados
- [x] Actualización automática de conversaciones
- [x] Contador de mensajes no leídos
- [x] Contador total de mensajes por conversación
- [x] Indicador de chats cerrados
- [x] Botón de chat en productos WooCommerce

---

## 🔄 Flujo de Trabajo Completo

### 1. Usuario envía mensaje:
```
Usuario escribe → ac_send_message() → 
Guardar en DB → ac_send_email_notification() → 
¿Usuario inactivo? → Enviar email
```

### 2. CRON revisa mensajes sin leer:
```
Cada 15 min → ac_check_unread_messages() → 
Buscar mensajes >30 min sin leer → 
Agrupar por usuario → Enviar email consolidado
```

### 3. Usuario actualiza actividad:
```
Cada 2 min → updateLastSeen() → 
AJAX ac_update_last_seen → 
Actualizar user_meta con timestamp
```

### 4. Admin revisa conversaciones:
```
Cargar dashboard → loadAdminConversations() → 
ac_get_all_conversations_admin() → 
Renderizar lista → Actualizar cada 10s
```

---

## 🎓 Notas para Desarrollo

### Testing:
```php
// Para probar notificaciones, reducir tiempo de inactividad:
if (!$last_seen || (time() - $last_seen) > 60) { // 1 minuto en lugar de 5
```

### Depuración:
```php
// Activar logs de WordPress en wp-config.php:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Limpiar datos de prueba:
```
POST /wp-json/activity-chat/v1/clear-all
```

---

**Última actualización:** 2 de diciembre de 2025
**Versión del plugin:** 1.0
**Desarrolladores:** Nahuel & Jonathan
