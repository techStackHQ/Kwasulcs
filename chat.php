<?php
require_once __DIR__ . '/config.php';
require_login();
// ensure_chat_tables() removed here — schema already established in
// production; this endpoint was re-running ~11 CREATE/ALTER TABLE
// statements on every request. See config.php's ensure_chat_tables().

$user = current_user();
$role = $user['role'];

$chatData  = chat_conversations_for($user);
$groupRows = $chatData['groups'];
$dmRows    = $chatData['dms'];

// dm_threads is only needed here to know which "other" ids already have a
// thread, so the "start a new chat" list below doesn't re-offer them.
$dmThreads = array_map(fn($d) => ['other_id' => $d['other_id']], $dmRows);

// ── People available to start a new DM with (no existing thread yet):
// classmates + course lecturer(s) for students, enrolled students + admins
// for lecturers. Mirrors the broadened DM eligibility in share_a_course(). ──
$classmates = [];
$existingOtherIds = array_map('intval', array_column($dmThreads, 'other_id'));

if ($role === 'student') {
    $cmStmt = db()->prepare('
        SELECT DISTINCT u.id, u.full_name, u.google_picture, "Classmate" AS tag
        FROM enrollments e1
        JOIN enrollments e2 ON e2.course_id = e1.course_id AND e2.student_id != e1.student_id
        JOIN users u ON u.id = e2.student_id
        WHERE e1.student_id = ?
        UNION
        SELECT DISTINCT u.id, u.full_name, u.google_picture, "Lecturer" AS tag
        FROM enrollments e
        JOIN courses c ON c.id = e.course_id
        JOIN users u ON u.id = c.lecturer_id
        WHERE e.student_id = ?
        ORDER BY tag, full_name
    ');
    $cmStmt->execute([$user['id'], $user['id']]);
    foreach ($cmStmt->fetchAll() as $cm) {
        if (!in_array((int) $cm['id'], $existingOtherIds, true)) {
            $classmates[] = $cm;
        }
    }
} elseif ($role === 'lecturer') {
    $stStmt = db()->prepare('
        SELECT DISTINCT u.id, u.full_name, u.google_picture, "Student" AS tag
        FROM enrollments e
        JOIN courses c ON c.id = e.course_id
        JOIN users u ON u.id = e.student_id
        WHERE c.lecturer_id = ?
        ORDER BY full_name
    ');
    $stStmt->execute([$user['id']]);
    foreach ($stStmt->fetchAll() as $cm) {
        if (!in_array((int) $cm['id'], $existingOtherIds, true)) {
            $classmates[] = $cm;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = 'Chat';
    $extraCss = ['assets/chat.css'];
    include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <header class="topbar chat-hero">
        <div>
            <div class="eyebrow"><?php echo brand_logo(); ?> KWASU LCS</div>
            <h1><i class="bi bi-chat-dots-fill icon"></i> Chat</h1>
            <p class="muted">Course groups, direct messages, and the AI assistant.</p>
        </div>
        <div class="topbar-actions">
            <a class="btn glass btn-go-dashboard" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
        </div>
    </header>

    <main class="page">
        <a class="panel chat-ai-card" href="chat_ai.php">
            <div class="chat-ai-card-icon"><i class="bi bi-robot"></i></div>
            <div class="chat-ai-card-info">
                <div class="chat-ai-card-title">AI Assistant <span class="chat-badge-new">New</span></div>
                <p class="muted">Ask questions across all your courses, assignments and lecture materials.</p>
            </div>
            <span class="btn primary chat-ai-card-btn">Start AI Chat</span>
        </a>

        <div class="panel chat-groups-panel" style="margin-top:16px;">
            <div class="panel-head">
                <div>
                    <h2>Course Groups</h2>
                    <p class="muted panel-subtitle">Your enrolled courses and their group chats.</p>
                </div>
            </div>
            <div class="chat-search-input">
                <i class="bi bi-search"></i>
                <input type="search" id="groupSearchInput" placeholder="Search groups…" aria-label="Search course groups">
            </div>
            <div id="groupList">
                <?php if (!$groupRows): ?>
                    <p class="muted" style="padding:12px 0;">No course groups yet.</p>
                <?php endif; ?>
                <?php foreach ($groupRows as $g): ?>
                    <a class="chat-list-row" data-search="<?php echo h(strtolower($g['code'] . ' ' . $g['title'])); ?>" href="chat_group.php?course_id=<?php echo (int) $g['course_id']; ?>">
                        <div class="chat-avatar" style="background:<?php echo user_color((int) $g['course_id']); ?>;color:#fff;"><?php echo h(substr($g['code'], 0, 2)); ?></div>
                        <div class="chat-list-info">
                            <strong><?php echo h($g['code']); ?> — <?php echo h($g['title']); ?></strong>
                            <p class="muted"><?php echo h(mb_strimwidth($g['preview'], 0, 70, '…')); ?></p>
                        </div>
                        <div class="chat-list-meta">
                            <span class="chat-list-time"><?php echo h(chat_preview_time($g['preview_at'])); ?></span>
                            <?php if ($g['unread'] > 0): ?>
                                <span class="notif-badge"><?php echo $g['unread']; ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
                <p class="muted chat-no-results" id="groupNoResults" hidden>No groups match your search.</p>
            </div>
        </div>

        <div class="panel" style="margin-top:16px;" id="start-chat">
            <div class="panel-head">
                <h2>Direct Messages</h2>
            </div>
            <?php if (!$dmRows): ?>
                <p class="muted" style="padding:12px 0;">No direct messages yet.</p>
            <?php endif; ?>
            <?php foreach ($dmRows as $d): ?>
                <a class="chat-list-row" href="chat_dm.php?thread_id=<?php echo (int) $d['thread_id']; ?>">
                    <div class="chat-avatar" style="background:<?php echo user_color($d['other_id']); ?>;color:#fff;"><?php echo avatar_inner_html($d['name'], $d['picture'] ?? null); ?></div>
                    <div class="chat-list-info">
                        <strong><?php echo h($d['name']); ?></strong>
                        <p class="muted"><?php echo h(mb_strimwidth($d['preview'], 0, 70, '…')); ?></p>
                    </div>
                    <div class="chat-list-meta">
                        <span class="chat-list-time"><?php echo h(chat_preview_time($d['preview_at'])); ?></span>
                        <?php if ($d['unread'] > 0): ?>
                            <span class="notif-badge"><?php echo $d['unread']; ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>

            <?php if ($classmates): ?>
                <div class="panel-head" style="margin-top:16px;">
                    <h2 style="font-size:16px;">Start a new chat</h2>
                </div>
                <?php foreach ($classmates as $cm): ?>
                    <a class="chat-list-row" href="chat_dm.php?with=<?php echo (int) $cm['id']; ?>">
                        <div class="chat-avatar" style="background:<?php echo user_color((int) $cm['id']); ?>;color:#fff;"><?php echo avatar_inner_html($cm['full_name'], $cm['google_picture'] ?? null); ?></div>
                        <div class="chat-list-info">
                            <strong><?php echo h($cm['full_name']); ?></strong>
                            <p class="muted"><?php echo h($cm['tag']); ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        (function() {
            var input = document.getElementById('groupSearchInput');
            var rows = document.querySelectorAll('#groupList .chat-list-row');
            var noResults = document.getElementById('groupNoResults');
            if (!input) return;
            input.addEventListener('input', function() {
                var q = input.value.trim().toLowerCase();
                var anyVisible = false;
                rows.forEach(function(row) {
                    var match = !q || (row.dataset.search || '').includes(q);
                    row.hidden = !match;
                    if (match) anyVisible = true;
                });
                if (noResults) noResults.hidden = anyVisible || rows.length === 0;
            });
        })();
    </script>
</body>

</html>