let supportWorkspaceCleanup = null;
let supportWorkspaceSwitching = false;

// 提示音/通知的跨会话状态：无刷新切换会重跑 boot，这个对象必须挂在模块级。
const supportAlertState = window.__supportAlertState || (window.__supportAlertState = { lastAt: 0, audio: null });

function bootSupportWorkspace() {
    if (supportWorkspaceCleanup) {
        try { supportWorkspaceCleanup(); } catch (error) { /* 旧实例清理失败不阻断新实例 */ }
        supportWorkspaceCleanup = null;
    }

    const root = document.getElementById('support-workspace');
    if (!root) return;

    const config = JSON.parse(root.dataset.supportConfig);
    const cleanups = [];

    const stream = document.getElementById('message-stream');
    const list = document.getElementById('message-list');
    const form = document.getElementById('reply-form');
    const input = document.getElementById('ticket-reply');
    const submit = form ? form.querySelector('button[type="submit"]') : null;
    const presenceToggle = document.getElementById('presence-toggle');
    const presenceDot = document.getElementById('presence-dot');
    const presenceCopy = document.getElementById('presence-copy');
    const onlineCount = document.getElementById('online-agent-count');
    const claimButton = document.getElementById('claim-button');
    const claimHint = document.getElementById('claim-hint');
    const releaseButton = document.getElementById('release-button');
    const conversationList = document.getElementById('conversation-list');
    const queueCount = document.getElementById('queue-count');
    const mineCount = document.getElementById('mine-count');
    const unreadCount = document.getElementById('unread-count');
    const asyncCount = document.getElementById('async-count');
    const waitingCount = document.getElementById('waiting-count');
    const inboxTotal = document.getElementById('inbox-total-count');
    const inboxSearch = document.getElementById('inbox-search');
    const inboxFilterButtons = document.querySelectorAll('[data-inbox-filter]');
    const inboxFilterEmpty = document.getElementById('inbox-filter-empty');
    const soundToggle = document.getElementById('sound-toggle');
    const notifyToggle = document.getElementById('notify-toggle');
    const statNew = document.getElementById('stat-new');
    const statClosed = document.getElementById('stat-closed');
    const statWaiting = document.getElementById('stat-waiting');
    const statOverdue = document.getElementById('stat-overdue');
    const statFirst = document.getElementById('stat-first');
    const statRating = document.getElementById('stat-rating');
    const assigneeNameEl = document.getElementById('assignee-name');
    const listSyncState = document.getElementById('list-sync-state');
    const chatSyncState = document.getElementById('chat-sync-state');
    const newMessageTip = document.getElementById('new-message-tip');
    const statusForm = document.getElementById('status-form');
    const statusBadge = document.getElementById('chat-status-badge');
    const statusFeedback = document.getElementById('status-feedback');
    const toast = document.getElementById('workspace-toast');
    const heldBadge = document.getElementById('chat-held-badge');
    const customerTyping = document.getElementById('customer-typing');
    const heldCount = document.getElementById('held-count');
    const statHeld = document.getElementById('stat-held');
    const transferButton = document.getElementById('transfer-button');
    const transferAgent = document.getElementById('transfer-agent');
    const holdButton = document.getElementById('hold-button');
    const holdReason = document.getElementById('hold-reason');
    const resumeButton = document.getElementById('resume-button');
    const quickStatsToggle = document.getElementById('quick-stats-toggle');
    const quickStatsPanel = document.getElementById('quick-stats-panel');
    const composerModeButtons = document.querySelectorAll('[data-composer-mode]');
    const composerHint = document.getElementById('composer-mode-hint');

    let isOnline = config.initialOnline;
    let status = config.status;
    let assigneeId = config.assigneeId;
    let isHeld = !!config.held;
    let composerMode = 'reply';
    let lastQuickReplyKey = null;
    let lastTypingPingAt = 0;
    let lastMessageId = config.initialLastMessageId;
    let inboxFilter = 'all';
    let soundEnabled = readFlag('support_sound_enabled', true);
    let notifyEnabled = readFlag('support_notify_enabled', false);
    const savedInboxState = window.__supportInboxState || null;
    window.__supportInboxState = null;
    let polling = false;
    let atBottom = true;
    let conversationsHtml = conversationList ? conversationList.innerHTML.trim() : '';
    let toastTimer = null;
    // 无刷新切换后浏览器标签也要跟着换，否则标题还停在上一张单。
    document.title = '客服工作台' + (config.ticketNo ? ' ' + config.ticketNo : '');
    const titleBase = document.title;

    function headers(acceptJson = true) {
        const result = {
            'X-CSRF-TOKEN': config.csrf,
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (acceptJson) result.Accept = 'application/json';
        return result;
    }

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.remove('hidden');
        if (toastTimer) window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toast.classList.add('hidden');
        }, 2200);
    }

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { ...headers(), 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
        });
        const payload = await response.json().catch(function () { return {}; });
        if (!response.ok) throw new Error(payload.message || '操作失败');
        return payload;
    }

    /**
     * 话术使用事件：insert 记「点了」，send 记「真发出去了」。
     * 失败不打断接待 —— 统计不是主链路。
     */
    function recordQuickReply(eventName, key) {
        if (!key) return;
        postJson(config.quickReplyEventUrl, {
            key: key,
            event: eventName,
            ticket_id: config.ticketId,
        }).catch(function () {});
    }

    function applyHeld(nextHeld, reason) {
        isHeld = !!nextHeld;
        if (heldBadge) heldBadge.classList.toggle('hidden', !isHeld);
        if (isHeld && heldBadge) heldBadge.title = reason || '等待客户补充';
    }

    function readFlag(key, fallback) {
        try {
            const value = window.localStorage.getItem(key);
            return value === null ? fallback : value === '1';
        } catch (error) {
            return fallback;
        }
    }

    function writeFlag(key, value) {
        try {
            window.localStorage.setItem(key, value ? '1' : '0');
        } catch (error) {
            /* 隐私模式/禁用存储时只影响记忆，不影响本次会话 */
        }
    }

    function ensureAudio() {
        try {
            supportAlertState.audio = supportAlertState.audio
                || new (window.AudioContext || window.webkitAudioContext)();
            if (supportAlertState.audio.state === 'suspended') {
                supportAlertState.audio.resume();
            }
        } catch (error) {
            supportAlertState.audio = null;
        }
    }

    function playBeep() {
        if (!soundEnabled) return;

        ensureAudio();
        const audio = supportAlertState.audio;
        if (!audio) return;

        try {
            const oscillator = audio.createOscillator();
            const gain = audio.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.value = 0.06;
            oscillator.connect(gain);
            gain.connect(audio.destination);
            oscillator.start();
            gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + 0.18);
            oscillator.stop(audio.currentTime + 0.2);
        } catch (error) {
            /* 浏览器不允许播放时静默失败，未读徽标仍然有效 */
        }
    }

    function notifyNewMessage(text) {
        if (!notifyEnabled || !document.hidden) return;
        if (!('Notification' in window) || Notification.permission !== 'granted') return;

        try {
            new Notification('客服工作台 · 新消息', {
                body: String(text || '有新的客户消息').slice(0, 80),
                tag: 'support-ticket-' + config.ticketId,
                silent: true,
            });
        } catch (error) {
            /* 通知失败不影响工作台 */
        }
    }

    /**
     * 新消息提醒：2 秒内的多次触发只响一次，避免当前会话轮询和左栏轮询各响一遍。
     */
    function alertNewMessage(text) {
        const now = Date.now();
        if (now - supportAlertState.lastAt < 2000) return;
        supportAlertState.lastAt = now;
        playBeep();
        notifyNewMessage(text);
    }

    function paintAlertToggles() {
        if (soundToggle) {
            soundToggle.textContent = soundEnabled ? '提示音 开' : '提示音 关';
            soundToggle.classList.toggle('support-toggle-on', soundEnabled);
            soundToggle.title = '新消息到达时播放提示音';
        }

        if (notifyToggle) {
            if (!('Notification' in window)) {
                notifyToggle.textContent = '通知不支持';
                notifyToggle.disabled = true;
            } else if (!notifyEnabled) {
                notifyToggle.textContent = '桌面通知';
                notifyToggle.classList.remove('support-toggle-on');
            } else if (Notification.permission === 'granted') {
                notifyToggle.textContent = '通知 开';
                notifyToggle.classList.add('support-toggle-on');
            } else {
                notifyToggle.textContent = '通知 未授权';
                notifyToggle.classList.remove('support-toggle-on');
            }
            notifyToggle.title = '页面在后台时用系统通知提醒新消息';
        }
    }

    if (soundToggle) {
        soundToggle.addEventListener('click', function () {
            soundEnabled = !soundEnabled;
            writeFlag('support_sound_enabled', soundEnabled);
            paintAlertToggles();
            if (soundEnabled) {
                ensureAudio();
                playBeep();
            }
        });
    }

    if (notifyToggle) {
        notifyToggle.addEventListener('click', function () {
            if (!('Notification' in window)) {
                showToast('当前浏览器不支持桌面通知');
                return;
            }

            if (Notification.permission === 'granted') {
                notifyEnabled = !notifyEnabled;
                writeFlag('support_notify_enabled', notifyEnabled);
                paintAlertToggles();
                return;
            }

            Notification.requestPermission().then(function (permission) {
                notifyEnabled = permission === 'granted';
                writeFlag('support_notify_enabled', notifyEnabled);
                paintAlertToggles();

                if (!notifyEnabled) showToast('桌面通知未授权，可继续使用提示音与未读徽标');
            }).catch(function () {
                showToast('桌面通知授权失败');
            });
        });
    }

    paintAlertToggles();

    // 浏览器要求音频在用户手势后才能播放；第一次点击时把 AudioContext 唤醒。
    const onFirstGesture = function () {
        ensureAudio();
        document.removeEventListener('click', onFirstGesture);
    };
    document.addEventListener('click', onFirstGesture);
    cleanups.push(function () { document.removeEventListener('click', onFirstGesture); });

    function paintPresence(agentCount) {
        if (!presenceToggle) return;

        presenceDot.className = 'support-dot ' + (isOnline ? 'support-dot-online' : 'support-dot-offline');
        presenceCopy.textContent = isOnline ? '在线接待' : '上线接待';
        presenceToggle.className = 'support-presence shrink-0 ' + (
            isOnline ? 'support-presence-online' : 'support-presence-offline'
        );

        if (claimButton) {
            claimButton.disabled = !isOnline;
            // 已接入的会话会把「接入」按钮藏起来，此时心跳刷新不要再把提示改回去。
            if (claimHint && !claimButton.classList.contains('hidden')) {
                claimHint.textContent = isOnline ? '接入后即可直接回复。' : '请先在左上角上线接待。';
            }
        }

        if (typeof agentCount === 'number' && onlineCount) {
            onlineCount.textContent = agentCount + ' 位在线';
        }
    }

    async function setPresence(nextOnline) {
        const response = await fetch(config.presenceUrl, {
            method: 'POST',
            headers: { ...headers(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ is_online: nextOnline, max_concurrent: 5 }),
        });

        if (!response.ok) throw new Error('在线状态更新失败');
        const payload = await response.json();
        isOnline = !!(payload.data && payload.data.is_online);
        paintPresence(payload.data ? payload.data.online_agents : undefined);
    }

    if (presenceToggle) {
        paintPresence();
        presenceToggle.addEventListener('click', async function () {
            const next = !isOnline;
            presenceToggle.disabled = true;
            try {
                await setPresence(next);
                showToast(isOnline ? '已上线接待' : '已下线');
            } catch (error) {
                showToast(error.message || '在线状态更新失败');
            } finally {
                presenceToggle.disabled = false;
            }
        });

        // 心跳必须常驻：客服可能在页面加载后才点“上线接待”，
        // 如果只在 initialOnline 为 true 时起定时器，上线 60 秒后就会掉线。
        if (isOnline) {
            setPresence(true).catch(function () {});
        }

        const presenceTimer = window.setInterval(function () {
            if (isOnline) setPresence(true).catch(function () {});
        }, 20000);

        cleanups.push(function () { window.clearInterval(presenceTimer); });
    }

    function autoGrow() {
        if (!input) return;
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    if (input) {
        input.addEventListener('input', autoGrow);

        // 「客服正在输入」心跳：约 4 秒补一次，服务端 8 秒过期。
        // 内部备注不触发 —— 用户不该看到「正在输入」然后等来一条看不见的备注。
        input.addEventListener('input', function () {
            if (composerMode !== 'reply' || input.disabled) return;
            const now = Date.now();
            if (now - lastTypingPingAt < 4000) return;
            lastTypingPingAt = now;
            postJson(config.typingUrl, {}).catch(function () {});
        });
    }

    document.querySelectorAll('[data-reply]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!input || input.disabled) return;
            input.value = button.dataset.reply || '';
            lastQuickReplyKey = button.dataset.replyKey || null;
            recordQuickReply('insert', lastQuickReplyKey);
            autoGrow();
            input.focus();
        });
    });

    if (quickStatsToggle && quickStatsPanel) {
        quickStatsToggle.addEventListener('click', function () {
            quickStatsPanel.classList.toggle('hidden');
        });
    }

    function canReply(data) {
        return data.status !== 'closed' && (!data.assignee_id || Number(data.assignee_id) === Number(config.currentAdminId));
    }

    function applyReplyPermission(data) {
        const allowed = canReply(data);
        if (input) {
            input.disabled = !allowed;
            input.placeholder = allowed
                ? (composerMode === 'note' ? '写一条内部备注，Enter 保存…' : '输入回复，Enter 发送…')
                : '该会话已关闭或由其他客服接待';
        }
        if (submit) submit.disabled = !allowed;
    }

    function setComposerMode(mode) {
        composerMode = mode === 'note' ? 'note' : 'reply';

        composerModeButtons.forEach(function (button) {
            button.classList.toggle('support-mode-active', button.dataset.composerMode === composerMode);
        });

        if (composerHint) {
            composerHint.textContent = composerMode === 'note'
                ? '备注只在后台可见，不会发给用户'
                : '回复会实时进入用户会话';
        }

        if (input && !input.disabled) {
            input.placeholder = composerMode === 'note' ? '写一条内部备注，Enter 保存…' : '输入回复，Enter 发送…';
        }
    }

    composerModeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setComposerMode(button.dataset.composerMode);
            if (input) input.focus();
        });
    });

    function applyStatusBadge(nextStatus, label) {
        if (!statusBadge) return;
        status = nextStatus || status;
        statusBadge.dataset.status = status;
        statusBadge.className = 'support-tone ' + (config.statusTones[status] || config.statusTones.closed || 'support-tone-closed');
        if (label) statusBadge.textContent = label;
    }

    function attachmentElement(attachment) {
        if (attachment && typeof attachment === 'object' && attachment.type === 'product') {
            const card = document.createElement(attachment.admin_url ? 'a' : 'div');
            card.className = 'support-product-attachment';
            if (attachment.admin_url) {
                card.href = attachment.admin_url;
                card.target = '_blank';
                card.rel = 'noopener noreferrer';
            }

            const thumb = document.createElement('span');
            thumb.className = 'support-product-thumb';
            if (attachment.image) {
                const image = document.createElement('img');
                image.src = attachment.image;
                image.alt = '';
                image.loading = 'lazy';
                thumb.appendChild(image);
            } else {
                thumb.textContent = '商';
            }

            const copy = document.createElement('span');
            copy.className = 'support-product-copy';

            const kicker = document.createElement('span');
            kicker.className = 'support-product-kicker';
            kicker.textContent = '商品咨询';

            const name = document.createElement('strong');
            name.className = 'support-product-name';
            name.textContent = attachment.name || '商品';

            const meta = document.createElement('span');
            meta.className = 'support-product-meta';
            if (attachment.sku) {
                const sku = document.createElement('span');
                sku.textContent = attachment.sku;
                meta.appendChild(sku);
            }
            if (attachment.price) {
                const price = document.createElement('span');
                price.textContent = '¥' + attachment.price;
                meta.appendChild(price);
            }
            if (attachment.admin_url) {
                const link = document.createElement('span');
                link.className = 'support-product-link';
                link.textContent = '查看商品 →';
                meta.appendChild(link);
            }

            copy.appendChild(kicker);
            copy.appendChild(name);
            copy.appendChild(meta);
            card.appendChild(thumb);
            card.appendChild(copy);
            return card;
        }

        const chip = document.createElement('span');
        chip.className = 'max-w-xs truncate rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs text-blue-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
        chip.textContent = String(attachment || '');
        return chip;
    }

    function appendAttachments(column, message, isAdmin) {
        if (!Array.isArray(message.attachments) || !message.attachments.length) return;
        const wrap = document.createElement('div');
        wrap.className = 'mt-2 flex w-full flex-col gap-2 ' + (isAdmin ? 'items-end' : 'items-start');
        message.attachments.forEach(function (attachment) {
            wrap.appendChild(attachmentElement(attachment));
        });
        column.appendChild(wrap);
    }

    function messageElement(message) {
        if (message.actor_type === 'system') {
            const row = document.createElement('div');
            row.className = 'flex justify-center';
            row.dataset.messageId = message.id;
            row.dataset.actorType = 'system';
            const bubble = document.createElement('div');
            bubble.className = 'max-w-xl rounded-full bg-gray-100 px-4 py-2 text-center text-xs leading-relaxed text-gray-500 dark:bg-gray-800 dark:text-gray-400';
            bubble.textContent = message.content || '';
            row.appendChild(bubble);
            return row;
        }

        if (message.actor_type === 'note') {
            const row = document.createElement('div');
            row.className = 'flex justify-center';
            row.dataset.messageId = message.id;
            row.dataset.actorType = 'note';

            const bubble = document.createElement('div');
            bubble.className = 'support-note-bubble';

            const kicker = document.createElement('span');
            kicker.className = 'support-note-kicker';
            kicker.textContent = '内部备注 · 仅客服可见';

            const body = document.createElement('span');
            body.className = 'support-note-body';
            body.textContent = message.content || '';

            const time = document.createElement('span');
            time.className = 'support-note-time';
            time.textContent = message.created_at ? String(message.created_at).slice(5, 16) : '';

            bubble.appendChild(kicker);
            bubble.appendChild(body);
            bubble.appendChild(time);
            row.appendChild(bubble);
            return row;
        }

        const isAdmin = message.actor_type === 'admin';
        const isDeveloper = message.actor_type === 'developer';
        const row = document.createElement('div');
        row.className = 'flex gap-3 ' + (isAdmin ? 'flex-row-reverse' : '');
        row.dataset.messageId = message.id;
        row.dataset.actorType = message.actor_type;

        const avatar = document.createElement('div');
        avatar.className = 'support-avatar mt-1 h-8 w-8 flex-none ' + (
            isAdmin ? 'support-avatar-admin' : (isDeveloper ? 'support-avatar-dev' : 'support-avatar-customer')
        );
        avatar.textContent = isAdmin ? '服' : (isDeveloper ? '技' : '客');

        const column = document.createElement('div');
        column.className = 'flex max-w-[78%] flex-col ' + (isAdmin ? 'items-end text-right' : 'items-start');

        const meta = document.createElement('div');
        meta.className = 'mb-1 flex items-center gap-2 text-[10px] text-gray-400 ' + (isAdmin ? 'flex-row-reverse' : '');
        const actor = document.createElement('span');
        actor.textContent = message.actor_name || (isAdmin
            ? (Number(message.actor_id) === Number(config.currentAdminId) ? (config.currentAdminName || '平台客服') : '平台客服')
            : (isDeveloper ? '技术团队' : '用户'));
        const time = document.createElement('span');
        time.textContent = message.created_at ? String(message.created_at).slice(5, 16) : '';
        meta.appendChild(actor);
        meta.appendChild(time);

        if (isAdmin) {
            const receipt = document.createElement('span');
            receipt.className = 'support-receipt ' + (message.read_at ? 'support-receipt-read' : '');
            receipt.dataset.receiptFor = message.id;
            receipt.textContent = message.read_at ? '已读' : '未读';
            meta.appendChild(receipt);
        }

        const bubble = document.createElement('div');
        bubble.className = 'support-bubble ' + (isAdmin ? 'support-bubble-admin' : 'support-bubble-customer');
        bubble.textContent = message.content || '';

        column.appendChild(meta);
        column.appendChild(bubble);
        appendAttachments(column, message, isAdmin);
        row.appendChild(avatar);
        row.appendChild(column);

        return row;
    }

    /**
     * 已读回执会随时间变化（先发出去是未读，用户看过变成已读），
     * 但消息本身已在 DOM 里，appendMessages 不会重复插入 —— 所以单独刷新回执。
     */
    function updateReadReceipts(messages) {
        if (!list || !Array.isArray(messages)) return;
        messages.forEach(function (message) {
            if (message.actor_type && message.actor_type !== 'admin') return;
            if (!message.id) return;
            const receipt = list.querySelector('[data-receipt-for="' + message.id + '"]');
            if (!receipt) return;
            const read = !!message.read_at;
            receipt.textContent = read ? '已读' : '未读';
            receipt.classList.toggle('support-receipt-read', read);
        });
    }

    function scrollToBottom(force) {
        if (!stream) return;
        if (force || atBottom) {
            stream.scrollTop = stream.scrollHeight;
            atBottom = true;
            if (newMessageTip) newMessageTip.classList.add('hidden');
        }
    }

    function updateAtBottom() {
        if (!stream) return;
        atBottom = stream.scrollHeight - stream.scrollTop - stream.clientHeight < 80;
        if (atBottom && newMessageTip) newMessageTip.classList.add('hidden');
    }

    if (stream) stream.addEventListener('scroll', updateAtBottom);
    if (newMessageTip) {
        newMessageTip.addEventListener('click', function () {
            scrollToBottom(true);
        });
    }

    function appendMessages(messages) {
        if (!list || !Array.isArray(messages) || !messages.length) return 0;
        const existing = new Set(Array.from(list.querySelectorAll('[data-message-id]')).map(function (node) {
            return String(node.dataset.messageId);
        }));
        let added = 0;
        let incomingCustomer = false;

        messages.forEach(function (message) {
            if (existing.has(String(message.id))) return;
            list.appendChild(messageElement(message));
            existing.add(String(message.id));
            lastMessageId = Math.max(lastMessageId, Number(message.id || 0));
            added++;
            if (message.actor_type === 'customer') incomingCustomer = true;
        });

        if (stream) stream.dataset.lastMessageId = String(lastMessageId);
        if (added && (atBottom || !incomingCustomer)) {
            scrollToBottom(true);
        } else if (added && newMessageTip) {
            newMessageTip.classList.remove('hidden');
        }
        return added;
    }

    async function markRead(messages) {
        const hasCustomerMessage = Array.isArray(messages) && messages.some(function (message) {
            return message.actor_type === 'customer';
        });
        if (!hasCustomerMessage) return;

        try {
            await fetch(config.readUrl, { method: 'POST', headers: headers() });
        } catch (error) {
            // 已读只是辅助状态，失败不阻断会话。
        }
    }

    async function pollUpdates() {
        if (polling || status === 'closed') return;
        // 后台标签默认不轮询；但开启桌面通知后必须在后台继续轮询，
        // 否则“后台提醒”永远不会触发。关闭通知时保持原来的省流量行为。
        if (document.hidden && !notifyEnabled) return;
        polling = true;

        try {
            const response = await fetch(config.updatesUrl + '?after_message_id=' + encodeURIComponent(lastMessageId), {
                headers: headers(),
            });
            if (!response.ok) throw new Error('同步失败');
            const payload = await response.json();
            const data = payload.data || {};
            const messages = Array.isArray(data.messages) ? data.messages : [];
            const addedMessages = appendMessages(messages);
            updateReadReceipts(messages);
            if (Array.isArray(data.receipts)) updateReadReceipts(data.receipts);
            if (addedMessages && messages.some(function (message) { return message.actor_type === 'customer'; })) {
                const latestCustomer = messages.slice().reverse().find(function (message) { return message.actor_type === 'customer'; });
                alertNewMessage(latestCustomer ? latestCustomer.content : '有新的客户消息');
            }
            // 后台轮询只负责提醒，不替客服“已读”：否则错过通知回来时未读也没了。
            if (!document.hidden) markRead(messages);
            status = data.status || status;
            assigneeId = data.assignee_id;
            applyStatusBadge(data.status, data.status_label);
            applyReplyPermission(data);
            applyHeld(data.held, data.held_reason);
            if (customerTyping) customerTyping.classList.toggle('hidden', !data.customer_typing);
            if (typeof data.online_agents === 'number') paintPresence(data.online_agents);
            if (chatSyncState) chatSyncState.innerHTML = '<span class="support-dot support-dot-online"></span>实时同步';
            if (status === 'closed') window.clearInterval(pollTimer);
        } catch (error) {
            if (chatSyncState) chatSyncState.innerHTML = '<span class="support-dot support-dot-warn"></span>同步中断';
        } finally {
            polling = false;
        }
    }

    function paintStats(stats) {
        if (!stats) return;

        if (statNew) statNew.textContent = stats.new_today;
        if (statClosed) statClosed.textContent = stats.closed_today;

        if (statWaiting) {
            statWaiting.textContent = stats.waiting_reply;
            statWaiting.classList.toggle('support-warn-text', stats.waiting_reply > 0);
        }

        if (statOverdue) {
            statOverdue.textContent = stats.overdue;
            statOverdue.classList.toggle('support-danger-text', stats.overdue > 0);
        }

        if (statHeld && typeof stats.held !== 'undefined') {
            statHeld.textContent = stats.held;
        }

        if (statFirst) {
            statFirst.textContent = stats.first_response === null
                ? '—'
                : (stats.first_response < 60 ? stats.first_response + ' 秒' : Math.round(stats.first_response / 60) + ' 分钟');
        }

        if (statRating) statRating.textContent = stats.rating_avg === null ? '—' : stats.rating_avg;
    }

    /**
     * 客户收件箱的筛选/搜索。
     *
     * 控件是静态的，客户行每 3 秒被替换一次 —— 所以筛选必须在每次重绘后重新应用，
     * 否则客服正选着“待回复”、对方回了一句，列表就被刷回全量。
     */
    function applyInboxFilters() {
        if (!conversationList) return;

        const query = inboxSearch ? inboxSearch.value.trim().toLowerCase() : '';
        let visible = 0;

        conversationList.querySelectorAll('[data-inbox-row]').forEach(function (row) {
            let matches = true;

            if (inboxFilter === 'waiting') {
                matches = (row.dataset.waiting || '') !== '';
            } else if (inboxFilter === 'unread') {
                matches = Number(row.dataset.unread || 0) > 0;
            } else if (inboxFilter !== 'all') {
                matches = row.dataset[inboxFilter] === '1';
            }

            if (matches && query) {
                matches = (row.dataset.search || '').indexOf(query) !== -1;
            }

            row.classList.toggle('hidden', !matches);

            if (matches) visible++;
        });

        if (inboxFilterEmpty) {
            inboxFilterEmpty.classList.toggle('hidden', visible > 0);
        }
    }

    inboxFilterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            inboxFilter = button.dataset.inboxFilter || 'all';

            inboxFilterButtons.forEach(function (candidate) {
                candidate.classList.toggle('support-filter-active', candidate === button);
            });

            applyInboxFilters();
        });
    });

    if (inboxSearch) {
        inboxSearch.addEventListener('input', applyInboxFilters);
    }

    function inboxUnreadTotal() {
        if (!conversationList) return 0;

        let total = 0;
        conversationList.querySelectorAll('[data-inbox-row]').forEach(function (row) {
            total += Number(row.dataset.unread || 0);
        });

        return total;
    }

    async function pollConversations() {
        if (!conversationList) return;
        if (document.hidden && !notifyEnabled) return;

        const unreadBefore = inboxUnreadTotal();

        try {
            const response = await fetch(config.conversationsUrl + '?ticket_id=' + encodeURIComponent(config.ticketId), {
                headers: headers(),
            });
            if (!response.ok) throw new Error('会话列表同步失败');
            const payload = await response.json();
            const data = payload.data || {};
            const scrollTop = conversationList.scrollTop;

            if (typeof data.html === 'string' && data.html.trim() !== conversationsHtml) {
                conversationList.innerHTML = data.html;
                conversationsHtml = data.html.trim();
                conversationList.scrollTop = scrollTop;
            }

            if (data.counts) {
                if (inboxTotal && typeof data.counts.total !== 'undefined') inboxTotal.textContent = data.counts.total;
                if (queueCount && typeof data.counts.queue !== 'undefined') queueCount.textContent = data.counts.queue;
                if (mineCount && typeof data.counts.mine !== 'undefined') mineCount.textContent = data.counts.mine;
                if (unreadCount && typeof data.counts.unread !== 'undefined') unreadCount.textContent = data.counts.unread;
                if (asyncCount && typeof data.counts.async !== 'undefined') asyncCount.textContent = data.counts.async;
                if (heldCount && typeof data.counts.held !== 'undefined') heldCount.textContent = data.counts.held;
                if (waitingCount && typeof data.counts.waiting !== 'undefined') waitingCount.textContent = data.counts.waiting;
            }
            if (typeof data.online_agents === 'number') paintPresence(data.online_agents);
            paintStats(data.stats);
            applyInboxFilters();

            if (inboxUnreadTotal() > unreadBefore) {
                alertNewMessage('有新的客户消息');
            }
            if (listSyncState) {
                listSyncState.textContent = '实时刷新';
                listSyncState.className = 'ml-auto text-gray-400';
            }

            updateUnreadTitle();
        } catch (error) {
            if (listSyncState) {
                listSyncState.textContent = '刷新中断';
                listSyncState.className = 'ml-auto support-warn-text';
            }
        }
    }

    function updateUnreadTitle() {
        if (!conversationList) return;
        let unread = 0;
        conversationList.querySelectorAll('[data-unread]').forEach(function (item) {
            unread += Number(item.dataset.unread || 0);
        });
        document.title = unread > 0 ? '(' + unread + ') ' + titleBase : titleBase;
    }

    if (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const text = input ? input.value.trim() : '';
            if (!text) return;
            if (submit) submit.disabled = true;

            try {
                if (composerMode === 'note') {
                    const payload = await postJson(config.noteUrl, { content: text });

                    if (input) {
                        input.value = '';
                        autoGrow();
                    }
                    if (payload.data && payload.data.message) {
                        appendMessages([payload.data.message]);
                    }
                    showToast(payload.message || '备注已保存');
                    return;
                }

                const body = new FormData(form);
                // 只有真正发出去了才记 send；点过但没发的只算 insert。
                if (lastQuickReplyKey) {
                    body.append('quick_reply_key', lastQuickReplyKey);
                }

                const response = await fetch(config.replyUrl, {
                    method: 'POST',
                    headers: headers(),
                    body: body,
                });
                const payload = await response.json().catch(function () { return {}; });
                if (!response.ok) throw new Error(payload.message || '发送失败');

                if (input) {
                    input.value = '';
                    autoGrow();
                }
                lastQuickReplyKey = null;
                if (payload.data) {
                    applyStatusBadge(payload.data.status, payload.data.status_label);
                    applyReplyPermission(payload.data);
                    // 客服回复会让服务端解除挂起，前端同步收起「挂起」徽标。
                    applyHeld(false);
                }
                if (payload.data && payload.data.message) {
                    appendMessages([payload.data.message]);
                }
            } catch (error) {
                showToast(error.message || '发送失败');
            } finally {
                if (submit) submit.disabled = false;
                if (input) input.focus();
            }
        });
    }

    if (input && form) {
        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
            event.preventDefault();
            if (form.requestSubmit) form.requestSubmit();
            else form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        });
    }

    if (statusForm) {
        statusForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const button = statusForm.querySelector('button[type="submit"]');
            if (button) button.disabled = true;

            try {
                const response = await fetch(config.statusUrl, {
                    method: 'POST',
                    headers: headers(),
                    body: new FormData(statusForm),
                });
                const payload = await response.json().catch(function () { return {}; });
                if (!response.ok) throw new Error(payload.message || '状态更新失败');

                if (payload.data) {
                    applyStatusBadge(payload.data.status, payload.data.status_label);
                    applyReplyPermission(payload.data);
                }
                if (statusFeedback) {
                    statusFeedback.textContent = '处置已保存，用户端会看到最新状态。';
                    statusFeedback.classList.remove('hidden');
                    window.setTimeout(function () { statusFeedback.classList.add('hidden'); }, 2600);
                }
                showToast(payload.message || '状态已更新');
            } catch (error) {
                showToast(error.message || '状态更新失败');
            } finally {
                if (button) button.disabled = false;
            }
        });
    }

    async function claimOrRelease(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: headers(),
            body: body ? JSON.stringify(body) : undefined,
        });
        const payload = await response.json().catch(function () { return {}; });
        if (!response.ok) throw new Error(payload.message || '操作失败');
        window.location.reload();
    }

    if (claimButton) {
        claimButton.addEventListener('click', async function () {
            if (claimButton.disabled) return;
            claimButton.disabled = true;
            try {
                const response = await fetch(claimButton.dataset.url, { method: 'POST', headers: headers() });
                const payload = await response.json().catch(function () { return {}; });
                if (!response.ok) throw new Error(payload.message || '接入失败');

                // 接入后不整页刷新：整页重绘要重跑三栏查询，在排队高峰时反而拖慢接待。
                const data = payload.data || {};
                assigneeId = data.assignee_id;
                status = data.status || 'processing';
                applyStatusBadge(status, data.status_label);
                applyReplyPermission({ status: status, assignee_id: assigneeId });
                claimButton.classList.add('hidden');
                if (claimHint) claimHint.textContent = '已接入，当前由你接待。';
                if (assigneeNameEl) assigneeNameEl.textContent = config.currentAdminName || '当前客服';
                showToast(payload.message || '已接入在线会话');
                if (input) input.focus();
                pollConversations();
            } catch (error) {
                showToast(error.message || '接入失败');
                claimButton.disabled = false;
            }
        });
    }

    if (releaseButton) {
        releaseButton.addEventListener('click', async function () {
            if (!window.confirm('确认释放会话并放回队列？')) return;
            releaseButton.disabled = true;
            try {
                await claimOrRelease(releaseButton.dataset.url);
            } catch (error) {
                showToast(error.message || '释放失败');
                releaseButton.disabled = false;
            }
        });
    }

    if (transferButton && transferAgent) {
        transferButton.addEventListener('click', async function () {
            const targetId = transferAgent.value;
            if (!targetId) return;

            const option = transferAgent.options[transferAgent.selectedIndex];
            const targetLabel = option ? option.textContent.trim() : ('#' + targetId);

            if (!window.confirm('确认将会话转接给「' + targetLabel + '」？')) return;

            transferButton.disabled = true;
            try {
                const payload = await postJson(config.transferUrl, { assignee_id: Number(targetId) });
                showToast(payload.message || '会话已转接');
                window.location.reload();
            } catch (error) {
                showToast(error.message || '转接失败');
                transferButton.disabled = false;
            }
        });
    }

    if (holdButton) {
        holdButton.addEventListener('click', async function () {
            if (!window.confirm('挂起后会移出活跃队列，客户回复或客服回复都会自动恢复。确认挂起？')) return;

            holdButton.disabled = true;
            try {
                const payload = await postJson(config.holdUrl, {
                    reason: holdReason ? holdReason.value.trim() : '',
                });
                showToast(payload.message || '会话已挂起');
                window.location.reload();
            } catch (error) {
                showToast(error.message || '挂起失败');
                holdButton.disabled = false;
            }
        });
    }

    if (resumeButton) {
        resumeButton.addEventListener('click', async function () {
            resumeButton.disabled = true;
            try {
                const payload = await postJson(config.resumeUrl, {});
                showToast(payload.message || '会话已恢复处理');
                window.location.reload();
            } catch (error) {
                showToast(error.message || '恢复失败');
                resumeButton.disabled = false;
            }
        });
    }

    setComposerMode('reply');
    applyHeld(config.held);
    if (savedInboxState) {
        inboxFilter = savedInboxState.filter || 'all';
        if (inboxSearch) inboxSearch.value = savedInboxState.query || '';
        inboxFilterButtons.forEach(function (button) {
            button.classList.toggle('support-filter-active', button.dataset.inboxFilter === inboxFilter);
        });
    }
    applyInboxFilters();
    if (savedInboxState && conversationList) conversationList.scrollTop = savedInboxState.scroll || 0;
    applyReplyPermission({ status: status, assignee_id: assigneeId });
    applyStatusBadge(status);
    autoGrow();
    scrollToBottom(true);
    updateUnreadTitle();

    async function switchConversation(url, pushHistory) {
        if (supportWorkspaceSwitching) return;
        supportWorkspaceSwitching = true;

        try {
            const embedUrl = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
            const response = await fetch(embedUrl, { headers: headers(false) });
            if (!response.ok) throw new Error('切换会话失败');

            const html = await response.text();
            const trimmed = html.trim();

            // 会话过期时服务端会重定向到登录页；不能把登录页塞进工作台。
            if (!trimmed.startsWith('<div') || !trimmed.includes('id="support-workspace"')) {
                throw new Error('登录状态已失效，请刷新页面');
            }

            const current = document.getElementById('support-workspace');
            if (!current) throw new Error('工作台不存在');

            window.__supportInboxState = {
                filter: inboxFilter,
                query: inboxSearch ? inboxSearch.value : '',
                scroll: conversationList ? conversationList.scrollTop : 0,
            };
            window.__supportPendingToast = '已切换会话';

            current.outerHTML = trimmed;
            if (pushHistory !== false) {
                history.pushState({ supportWorkspace: true }, '', url);
            }

            // 重新读取新节点的 data-support-config，并清掉旧实例的定时器/监听。
            bootSupportWorkspace();
        } catch (error) {
            showToast(error.message || '切换会话失败');
        } finally {
            supportWorkspaceSwitching = false;
        }
    }

    // 左栏每 3 秒重绘，点击必须走事件委托；带修饰键时保留浏览器默认行为（新标签打开）。
    const onInboxClick = function (event) {
        if (event.defaultPrevented) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const row = event.target.closest
            ? event.target.closest('[data-inbox-row], [data-workspace-link]')
            : null;
        if (!row) return;

        const href = row.getAttribute('href');
        if (!href) return;

        event.preventDefault();
        switchConversation(href);
    };
    document.addEventListener('click', onInboxClick);
    cleanups.push(function () { document.removeEventListener('click', onInboxClick); });

    // 浏览器后退/前进时按当前 URL 重新加载工作台主体；回到非工单页则整页加载。
    const onPopState = function () {
        if (/^\/admin\/customers\/tickets\/\d+$/.test(window.location.pathname)) {
            switchConversation(window.location.href, false);
            return;
        }

        window.location.reload();
    };
    window.addEventListener('popstate', onPopState);
    cleanups.push(function () { window.removeEventListener('popstate', onPopState); });

    const pollTimer = window.setInterval(
        pollUpdates,
        config.serviceMode === 'online' ? 2000 : 5000
    );
    const conversationTimer = window.setInterval(pollConversations, 3000);
    const initialListTimer = window.setTimeout(pollConversations, 600);

    // 从后台标签页切回来时立刻补一次，不用等下一个轮询周期。
    const onVisibilityChange = function () {
        if (document.hidden) return;
        pollUpdates();
        pollConversations();
    };
    document.addEventListener('visibilitychange', onVisibilityChange);

    cleanups.push(function () {
        window.clearInterval(pollTimer);
        window.clearInterval(conversationTimer);
        window.clearTimeout(initialListTimer);
        document.removeEventListener('visibilitychange', onVisibilityChange);
    });

    supportWorkspaceCleanup = function () {
        cleanups.forEach(function (cleanup) {
            try { cleanup(); } catch (error) { /* 单个清理失败不阻断其余 */ }
        });
    };

    if (window.__supportPendingToast) {
        showToast(window.__supportPendingToast);
        window.__supportPendingToast = null;
    }
}

/*
 * ⚠ 不能绑 DOMContentLoaded。
 * Bagisto Admin 在 window.load 里 app.mount('#app')，Vue 会把服务端渲染的节点整体替换掉；
 * 在 DOMContentLoaded 缓存的按钮/输入框/消息列表全部会变成脱离文档的旧节点（点了没反应）。
 * 因此等 load + 一帧之后再启动，确保绑定的是 Vue 挂载后的真实节点。
 */
function scheduleSupportWorkspaceBoot() {
    window.setTimeout(bootSupportWorkspace, 150);
}

if (document.readyState === 'complete') {
    scheduleSupportWorkspaceBoot();
} else {
    window.addEventListener('load', scheduleSupportWorkspaceBoot, { once: true });
}
