jQuery(document).ready(function($) {
    let currentChat = null;
    let pollInterval = null;
    let lastSeenInterval = null;

    // Registrar actividad del usuario cada 2 minutos
    function updateLastSeen() {
        $.post(acVars.ajaxUrl, {
            action: 'ac_update_last_seen'
        });
    }
    
    // Actualizar al cargar y cada 2 minutos
    updateLastSeen();
    lastSeenInterval = setInterval(updateLastSeen, 120000);

    // Abrir chat desde botón en producto
    $(document).on('click', '#ac-open-chat', function() {
        const activityId = $(this).data('activity-id');
        const collabId = $(this).data('collab-id');
        
        console.log('Abriendo chat desde producto:', {
            activityId: activityId,
            collabId: collabId,
            currentUser: acVars.userId
        });
        
        openChat(activityId, collabId, 'Colaborador');
    });

    // Abrir chat desde dashboard
    $(document).on('click', '.ac-conversation-item', function() {
        const activityId = $(this).data('activity-id');
        const otherUser = $(this).data('other-user');
        const userName = $(this).data('user-name');
        
        openChat(activityId, otherUser, userName);
    });

    // Cerrar modal
    $(document).on('click', '#ac-close', function() {
        $('#ac-modal').hide();
        clearInterval(pollInterval);
        currentChat = null;
    });

    // Enviar mensaje
    $(document).on('click', '#ac-send', sendMessage);
    $(document).on('keypress', '#ac-input', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            sendMessage();
        }
    });

    function openChat(activityId, otherUserId, userName) {
        currentChat = {
            activity_id: activityId,
            other_user: otherUserId
        };
        
        $('#ac-chat-title').text('Chat con ' + userName);
        $('#ac-modal').show();
        $('#ac-messages').html('<p style="text-align:center;color:#999;">Cargando...</p>');
        
        // Marcar mensajes como leídos
        $.ajax({
            url: acVars.restUrl + 'mark-read',
            method: 'POST',
            data: {
                activity_id: activityId,
                other_user: otherUserId
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', acVars.nonce);
            }
        });
        
        fetchMessages();
        
        // Polling cada 2 segundos
        clearInterval(pollInterval);
        pollInterval = setInterval(fetchMessages, 2000);
    }

    function fetchMessages() {
        if (!currentChat) return;
        
        $.ajax({
            url: acVars.restUrl + 'fetch',
            method: 'GET',
            data: {
                activity_id: currentChat.activity_id,
                other_user: currentChat.other_user
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', acVars.nonce);
            },
            success: function(messages) {
                // Guardar posición del scroll antes de actualizar
                const messagesDiv = document.getElementById('ac-messages');
                const wasAtBottom = messagesDiv && (messagesDiv.scrollHeight - messagesDiv.scrollTop <= messagesDiv.clientHeight + 50);
                
                renderMessages(messages);
                
                // Solo hacer scroll si estábamos al final (para no interrumpir si el usuario está leyendo arriba)
                if (wasAtBottom && messagesDiv) {
                    messagesDiv.scrollTop = messagesDiv.scrollHeight;
                }
            }
        });
    }

    function renderMessages(messages) {
        if (!messages || messages.length === 0) {
            const currentHtml = $('#ac-messages').html();
            const emptyHtml = '<p style="text-align:center;color:#999;padding:20px;">No hay mensajes</p>';
            if (currentHtml !== emptyHtml) {
                $('#ac-messages').html(emptyHtml);
            }
            return;
        }
        
        let html = '';
        messages.forEach(function(msg) {
            const isMine = msg.sender_id == acVars.userId;
            const className = isMine ? 'ac-msg-me' : 'ac-msg-them';
            html += '<div class="ac-msg ' + className + '">' + msg.message + '</div>';
        });
        
        // Solo actualizar si el contenido cambió (evitar parpadeo)
        if ($('#ac-messages').html() !== html) {
            $('#ac-messages').html(html);
        }
    }

    function sendMessage() {
        if (!currentChat) return;
        
        const message = $('#ac-input').val().trim();
        if (!message) return;
        
        const sendData = {
            activity_id: parseInt(currentChat.activity_id),
            recipient_id: parseInt(currentChat.other_user),
            message: message
        };
        
        console.log('Enviando mensaje:', sendData);
        console.log('currentChat:', currentChat);
        
        $.ajax({
            url: acVars.restUrl + 'send',
            method: 'POST',
            data: sendData,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', acVars.nonce);
            },
            success: function(response) {
                console.log('Respuesta send:', response);
                if (response.success) {
                    $('#ac-input').val('');
                    fetchMessages();
                }
            },
            error: function(xhr) {
                console.error('Error enviando:', xhr);
            }
        });
    }

    // Cargar conversaciones en dashboard
    function loadConversations() {
        if ($('#ac-dashboard').length === 0) return;
        
        console.log('Cargando conversaciones para usuario:', acVars.userId);
        
        $.ajax({
            url: acVars.restUrl + 'conversations',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', acVars.nonce);
            },
            success: function(conversations) {
                console.log('Conversaciones recibidas:', conversations);
                
                if (!conversations || conversations.length === 0) {
                    $('#ac-conversations-list').html('<p>No hay conversaciones activas.</p>');
                    return;
                }
                
                // Solo actualizar si hay cambios (evitar parpadeo)
                let newHtml = '<div class="ac-conversations">';
                conversations.forEach(function(conv) {
                    newHtml += '<div class="ac-conversation-item" ' +
                           'data-activity-id="' + conv.activity_id + '" ' +
                           'data-other-user="' + conv.other_user + '" ' +
                           'data-user-name="' + conv.other_user_name + '">' +
                           '<div>' +
                           '<strong>' + conv.other_user_name + '</strong>' +
                           '<small style="display:block;margin-top:5px;">' + conv.product_name + '</small>';
                    
                    if (conv.last_message) {
                        newHtml += '<small style="display:block;margin-top:8px;color:#999;">' + 
                               conv.last_message.substring(0, 50) + (conv.last_message.length > 50 ? '...' : '') + 
                               '</small>';
                    }
                    
                    newHtml += '</div><div>';
                    
                    if (conv.unread_count > 0) {
                        newHtml += '<span class="ac-unread">' + conv.unread_count + ' nuevo' + (conv.unread_count > 1 ? 's' : '') + '</span>';
                    }
                    
                    newHtml += '</div></div>';
                });
                newHtml += '</div>';
                
                // Solo actualizar si el HTML cambió
                if ($('#ac-conversations-list').html() !== newHtml) {
                    $('#ac-conversations-list').html(newHtml);
                }
            },
            error: function(xhr) {
                console.error('Error cargando conversaciones:', xhr);
            }
        });
    }

    // Cargar conversaciones si estamos en el dashboard
    loadConversations();
    
    // Recargar conversaciones cada 5 segundos si estamos en el dashboard
    if ($('#ac-dashboard').length > 0) {
        setInterval(loadConversations, 5000);
    }

    // ========== FUNCIONES PARA ADMINISTRADOR ==========
    
    // Cargar todas las conversaciones (solo admin)
    function loadAllConversationsAdmin() {
        if ($('#ac-admin-dashboard').length === 0) return;
        
        $.ajax({
            url: acVars.restUrl + 'admin/all-conversations',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', acVars.nonce);
            },
            success: function(conversations) {
                console.log('Conversaciones admin recibidas:', conversations);
                
                if (!conversations || conversations.length === 0) {
                    const emptyHtml = '<p>No hay conversaciones en el sistema.</p>';
                    if ($('#ac-admin-conversations-list').html() !== emptyHtml) {
                        $('#ac-admin-conversations-list').html(emptyHtml);
                    }
                    return;
                }
                
                // Renderizar conversaciones
                let html = '<div class="ac-admin-conversations">';
                conversations.forEach(function(conv) {
                    html += '<div class="ac-admin-conversation-item" ' +
                           'data-activity-id="' + conv.activity_id + '" ' +
                           'data-user1="' + conv.user1_id + '" ' +
                           'data-user2="' + conv.user2_id + '">' +
                           '<div class="ac-admin-conv-header">' +
                           '<strong>' + conv.user1_name + ' ↔ ' + conv.user2_name + '</strong>' +
                           '<span class="ac-admin-msg-count">' + conv.total_messages + ' mensajes</span>' +
                           '</div>' +
                           '<div class="ac-admin-conv-details">' +
                           '<div class="ac-admin-product">📦 ' + conv.product_name + '</div>';
                    
                    if (conv.last_message) {
                        const date = new Date(conv.last_message_date);
                        const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
                        html += '<div class="ac-admin-last-msg">' +
                               '<strong>' + conv.last_sender + ':</strong> ' +
                               conv.last_message.substring(0, 80) + (conv.last_message.length > 80 ? '...' : '') +
                               '</div>' +
                               '<div class="ac-admin-date">' + dateStr + '</div>';
                    }
                    
                    html += '</div>' +
                           '<button class="ac-admin-view-btn" ' +
                           'data-activity-id="' + conv.activity_id + '" ' +
                           'data-user1="' + conv.user1_id + '" ' +
                           'data-user2="' + conv.user2_id + '" ' +
                           'data-user1-name="' + conv.user1_name + '" ' +
                           'data-user2-name="' + conv.user2_name + '" ' +
                           'data-product="' + conv.product_name + '">' +
                           'Ver conversación completa' +
                           '</button>' +
                           '</div>';
                });
                html += '</div>';
                
                // Solo actualizar si el HTML cambió (evitar parpadeo)
                if ($('#ac-admin-conversations-list').html() !== html) {
                    $('#ac-admin-conversations-list').html(html);
                }
            },
            error: function(xhr) {
                console.error('Error cargando conversaciones admin:', xhr);
                $('#ac-admin-conversations-list').html('<p>Error al cargar conversaciones.</p>');
            }
        });
    }
    
    // Abrir conversación en modo admin (solo lectura)
    $(document).on('click', '.ac-admin-view-btn', function() {
        const activityId = $(this).data('activity-id');
        const user1 = $(this).data('user1');
        const user2 = $(this).data('user2');
        const user1Name = $(this).data('user1-name');
        const user2Name = $(this).data('user2-name');
        const productName = $(this).data('product');
        
        openChatAdmin(activityId, user1, user2, user1Name, user2Name, productName);
    });
    
    function openChatAdmin(activityId, user1, user2, user1Name, user2Name, productName) {
        currentChat = {
            activity_id: activityId,
            user1: user1,
            user2: user2,
            admin_mode: true
        };
        
        $('#ac-chat-title').html('👁️ ' + user1Name + ' ↔ ' + user2Name + '<br><small style="font-size:12px;font-weight:normal;">📦 ' + productName + '</small>');
        $('#ac-modal').addClass('ac-admin-mode').show();
        $('#ac-messages').html('<p style="text-align:center;color:#999;">Cargando...</p>');
        
        // Ocultar input en modo admin
        $('#ac-input-wrap').hide();
        
        fetchMessagesAdmin();
        
        // Polling cada 3 segundos
        clearInterval(pollInterval);
        pollInterval = setInterval(fetchMessagesAdmin, 3000);
    }
    
    function fetchMessagesAdmin() {
        if (!currentChat || !currentChat.admin_mode) return;
        
        $.ajax({
            url: acVars.restUrl + 'admin/conversation-messages',
            method: 'GET',
            data: {
                activity_id: currentChat.activity_id,
                user1: currentChat.user1,
                user2: currentChat.user2
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', acVars.nonce);
            },
            success: function(messages) {
                const messagesDiv = document.getElementById('ac-messages');
                const wasAtBottom = messagesDiv && (messagesDiv.scrollHeight - messagesDiv.scrollTop <= messagesDiv.clientHeight + 50);
                
                renderMessagesAdmin(messages);
                
                if (wasAtBottom && messagesDiv) {
                    messagesDiv.scrollTop = messagesDiv.scrollHeight;
                }
            }
        });
    }
    
    function renderMessagesAdmin(messages) {
        if (!messages || messages.length === 0) {
            $('#ac-messages').html('<p style="text-align:center;color:#999;padding:20px;">No hay mensajes</p>');
            return;
        }
        
        let html = '';
        messages.forEach(function(msg) {
            const date = new Date(msg.created_at);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
            
            html += '<div class="ac-admin-msg">' +
                   '<div class="ac-admin-msg-header">' +
                   '<strong>' + msg.sender_name + '</strong>' +
                   '<span class="ac-admin-msg-time">' + dateStr + '</span>' +
                   '</div>' +
                   '<div class="ac-admin-msg-text">' + msg.message + '</div>' +
                   '</div>';
        });
        
        $('#ac-messages').html(html);
    }
    
    // Modificar el cerrar modal para limpiar modo admin
    $(document).off('click', '#ac-close').on('click', '#ac-close', function() {
        $('#ac-modal').removeClass('ac-admin-mode').hide();
        $('#ac-input-wrap').show();
        clearInterval(pollInterval);
        currentChat = null;
    });
    
    // Cargar conversaciones admin si estamos en el dashboard de admin
    if ($('#ac-admin-dashboard').length > 0) {
        loadAllConversationsAdmin();
        // Recargar cada 10 segundos
        setInterval(loadAllConversationsAdmin, 10000);
    }
});
