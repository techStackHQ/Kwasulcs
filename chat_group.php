<?php
require_once __DIR__ . '/config.php';
require_login();
ensure_chat_tables();

$user     = current_user();
$courseId = (int) ($_GET['course_id'] ?? 0);

if (!enrolled_or_staff_access($courseId, $user)) {
    http_response_code(403);
    echo 'Forbidden — you are not a member of this course.';
    exit();
}

$courseStmt = db()->prepare('SELECT c.*, u.full_name AS lecturer_name FROM courses c JOIN users u ON u.id = c.lecturer_id WHERE c.id = ?');
$courseStmt->execute([$courseId]);
$course = $courseStmt->fetch();
if (!$course) {
    http_response_code(404);
    echo 'Course not found.';
    exit();
}

$groupId = get_or_create_chat_group($courseId);

// Mark as read up to the latest message on load.
$latest = db()->prepare('SELECT MAX(id) FROM chat_messages WHERE group_id = ?');
$latest->execute([$groupId]);
$latestId = (int) ($latest->fetchColumn() ?: 0);
db()->prepare('
    INSERT INTO chat_reads (user_id, group_id, last_read_message_id) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))
')->execute([$user['id'], $groupId, $latestId]);

$msgStmt = db()->prepare('
    SELECT m.*, u.full_name AS sender_name, u.role AS sender_role, u.google_picture AS sender_picture
    FROM chat_messages m JOIN users u ON u.id = m.sender_id
    WHERE m.group_id = ? ORDER BY m.id ASC LIMIT 200
');
$msgStmt->execute([$groupId]);
$messages = $msgStmt->fetchAll();

$attachmentsByMsg = [];
$msgIds = array_column($messages, 'id');
if ($msgIds) {
    $in = implode(',', array_fill(0, count($msgIds), '?'));
    $atStmt = db()->prepare("SELECT * FROM chat_attachments WHERE message_id IN ($in)");
    $atStmt->execute($msgIds);
    foreach ($atStmt->fetchAll() as $a) {
        $attachmentsByMsg[$a['message_id']][] = $a;
    }
}

$readUpto = chat_group_read_upto($courseId, $groupId, $user);
$__chatActiveType = 'group';
$__chatActiveId   = $courseId;

// ── Group members (WhatsApp-style participant list): lecturer, admins, then
// enrolled students, each de-duplicated in case of overlap ─────────────────────
$members = [];
$seenMemberIds = [];

$lecStmt = db()->prepare('SELECT id, full_name, google_picture FROM users WHERE id = ?');
$lecStmt->execute([$course['lecturer_id']]);
if ($lec = $lecStmt->fetch()) {
    $members[] = ['id' => (int) $lec['id'], 'name' => $lec['full_name'], 'picture' => $lec['google_picture'], 'tag' => 'Lecturer'];
    $seenMemberIds[(int) $lec['id']] = true;
}

foreach (db()->query("SELECT id, full_name, google_picture FROM users WHERE role = 'admin' ORDER BY full_name")->fetchAll() as $a) {
    if (!isset($seenMemberIds[(int) $a['id']])) {
        $members[] = ['id' => (int) $a['id'], 'name' => $a['full_name'], 'picture' => $a['google_picture'], 'tag' => 'Admin'];
        $seenMemberIds[(int) $a['id']] = true;
    }
}

$stuStmt = db()->prepare('
    SELECT u.id, u.full_name, u.google_picture FROM enrollments e JOIN users u ON u.id = e.student_id
    WHERE e.course_id = ? ORDER BY u.full_name
');
$stuStmt->execute([$courseId]);
foreach ($stuStmt->fetchAll() as $s) {
    if (!isset($seenMemberIds[(int) $s['id']])) {
        $members[] = ['id' => (int) $s['id'], 'name' => $s['full_name'], 'picture' => $s['google_picture'], 'tag' => 'Student'];
        $seenMemberIds[(int) $s['id']] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $pageTitle = $course['code'] . ' Group Chat'; $extraCss = ['assets/chat.css']; include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <div class="chat-thread-header">
        <a class="chat-icon-btn" href="chat.php" aria-label="Back to Chat" title="Back to Chat"><i class="bi bi-arrow-left"></i></a>
        <div class="chat-avatar chat-thread-avatar" style="background:<?php echo user_color($courseId); ?>;color:#fff;"><?php echo h(substr($course['code'], 0, 2)); ?></div>
        <div class="chat-thread-info">
            <strong><?php echo h($course['code']); ?> — <?php echo h($course['title']); ?></strong>
            <p class="muted"><?php echo count($members); ?> members · Course Group</p>
        </div>
        <div class="chat-thread-actions">
            <button type="button" class="chat-icon-btn" id="chatSearchToggle" title="Search in conversation"><i class="bi bi-search"></i></button>
            <button type="button" class="chat-icon-btn" onclick="openMembersModal()" title="Members"><i class="bi bi-people-fill"></i></button>
        </div>
    </div>
    <div class="chat-thread-search" id="chatSearchBar" hidden>
        <i class="bi bi-search"></i>
        <input type="search" id="chatSearchInput" placeholder="Search messages…" aria-label="Search messages in this conversation">
        <button type="button" class="chat-icon-btn" id="chatSearchClose" aria-label="Close search"><i class="bi bi-x-lg"></i></button>
    </div>

    <main class="page chat-page">
        <?php include __DIR__ . '/partials/chat_sidebar.php'; ?>

        <div class="panel chat-thread-panel">
            <div id="chatMessages" class="chat-messages">
                <?php $__lastDate = null; ?>
                <?php foreach ($messages as $m):
                    $isMe = (int) $m['sender_id'] === (int) $user['id'];
                    $isLecturer = (int) $m['sender_id'] === (int) $course['lecturer_id'];
                    $isAdmin = $m['sender_role'] === 'admin';
                    $senderColor = user_color((int) $m['sender_id']);
                    $msgDate = date('Y-m-d', strtotime($m['created_at']));
                    $isRead = $isMe && $readUpto > 0 && (int) $m['id'] <= $readUpto;
                ?>
                    <?php if ($msgDate !== $__lastDate): $__lastDate = $msgDate; ?>
                        <div class="chat-date-sep"><span><?php echo h(chat_date_separator_label($m['created_at'])); ?></span></div>
                    <?php endif; ?>
                    <div class="chat-msg-row <?php echo $isMe ? 'me' : 'them'; ?>" data-msg-id="<?php echo (int) $m['id']; ?>" data-search="<?php echo h(strtolower($m['content'] ?? '')); ?>" data-raw-content="<?php echo h($m['content'] ?? ''); ?>">
                        <?php if (!$isMe): ?>
                            <a class="chat-avatar-msg" href="chat_dm.php?with=<?php echo (int) $m['sender_id']; ?>"
                                style="background:<?php echo $senderColor; ?>" title="Message <?php echo h($m['sender_name']); ?>">
                                <?php echo avatar_inner_html($m['sender_name'], $m['sender_picture'] ?? null); ?>
                            </a>
                        <?php endif; ?>
                        <div class="chat-bubble-col">
                            <?php if (!$isMe): ?>
                                <div class="chat-msg-name-row">
                                    <a href="chat_dm.php?with=<?php echo (int) $m['sender_id']; ?>" style="color:<?php echo $senderColor; ?>">
                                        <?php echo h($m['sender_name']); ?>
                                    </a>
                                    <?php if ($isLecturer): ?><span class="chat-role-pill lecturer">Lecturer</span><?php endif; ?>
                                    <?php if ($isAdmin): ?><span class="chat-role-pill admin">Admin</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($m['deleted_at']): ?>
                                <div class="chat-bubble chat-bubble-deleted <?php echo $isMe ? 'me' : 'them'; ?>">
                                    <i class="bi bi-slash-circle"></i> This message was deleted
                                </div>
                            <?php else: ?>
                                <div class="chat-bubble <?php echo $isMe ? 'me' : 'them'; ?>">
                                    <div class="chat-bubble-text">
                                        <?php if ($m['content']): ?><div><?php echo nl2br(h($m['content'])); ?></div><?php endif; ?>
                                    </div>
                                    <?php foreach ($attachmentsByMsg[$m['id']] ?? [] as $a): echo chat_render_attachment($a); endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="chat-msg-meta">
                                <span class="chat-msg-time">
                                    <?php echo date('g:i A', strtotime($m['created_at'])); ?><?php echo ($m['edited_at'] && !$m['deleted_at']) ? ' · edited' : ''; ?>
                                </span>
                                <?php if ($isMe && !$m['deleted_at']): ?>
                                    <span class="chat-receipt<?php echo $isRead ? ' read' : ''; ?>" title="<?php echo $isRead ? 'Read' : 'Sent'; ?>">
                                        <i class="bi <?php echo $isRead ? 'bi-check2-all' : 'bi-check2'; ?>"></i>
                                    </span>
                                <?php endif; ?>
                                <?php if ($isMe && !$m['deleted_at']): ?>
                                    <div class="chat-msg-actions">
                                        <?php if ($m['content']): ?>
                                            <button type="button" class="chat-msg-action-btn chat-msg-edit-btn" title="Edit message"><i class="bi bi-pencil-fill"></i></button>
                                        <?php endif; ?>
                                        <button type="button" class="chat-msg-action-btn chat-msg-delete-btn" title="Delete message"><i class="bi bi-trash-fill"></i></button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="muted chat-no-results" id="chatNoResults" hidden>No messages match your search.</p>
            </div>
            <form id="chatForm" class="chat-input-row" onsubmit="return false;" enctype="multipart/form-data">
                <label class="chat-attach-btn" title="Attach a file">
                    <i class="bi bi-paperclip"></i>
                    <input type="file" id="chatAttachment" style="display:none;">
                </label>
                <div class="chat-emoji-wrap">
                    <button type="button" class="chat-attach-btn chat-emoji-btn" id="chatEmojiBtn" title="Emoji">
                        <i class="bi bi-emoji-smile"></i>
                    </button>
                    <div class="chat-emoji-picker" id="chatEmojiPicker" hidden></div>
                </div>
                <textarea id="chatInput" rows="1" placeholder="Message the group…"></textarea>
                <button type="button" class="chat-attach-btn chat-voice-btn" id="chatVoiceBtn" title="Record a voice note">
                    <i class="bi bi-mic-fill"></i>
                </button>
                <button type="button" class="btn primary" id="chatSendBtn">Send</button>
            </form>
            <div class="chat-recording-bar" id="chatRecordingBar" hidden>
                <span class="chat-recording-dot"></span>
                <span>Recording… <span id="chatRecordingTime">0:00</span></span>
                <button type="button" class="btn tiny secondary" id="chatRecordingCancel">Cancel</button>
                <button type="button" class="btn tiny primary" id="chatRecordingStop">Send</button>
            </div>
            <div id="attachPreview" class="muted" style="font-size:13px;padding:0 4px;"></div>
        </div>
    </main>

    <!-- ── Members modal ─────────────────────────────────────────────────────── -->
    <div class="chat-members-modal" id="membersModal">
        <div class="chat-members-inner">
            <div class="chat-members-head">
                <button type="button" class="chat-members-close" onclick="closeMembersModal()">&times;</button>
                <h3><?php echo h($course['code']); ?> Members</h3>
                <p><?php echo count($members); ?> people in this group</p>
            </div>
            <div class="chat-members-list">
                <?php foreach ($members as $mem):
                    $isSelf = (int) $mem['id'] === (int) $user['id'];
                    $memColor = user_color((int) $mem['id']);
                ?>
                    <?php if ($isSelf): ?>
                        <div class="chat-member-row self">
                    <?php else: ?>
                        <a class="chat-member-row" href="chat_dm.php?with=<?php echo (int) $mem['id']; ?>">
                    <?php endif; ?>
                        <div class="chat-member-avatar" style="background:<?php echo $memColor; ?>">
                            <?php echo avatar_inner_html($mem['name'], $mem['picture'] ?? null); ?>
                        </div>
                        <div class="chat-member-info">
                            <strong style="color:<?php echo $memColor; ?>"><?php echo h($mem['name']); ?><?php echo $isSelf ? ' (You)' : ''; ?></strong>
                            <span><?php echo h($mem['tag']); ?></span>
                        </div>
                    <?php if ($isSelf): ?>
                        </div>
                    <?php else: ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        function openMembersModal() { document.getElementById('membersModal').classList.add('open'); }
        function closeMembersModal() { document.getElementById('membersModal').classList.remove('open'); }

        (function () {
            let lastId = <?php echo (int) $latestId; ?>;
            let lastDateKey = <?php echo json_encode($__lastDate); ?>;
            const CURRENT_USER_ID = <?php echo (int) $user['id']; ?>;
            const messagesEl = document.getElementById('chatMessages');
            const input = document.getElementById('chatInput');
            const sendBtn = document.getElementById('chatSendBtn');
            const fileInput = document.getElementById('chatAttachment');
            const attachPreview = document.getElementById('attachPreview');

            function escHtml(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            // JS-side mirror of avatar_inner_html() in config.php — same rule,
            // client-rendered messages just don't have a PHP round-trip.
            function avatarInnerHtml(picture, initialsText) {
                if (picture) return '<img src="' + escHtml(picture) + '" alt="" class="avatar-img">';
                return escHtml(initialsText);
            }

            // MySQL "YYYY-MM-DD HH:MM:SS" isn't reliably parsed by `new Date()`
            // across browsers (Safari in particular) — parse it by hand.
            function parseSqlDate(sql) {
                const m = String(sql).match(/(\d+)-(\d+)-(\d+)[ T](\d+):(\d+):(\d+)/);
                if (!m) return new Date();
                return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
            }

            function dateKey(sql) {
                const m = String(sql).match(/(\d+)-(\d+)-(\d+)/);
                return m ? m[0] : '';
            }

            function formatTime(sql) {
                return parseSqlDate(sql).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            }

            function dateSepLabel(sql) {
                const d = parseSqlDate(sql);
                const today = new Date(); today.setHours(0, 0, 0, 0);
                const day = new Date(d); day.setHours(0, 0, 0, 0);
                const days = Math.round((today - day) / 86400000);
                if (days === 0) return 'Today';
                if (days === 1) return 'Yesterday';
                if (days < 7) return d.toLocaleDateString([], { weekday: 'long' });
                return d.toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' });
            }

            function maybeInsertDateSeparator(sql) {
                const key = dateKey(sql);
                if (key === lastDateKey) return;
                lastDateKey = key;
                const sep = document.createElement('div');
                sep.className = 'chat-date-sep';
                sep.innerHTML = '<span>' + dateSepLabel(sql) + '</span>';
                messagesEl.appendChild(sep);
            }

            const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            const AUDIO_EXT = ['webm', 'ogg'];
            function formatSize(bytes) {
                if (!bytes) return '';
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }
            function renderAttachment(a) {
                const ext = (a.file_type || '').toLowerCase();
                const url = 'chat_download.php?id=' + a.id;
                if (IMAGE_EXT.includes(ext)) {
                    return '<a class="chat-attachment-image" href="' + url + '" target="_blank"><img src="' + url + '" alt="' + escHtml(a.original_name) + '" loading="lazy"></a>';
                }
                if (ext === 'pdf') {
                    const size = formatSize(a.size);
                    return '<a class="chat-attachment-file chat-attachment-pdf" href="' + url + '" target="_blank">'
                        + '<span class="chat-attachment-file-icon"><i class="bi bi-file-earmark-pdf-fill"></i></span>'
                        + '<span class="chat-attachment-file-info"><strong>' + escHtml(a.original_name) + '</strong>'
                        + '<span class="muted">' + escHtml(size ? size + ' • PDF' : 'PDF') + '</span></span></a>';
                }
                if (AUDIO_EXT.includes(ext)) {
                    return '<div class="chat-attachment-audio-wrap"><i class="bi bi-mic-fill"></i><audio controls src="' + url + '"></audio></div>';
                }
                return '<a class="chat-attachment" href="' + url + '" target="_blank"><i class="bi bi-paperclip"></i> ' + escHtml(a.original_name) + '</a>';
            }

            fileInput.addEventListener('change', function () {
                attachPreview.innerHTML = fileInput.files.length
                    ? '<i class="bi bi-paperclip icon"></i> ' + escHtml(fileInput.files[0].name)
                    : '';
            });

            function renderMessage(m) {
                if (messagesEl.querySelector('[data-msg-id="' + m.id + '"]')) return;
                if (m.created_at) maybeInsertDateSeparator(m.created_at);

                const isMe = m.sender_id == CURRENT_USER_ID;
                const row = document.createElement('div');
                row.className = 'chat-msg-row ' + (isMe ? 'me' : 'them');
                row.dataset.msgId = m.id;
                row.dataset.search = (m.content || '').toLowerCase();
                row.dataset.rawContent = m.content || '';

                let html = '';
                if (!isMe) {
                    html += '<a class="chat-avatar-msg" href="chat_dm.php?with=' + m.sender_id + '" style="background:' + m.sender_color + '" title="Message ' + escHtml(m.sender_name) + '">' + avatarInnerHtml(m.sender_picture, m.sender_initials) + '</a>';
                }
                html += '<div class="chat-bubble-col">';
                if (!isMe) {
                    html += '<div class="chat-msg-name-row"><a href="chat_dm.php?with=' + m.sender_id + '" style="color:' + m.sender_color + '">' + escHtml(m.sender_name) + '</a>';
                    if (m.is_lecturer) html += ' <span class="chat-role-pill lecturer">Lecturer</span>';
                    if (m.sender_role === 'admin') html += ' <span class="chat-role-pill admin">Admin</span>';
                    html += '</div>';
                }
                if (m.deleted_at) {
                    html += '<div class="chat-bubble chat-bubble-deleted ' + (isMe ? 'me' : 'them') + '"><i class="bi bi-slash-circle"></i> This message was deleted</div>';
                } else {
                    let bubbleInner = '<div class="chat-bubble-text">';
                    if (m.content) bubbleInner += '<div>' + escHtml(m.content).replace(/\n/g, '<br>') + '</div>';
                    bubbleInner += '</div>';
                    (m.attachments || []).forEach(a => { bubbleInner += renderAttachment(a); });
                    html += '<div class="chat-bubble ' + (isMe ? 'me' : 'them') + '">' + bubbleInner + '</div>';
                }
                html += '<div class="chat-msg-meta">';
                if (m.created_at) html += '<span class="chat-msg-time">' + formatTime(m.created_at) + (m.edited_at && !m.deleted_at ? ' · edited' : '') + '</span>';
                if (isMe && !m.deleted_at) {
                    html += '<span class="chat-receipt" title="Sent"><i class="bi bi-check2"></i></span>';
                    html += '<div class="chat-msg-actions">';
                    if (m.content) html += '<button type="button" class="chat-msg-action-btn chat-msg-edit-btn" title="Edit message"><i class="bi bi-pencil-fill"></i></button>';
                    html += '<button type="button" class="chat-msg-action-btn chat-msg-delete-btn" title="Delete message"><i class="bi bi-trash-fill"></i></button>';
                    html += '</div>';
                }
                html += '</div></div>';

                row.innerHTML = html;
                messagesEl.appendChild(row);
            }

            function updateReceipts(readUpto) {
                if (!readUpto) return;
                messagesEl.querySelectorAll('.chat-msg-row.me').forEach(function (row) {
                    if (+row.dataset.msgId > readUpto) return;
                    const receipt = row.querySelector('.chat-receipt');
                    if (!receipt || receipt.classList.contains('read')) return;
                    receipt.classList.add('read');
                    receipt.title = 'Read';
                    receipt.innerHTML = '<i class="bi bi-check2-all"></i>';
                });
            }

            // Patches an already-rendered row after this or another
            // participant edits/deletes it (see the `updates` array chat_poll.php
            // returns, since regular polling only reports brand-new ids).
            function applyMessageUpdate(u) {
                const row = messagesEl.querySelector('[data-msg-id="' + u.id + '"]');
                if (!row) return;
                const bubbleCol = row.querySelector('.chat-bubble-col');
                const isMe = row.classList.contains('me');
                if (u.deleted_at) {
                    const bubble = row.querySelector('.chat-bubble');
                    if (bubble) bubble.outerHTML = '<div class="chat-bubble chat-bubble-deleted ' + (isMe ? 'me' : 'them') + '"><i class="bi bi-slash-circle"></i> This message was deleted</div>';
                    const actions = row.querySelector('.chat-msg-actions');
                    if (actions) actions.remove();
                    const receipt = row.querySelector('.chat-receipt');
                    if (receipt) receipt.remove();
                    row.dataset.search = '';
                    return;
                }
                const textWrap = row.querySelector('.chat-bubble-text');
                if (textWrap) textWrap.innerHTML = u.content ? '<div>' + escHtml(u.content).replace(/\n/g, '<br>') + '</div>' : '';
                row.dataset.search = (u.content || '').toLowerCase();
                const timeEl = row.querySelector('.chat-msg-time');
                if (timeEl && u.edited_at && !timeEl.textContent.includes('edited')) {
                    timeEl.textContent = timeEl.textContent + ' · edited';
                }
                const editBtn = row.querySelector('.chat-msg-edit-btn');
                if (editBtn && !u.content) editBtn.remove();
            }

            async function send(fileOverride) {
                const text = input.value.trim();
                const file = fileOverride || fileInput.files[0];
                if (!text && !file) return;
                sendBtn.disabled = true;
                const fd = new FormData();
                fd.append('type', 'group');
                fd.append('target_id', <?php echo (int) $courseId; ?>);
                fd.append('message', text);
                if (file) fd.append('attachment', file, file.name || 'voice-note.webm');

                try {
                    const res = await fetch('chat_send.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.ok) {
                        renderMessage(data.message);
                        lastId = data.message.id;
                        messagesEl.scrollTop = messagesEl.scrollHeight;
                        input.value = '';
                        fileInput.value = '';
                        attachPreview.textContent = '';
                    } else {
                        alert(data.error || 'Failed to send.');
                    }
                } catch (e) {
                    alert('Network error. Please try again.');
                } finally {
                    sendBtn.disabled = false;
                    input.focus();
                }
            }

            sendBtn.addEventListener('click', () => send());
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
            });

            let polling = false;
            let lastPollTime = '<?php echo date('Y-m-d H:i:s'); ?>';
            async function poll() {
                if (polling) return;
                polling = true;
                try {
                    const res = await fetch('chat_poll.php?type=group&id=' + <?php echo (int) $courseId; ?> + '&since_id=' + lastId + '&since_time=' + encodeURIComponent(lastPollTime));
                    const data = await res.json();
                    (data.messages || []).forEach(m => {
                        renderMessage(m);
                        lastId = m.id;
                    });
                    if ((data.messages || []).length) messagesEl.scrollTop = messagesEl.scrollHeight;
                    (data.updates || []).forEach(applyMessageUpdate);
                    updateReceipts(data.read_upto);
                    if (data.server_time) lastPollTime = data.server_time;
                } catch (e) { /* silent — retry next tick */ } finally {
                    polling = false;
                }
            }
            setInterval(poll, 4000);
            updateReceipts(<?php echo (int) $readUpto; ?>);

            // ── Edit / delete own messages ───────────────────────────────────────
            function startEdit(row) {
                if (row.querySelector('.chat-edit-textarea')) return;
                const textWrap = row.querySelector('.chat-bubble-text');
                if (!textWrap) return;
                const original = row.dataset.rawContent || '';
                const savedHtml = textWrap.innerHTML;
                textWrap.innerHTML = '<textarea class="chat-edit-textarea"></textarea>'
                    + '<div class="chat-edit-actions">'
                    + '<button type="button" class="btn tiny secondary chat-edit-cancel">Cancel</button>'
                    + '<button type="button" class="btn tiny primary chat-edit-save">Save</button>'
                    + '</div>';
                const ta = textWrap.querySelector('textarea');
                ta.value = original;
                ta.focus();
                ta.selectionStart = ta.selectionEnd = ta.value.length;

                textWrap.querySelector('.chat-edit-cancel').addEventListener('click', function () {
                    textWrap.innerHTML = savedHtml;
                });
                textWrap.querySelector('.chat-edit-save').addEventListener('click', async function () {
                    const newText = ta.value.trim();
                    if (!newText) { alert('Message cannot be empty.'); return; }
                    try {
                        const fd = new FormData();
                        fd.append('type', 'group');
                        fd.append('message_id', row.dataset.msgId);
                        fd.append('message', newText);
                        const res = await fetch('chat_edit.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.ok) {
                            row.dataset.rawContent = data.content;
                            row.dataset.search = data.content.toLowerCase();
                            textWrap.innerHTML = '<div>' + escHtml(data.content).replace(/\n/g, '<br>') + '</div>';
                            const timeEl = row.querySelector('.chat-msg-time');
                            if (timeEl && !timeEl.textContent.includes('edited')) timeEl.textContent += ' · edited';
                        } else {
                            alert(data.error || 'Failed to save.');
                        }
                    } catch (e) {
                        alert('Network error. Please try again.');
                    }
                });
            }

            async function deleteMessage(row) {
                if (!confirm('Delete this message? This cannot be undone.')) return;
                try {
                    const fd = new FormData();
                    fd.append('type', 'group');
                    fd.append('message_id', row.dataset.msgId);
                    const res = await fetch('chat_delete.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.ok) {
                        applyMessageUpdate({ id: row.dataset.msgId, deleted_at: true });
                    } else {
                        alert(data.error || 'Failed to delete.');
                    }
                } catch (e) {
                    alert('Network error. Please try again.');
                }
            }

            messagesEl.addEventListener('click', function (e) {
                const editBtn = e.target.closest('.chat-msg-edit-btn');
                const deleteBtn = e.target.closest('.chat-msg-delete-btn');
                if (editBtn) startEdit(editBtn.closest('.chat-msg-row'));
                else if (deleteBtn) deleteMessage(deleteBtn.closest('.chat-msg-row'));
            });

            // ── Search in conversation ───────────────────────────────────────────
            const searchToggle = document.getElementById('chatSearchToggle');
            const searchBar = document.getElementById('chatSearchBar');
            const searchInput = document.getElementById('chatSearchInput');
            const searchClose = document.getElementById('chatSearchClose');
            const noResults = document.getElementById('chatNoResults');
            function closeSearch() {
                searchBar.hidden = true;
                searchInput.value = '';
                messagesEl.querySelectorAll('.chat-msg-row').forEach(r => r.hidden = false);
                if (noResults) noResults.hidden = true;
            }
            if (searchToggle) {
                searchToggle.addEventListener('click', function () {
                    searchBar.hidden = !searchBar.hidden;
                    if (!searchBar.hidden) searchInput.focus();
                    else closeSearch();
                });
            }
            if (searchClose) searchClose.addEventListener('click', closeSearch);
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    const q = searchInput.value.trim().toLowerCase();
                    let any = false;
                    messagesEl.querySelectorAll('.chat-msg-row').forEach(function (row) {
                        const match = !q || (row.dataset.search || '').includes(q);
                        row.hidden = !match;
                        if (match) any = true;
                    });
                    if (noResults) noResults.hidden = any;
                });
            }

            // ── Emoji picker: categorized, ~240 emoji (as close to a full
            // keyboard emoji picker as a hand-maintained list gets) ─────────────
            const EMOJI_CATEGORIES = {
                'Smileys': ['😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃', '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗', '😚', '😙', '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭', '🤫', '🤔', '🤐', '🤨', '😐', '😑', '😶', '😏', '😒', '🙄', '😬', '😌', '😔', '😪', '🤤', '😴', '😷', '🤒', '🤕', '🥵', '🥶', '😵', '🤯', '🥳', '😎', '🤓', '🧐', '😕', '🙁', '☹️', '😮', '😲', '😢', '😭', '😱', '😨'],
                'Gestures': ['👍', '👎', '👏', '🙌', '🙏', '👋', '🤝', '💪', '✌️', '🤞', '🤟', '🤙', '👌', '🤌', '🖐️', '✋', '👊', '✊', '👀', '👁️', '💋', '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔', '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝'],
                'Animals': ['🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯', '🦁', '🐮', '🐷', '🐸', '🐵', '🙈', '🙉', '🙊', '🐔', '🐧', '🐦', '🐤', '🦆', '🦅', '🦉', '🦇', '🐺', '🐗', '🐴', '🦄', '🐝', '🦋'],
                'Food': ['🍏', '🍎', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🫐', '🍈', '🍒', '🍑', '🥭', '🍍', '🥥', '🥝', '🍅', '🍆', '🥑', '🥦', '🥬', '🥒', '🌶️', '🌽', '🥕', '🍕', '🍔', '🍟', '🌭', '🍿', '🧁'],
                'Activities': ['⚽', '🏀', '🏈', '⚾', '🎾', '🏐', '🏉', '🎱', '🏓', '🏸', '🥅', '🏒', '🏑', '🥍', '🏏', '⛳', '🏹', '🎣', '🥊', '🥋', '🎽', '🛹', '🛼', '🎮', '🎲', '🎯'],
                'Travel': ['✈️', '🚀', '🚗', '🚕', '🚙', '🚌', '🏍️', '🚲', '🛴', '🚂', '🚆', '🛳️', '⛵', '⚓', '🗺️', '🏔️', '🌋', '🏖️', '🏝️', '🏕️', '🏠', '🏢', '🕌', '🗽'],
                'Objects': ['💡', '🔦', '🕯️', '📚', '📖', '✏️', '📌', '📎', '✂️', '🔑', '🔒', '🔓', '💻', '🖥️', '⌨️', '🖨️', '📱', '☎️', '📷', '🎥', '🎧', '🎤', '🎬', '📁'],
                'Symbols': ['✅', '❌', '❗', '❓', '⚠️', '🔥', '✨', '🎉', '🎊', '💯', '🔔', '🔕', '♻️', '⭐', '🌟', '💫', '🚫', '⛔', '🆕', '🆗', '🔴', '🟢', '🔵', '🟡'],
            };
            const CATEGORY_ICON = { Smileys: '😀', Gestures: '👍', Animals: '🐶', Food: '🍎', Activities: '⚽', Travel: '✈️', Objects: '💡', Symbols: '✅' };
            const emojiBtn = document.getElementById('chatEmojiBtn');
            const emojiPicker = document.getElementById('chatEmojiPicker');
            if (emojiBtn && emojiPicker) {
                const cats = Object.keys(EMOJI_CATEGORIES);
                emojiPicker.innerHTML =
                    '<div class="chat-emoji-tabs">' + cats.map((c, i) => '<button type="button" class="chat-emoji-tab' + (i === 0 ? ' active' : '') + '" data-cat="' + c + '" title="' + c + '">' + CATEGORY_ICON[c] + '</button>').join('') + '</div>'
                    + '<div class="chat-emoji-grid" id="chatEmojiGrid"></div>';
                const grid = emojiPicker.querySelector('#chatEmojiGrid');
                function renderCategory(cat) {
                    grid.innerHTML = EMOJI_CATEGORIES[cat].map(e => '<button type="button" class="chat-emoji-option">' + e + '</button>').join('');
                    grid.scrollTop = 0;
                }
                renderCategory(cats[0]);
                emojiPicker.querySelectorAll('.chat-emoji-tab').forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        emojiPicker.querySelectorAll('.chat-emoji-tab').forEach(t => t.classList.remove('active'));
                        tab.classList.add('active');
                        renderCategory(tab.dataset.cat);
                    });
                });

                emojiBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    emojiPicker.hidden = !emojiPicker.hidden;
                });
                emojiPicker.addEventListener('click', function (e) {
                    const btn = e.target.closest('.chat-emoji-option');
                    if (!btn) return;
                    const start = input.selectionStart || input.value.length;
                    const end = input.selectionEnd || input.value.length;
                    input.value = input.value.slice(0, start) + btn.textContent + input.value.slice(end);
                    input.focus();
                    input.selectionStart = input.selectionEnd = start + btn.textContent.length;
                });
                document.addEventListener('click', function (e) {
                    if (!emojiPicker.hidden && !emojiPicker.contains(e.target) && e.target !== emojiBtn) {
                        emojiPicker.hidden = true;
                    }
                });
            }

            // ── Voice notes ──────────────────────────────────────────────────────
            const voiceBtn = document.getElementById('chatVoiceBtn');
            const recordingBar = document.getElementById('chatRecordingBar');
            const recordingTime = document.getElementById('chatRecordingTime');
            const recordingStop = document.getElementById('chatRecordingStop');
            const recordingCancel = document.getElementById('chatRecordingCancel');
            const chatForm = document.getElementById('chatForm');
            let mediaRecorder = null, recordedChunks = [], recordTimer = null, recordSeconds = 0, cancelled = false;

            function stopStream() {
                if (mediaRecorder && mediaRecorder.stream) {
                    mediaRecorder.stream.getTracks().forEach(t => t.stop());
                }
                clearInterval(recordTimer);
                recordingBar.hidden = true;
                chatForm.hidden = false;
            }

            if (voiceBtn && navigator.mediaDevices && window.MediaRecorder) {
                voiceBtn.addEventListener('click', async function () {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        recordedChunks = [];
                        cancelled = false;
                        mediaRecorder = new MediaRecorder(stream);
                        mediaRecorder.ondataavailable = e => { if (e.data.size) recordedChunks.push(e.data); };
                        mediaRecorder.onstop = function () {
                            stopStream();
                            if (cancelled || !recordedChunks.length) return;
                            const blob = new Blob(recordedChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                            const ext = (mediaRecorder.mimeType || '').includes('ogg') ? 'ogg' : 'webm';
                            const file = new File([blob], 'voice-note.' + ext, { type: blob.type });
                            send(file);
                        };
                        mediaRecorder.start();
                        chatForm.hidden = true;
                        recordingBar.hidden = false;
                        recordSeconds = 0;
                        recordingTime.textContent = '0:00';
                        recordTimer = setInterval(function () {
                            recordSeconds++;
                            const m = Math.floor(recordSeconds / 60), s = recordSeconds % 60;
                            recordingTime.textContent = m + ':' + String(s).padStart(2, '0');
                        }, 1000);
                    } catch (e) {
                        alert('Microphone access is required to record a voice note.');
                    }
                });
            } else if (voiceBtn) {
                voiceBtn.disabled = true;
                voiceBtn.title = 'Voice notes are not supported in this browser';
            }
            if (recordingStop) {
                recordingStop.addEventListener('click', function () {
                    if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
                });
            }
            if (recordingCancel) {
                recordingCancel.addEventListener('click', function () {
                    cancelled = true;
                    if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
                });
            }

            messagesEl.scrollTop = messagesEl.scrollHeight;
        })();
    </script>
</body>

</html>
