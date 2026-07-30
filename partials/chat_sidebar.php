<?php
// Compact conversations list shown beside an open thread (chat_group.php,
// chat_dm.php, chat_ai.php) — same data as chat.php's full landing page,
// via chat_conversations_for(), just rendered smaller. Requires $user in
// scope; $__chatActiveType ('group'|'dm'|'ai') and $__chatActiveId set by
// the including page so the open thread can be highlighted.
$__chatData   = chat_conversations_for($user);
$__chatActiveType = $__chatActiveType ?? null;
$__chatActiveId   = $__chatActiveId ?? 0;
?>
<aside class="chat-conversations">
    <div class="chat-conversations-head">
        <h2>Conversations</h2>
        <a class="chat-icon-btn" href="chat.php" title="Chat home"><i class="bi bi-house-door"></i></a>
    </div>
    <div class="chat-conversations-list">
        <a class="chat-conv-row<?php echo $__chatActiveType === 'ai' ? ' active' : ''; ?>" href="chat_ai.php">
            <div class="chat-avatar chat-avatar--ai"><i class="bi bi-robot"></i></div>
            <div class="chat-conv-info">
                <strong>AI Assistant</strong>
                <p class="muted">Ask anything</p>
            </div>
        </a>
        <?php foreach ($__chatData['groups'] as $g):
            $isActive = $__chatActiveType === 'group' && $__chatActiveId === $g['course_id'];
        ?>
            <a class="chat-conv-row<?php echo $isActive ? ' active' : ''; ?><?php echo $g['unread'] > 0 ? ' has-unread' : ''; ?>"
                href="chat_group.php?course_id=<?php echo (int) $g['course_id']; ?>">
                <div class="chat-avatar" style="background:<?php echo user_color((int) $g['course_id']); ?>;color:#fff;"><?php echo h(substr($g['code'], 0, 2)); ?></div>
                <div class="chat-conv-info">
                    <strong><?php echo h($g['code']); ?></strong>
                    <p class="muted"><?php echo h(mb_strimwidth($g['preview'], 0, 32, '…')); ?></p>
                </div>
                <div class="chat-conv-meta">
                    <span class="chat-conv-time"><?php echo h(chat_preview_time($g['preview_at'])); ?></span>
                    <?php if ($g['unread'] > 0): ?>
                        <span class="notif-badge"><?php echo $g['unread']; ?></span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
        <?php foreach ($__chatData['dms'] as $d):
            $isActive = $__chatActiveType === 'dm' && $__chatActiveId === $d['thread_id'];
        ?>
            <a class="chat-conv-row<?php echo $isActive ? ' active' : ''; ?><?php echo $d['unread'] > 0 ? ' has-unread' : ''; ?>"
                href="chat_dm.php?thread_id=<?php echo (int) $d['thread_id']; ?>">
                <div class="chat-avatar" style="background:<?php echo user_color((int) $d['other_id']); ?>;color:#fff;"><?php echo avatar_inner_html($d['name'], $d['picture'] ?? null); ?></div>
                <div class="chat-conv-info">
                    <strong><?php echo h($d['name']); ?></strong>
                    <p class="muted"><?php echo h(mb_strimwidth($d['preview'], 0, 32, '…')); ?></p>
                </div>
                <div class="chat-conv-meta">
                    <span class="chat-conv-time"><?php echo h(chat_preview_time($d['preview_at'])); ?></span>
                    <?php if ($d['unread'] > 0): ?>
                        <span class="notif-badge"><?php echo $d['unread']; ?></span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="btn secondary chat-new-dm-btn" href="chat.php#start-chat"><i class="bi bi-plus-lg"></i> New Direct Message</a>
</aside>
