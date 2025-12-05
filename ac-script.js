jQuery(document).ready(function ($) {
    let currentChat = null;
    let pollInterval = null;
    let lastSeenInterval = null;

    /* -----------------------------------------------------------
       ACTUALIZAR "LAST SEEN"
    ----------------------------------------------------------- */
    function updateLastSeen() {
        $.post(acVars.ajaxUrl, { action: 'ac_update_last_seen' });
    }

    updateLastSeen();
    lastSeenInterval = setInterval(updateLastSeen, 120000);

    /* -----------------------------------------------------------
       ABRIR CHAT DESDE PRODUCTO
    ----------------------------------------------------------- */
    $(document).on('click', '#ac-open-chat', function () {
        const activityId = $(this).data('activity-id');
        const collabId = $(this).data('collab-id');

        openChat(activityId, collabId, 'Colaborador');
    });

    /* -----------------------------------------------------------
       ABRIR CHAT DESDE DASHBOARD USUARIO
    ----------------------------------------------------------- */
    $(document).on('click', '.ac-conversation-item', function () {
        const activityId = $(this).data('activity-id');
        const otherUser = $(this).data('other-user');
        const userName = $(this).data('user-name');

        openChat(activityId, otherUser, userName);
    });

    /* -----------------------------------------------------------
       CERRAR MODAL
    ----------------------------------------------------------- */
    $(document).on('click', '#ac-close', function () {
        $('#ac-modal').hide();
        clearInterval(pollInterval);
        currentChat = null;

        // Rehabilitar input
        $('#ac-input').prop('disabled', false);
        $('#ac-send').prop('disabled', false);
        $('#ac-input-wrap').show();
    });

    /* -----------------------------------------------------------
       FINALIZAR CHAT MANUALMENTE
    ----------------------------------------------------------- */
    $(document).on('click', '#ac-finish-chat', function () {
        if (!currentChat) return;

        if (!confirm("¿Seguro que quieres finalizar este chat?")) return;

        $.ajax({
            url: acVars.restUrl + 'close',
            method: 'POST',
            data: { activity_id: currentChat.activity_id },
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', acVars.nonce);
            },
            success: function () {
                currentChat.closed = 1;

                $('#ac-input').prop('disabled', true);
                $('#ac-send').prop('disabled', true);

                $('#ac-messages').append(
                    '<p id="ac-closed-warning" style="text-align:center;color:#d63638;margin-top:10px;">🔒 Este chat ha sido finalizado.</p>'
                );
            }
        });
    });

    /* -----------------------------------------------------------
       ABRIR CHAT (USUARIO NORMAL)
    ----------------------------------------------------------- */
    function openChat(activityId, otherUserId, userName) {
        currentChat = {
            activity_id: activityId,
            other_user: otherUserId,
            admin_mode: false
        };

        $('#ac-chat-title').text('Chat con ' + userName);
        $('#ac-modal').show();

        $('#ac-input').prop('disabled', false);
        $('#ac-send').prop('disabled', false);
        $('#ac-closed-warning').remove();
        $('#ac-input-wrap').show();

        $('#ac-messages').html('<p style="text-align:center;color:#999;">Cargando...</p>');

        // Marcar leídos
        $.ajax({
            url: acVars.restUrl + 'mark-read',
            method: 'POST',
            data: {
                activity_id: activityId,
                other_user: otherUserId
            },
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', acVars.nonce)
        });

        fetchMessages();

        clearInterval(pollInterval);
        pollInterval = setInterval(fetchMessages, 2000);
    }

    /* -----------------------------------------------------------
       FETCH GENERAL DE MENSAJES (USUARIO NORMAL)
       DEVUELVE → { closed: 0/1, messages: [...] }
    ----------------------------------------------------------- */
    function fetchMessages() {
        if (!currentChat || currentChat.admin_mode) return;

        $.ajax({
            url: acVars.restUrl + 'fetch',
            method: 'GET',
            data: {
                activity_id: currentChat.activity_id,
                other_user: currentChat.other_user
            },
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', acVars.nonce),
            success: function (response) {

                const messages = response.messages ?? [];

                currentChat.closed = response.closed;

                const messagesDiv = document.getElementById('ac-messages');
                const wasAtBottom =
                    messagesDiv.scrollHeight - messagesDiv.scrollTop <= messagesDiv.clientHeight + 50;

                // Control de bloqueo por chat cerrado
                if (response.closed === 1) {
                    $('#ac-input').prop('disabled', true);
                    $('#ac-send').prop('disabled', true);

                    if (!$('#ac-closed-warning').length) {
                        $('#ac-messages').append(
                            '<p id="ac-closed-warning" style="text-align:center;color:#d63638;margin-top:10px;">🔒 Este chat ha sido cerrado.</p>'
                        );
                    }
                } else {
                    $('#ac-input').prop('disabled', false);
                    $('#ac-send').prop('disabled', false);
                    $('#ac-closed-warning').remove();
                }

                renderMessages(messages);

                if (wasAtBottom) {
                    messagesDiv.scrollTop = messagesDiv.scrollHeight;
                }
            }
        });
    }

    /* -----------------------------------------------------------
       RENDER DE MENSAJES (USUARIO)
    ----------------------------------------------------------- */
    function renderMessages(messages) {
        if (!messages || messages.length === 0) {
            $('#ac-messages').html(
                '<p style="text-align:center;color:#999;padding:20px;">No hay mensajes</p>'
            );
            return;
        }

        let html = '';

        messages.forEach(msg => {
            const isMine = msg.sender_id == acVars.userId;
            const className = isMine ? 'ac-msg-me' : 'ac-msg-them';
            html += `<div class="ac-msg ${className}">${msg.message}</div>`;
        });

        $('#ac-messages').html(html);
    }

    /* -----------------------------------------------------------
       ENVIAR MENSAJE
    ----------------------------------------------------------- */
    function sendMessage() {
        if (!currentChat || currentChat.closed == 1) {
            alert("Este chat está cerrado.");
            return;
        }

        const message = $('#ac-input').val().trim();
        if (!message) return;

        $.ajax({
            url: acVars.restUrl + 'send',
            method: 'POST',
            data: {
                activity_id: parseInt(currentChat.activity_id),
                recipient_id: parseInt(currentChat.other_user),
                message: message
            },
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', acVars.nonce),
            success: function (response) {
                if (response.success) {
                    $('#ac-input').val('');
                    fetchMessages();
                }
            }
        });
    }

    $(document).on('click', '#ac-send', sendMessage);
    $(document).on('keypress', '#ac-input', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            sendMessage();
        }
    });

    /* -----------------------------------------------------------
       DASHBOARD USUARIO: LISTA DE CHATS
    ----------------------------------------------------------- */
    function loadConversations() {
        if (!$('#ac-dashboard').length) return;

        $.ajax({
            url: acVars.restUrl + 'conversations',
            method: 'GET',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', acVars.nonce),
            success: function (conversations) {

                if (!conversations || conversations.length === 0) {
                    $('#ac-conversations-list').html('<p>No hay conversaciones activas.</p>');
                    return;
                }

                let html = '<div class="ac-conversations">';

                conversations.forEach((conv) => {
                    html += `
                        <div class="ac-conversation-item"
                             data-activity-id="${conv.activity_id}"
                             data-other-user="${conv.other_user}"
                             data-user-name="${conv.other_user_name}">
                            <div>
                                <strong>${conv.other_user_name}</strong>
                                <small>${conv.product_name}</small>
                                <small style="color:#999;">${conv.last_message ?? ''}</small>
                            </div>
                            <div>
                                ${conv.unread_count > 0 ? `<span class="ac-unread">${conv.unread_count}</span>` : ''}
                            </div>
                        </div>`;
                });

                html += '</div>';

                $('#ac-conversations-list').html(html);
            }
        });
    }

    loadConversations();
    if ($('#ac-dashboard').length) setInterval(loadConversations, 5000);

    /* -----------------------------------------------------------
       ADMIN — CARGAR TODAS LAS CONVERSACIONES
    ----------------------------------------------------------- */
    function loadAllConversationsAdmin() {
        if (!$('#ac-admin-dashboard').length) return;

        $.ajax({
            url: acVars.restUrl + 'admin/all-conversations',
            method: 'GET',
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', acVars.nonce),
            success: function (conversations) {

                if (!conversations || conversations.length === 0) {
                    $('#ac-admin-conversations-list').html('<p>No hay conversaciones en el sistema.</p>');
                    return;
                }

                let html = '<div class="ac-admin-conversations">';

                conversations.forEach((conv) => {
                    html += `
                        <div class="ac-admin-conversation-item"
                             data-activity-id="${conv.activity_id}"
                             data-user1="${conv.user1_id}"
                             data-user2="${conv.user2_id}">
                            
                            <div class="ac-admin-conv-header">
                                <strong>${conv.user1_name} ↔ ${conv.user2_name}</strong>
                                <span class="ac-admin-msg-count">${conv.total_messages} mensajes</span>
                            </div>

                            <div class="ac-admin-conv-details">
                                <div class="ac-admin-product">📦 ${conv.product_name}</div>

                                ${conv.last_message ? `
                                    <div class="ac-admin-last-msg">
                                        <strong>${conv.last_sender}:</strong> ${conv.last_message}
                                    </div>` : ''}
                            </div>

                            <button class="ac-admin-view-btn"
                                    data-activity-id="${conv.activity_id}"
                                    data-user1="${conv.user1_id}"
                                    data-user2="${conv.user2_id}"
                                    data-user1-name="${conv.user1_name}"
                                    data-user2-name="${conv.user2_name}"
                                    data-product="${conv.product_name}">
                                Ver conversación completa
                            </button>
                        </div>`;
                });

                html += '</div>';

                $('#ac-admin-conversations-list').html(html);
            }
        });
    }

    if ($('#ac-admin-dashboard').length) {
        loadAllConversationsAdmin();
        setInterval(loadAllConversationsAdmin, 10000);
    }

    /* -----------------------------------------------------------
       ADMIN — ABRIR CONVERSACIÓN
    ----------------------------------------------------------- */
    $(document).on('click', '.ac-admin-view-btn', function () {
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

        $('#ac-chat-title').html(
            `👁️ ${user1Name} ↔ ${user2Name}<br><small>📦 ${productName}</small>`
        );

        $('#ac-modal').addClass('ac-admin-mode').show();
        $('#ac-input-wrap').hide();
        $('#ac-messages').html('<p style="text-align:center;color:#999;">Cargando...</p>');

        fetchMessagesAdmin();

        clearInterval(pollInterval);
        pollInterval = setInterval(fetchMessagesAdmin, 3000);
    }

    /* -----------------------------------------------------------
       ADMIN — FETCH MENSAJES
    ----------------------------------------------------------- */
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
            beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', acVars.nonce),
            success: function (messages) {

                const messagesDiv = document.getElementById('ac-messages');
                const wasAtBottom =
                    messagesDiv.scrollHeight - messagesDiv.scrollTop <= messagesDiv.clientHeight + 50;

                renderMessagesAdmin(messages);

                if (wasAtBottom) {
                    messagesDiv.scrollTop = messagesDiv.scrollHeight;
                }
            }
        });
    }

    /* -----------------------------------------------------------
       ADMIN — RENDER MENSAJES
    ----------------------------------------------------------- */
    function renderMessagesAdmin(messages) {
        if (!messages || messages.length === 0) {
            $('#ac-messages').html('<p style="text-align:center;color:#999;">No hay mensajes</p>');
            return;
        }

        let html = '';

        messages.forEach((msg) => {
            const date = new Date(msg.created_at);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();

            html += `
                <div class="ac-admin-msg">
                    <div class="ac-admin-msg-header">
                        <strong>${msg.sender_name}</strong>
                        <span class="ac-admin-msg-time">${dateStr}</span>
                    </div>
                    <div class="ac-admin-msg-text">${msg.message}</div>
                </div>`;
        });

        $('#ac-messages').html(html);
    }

});
