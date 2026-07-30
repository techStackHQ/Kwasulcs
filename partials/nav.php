<?php
// Shared app navigation: desktop sidebar + mobile bottom nav + floating
// feedback button. Requires $user (current_user()) to already be in scope.
$__navCurrent = basename($_SERVER['PHP_SELF'] ?? '');
$__navItems = [
    ['href' => 'dashboard.php', 'icon' => 'bi-house-door-fill', 'label' => 'Home'],
    ['href' => 'courses.php',   'icon' => 'bi-journal-bookmark-fill', 'label' => 'Courses'],
    ['href' => 'calendar.php',  'icon' => 'bi-calendar3', 'label' => 'Calendar'],
    ['href' => 'chat.php',      'icon' => 'bi-chat-dots-fill', 'label' => 'Chat'],
];
?>
<aside class="sidebar">
    <a class="sidebar-brand" href="dashboard.php">
        <?php echo brand_logo(); ?>
        <span>KWASU LCS</span>
    </a>
    <nav class="sidebar-nav">
        <?php foreach ($__navItems as $item): ?>
            <a class="nav-item<?php echo $__navCurrent === $item['href'] ? ' active' : ''; ?>" href="<?php echo h($item['href']); ?>">
                <span class="nav-icon"><i class="bi <?php echo h($item['icon']); ?>"></i></span>
                <span class="nav-label"><?php echo h($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <nav class="sidebar-nav sidebar-bottom">
        <a class="nav-item<?php echo $__navCurrent === 'settings.php' ? ' active' : ''; ?>" href="settings.php">
            <span class="nav-icon"><i class="bi bi-gear-fill"></i></span>
            <span class="nav-label">Settings</span>
        </a>
        <?php if ($user['role'] !== 'student'): ?>
            <a class="nav-item<?php echo $__navCurrent === 'admin.php' ? ' active' : ''; ?>" href="admin.php">
                <span class="nav-icon"><i class="bi bi-sliders"></i></span>
                <span class="nav-label">Content Management</span>
            </a>
        <?php endif; ?>
    </nav>
</aside>

<!-- ── Mobile navigation: bottom tab bar ─────────────────────────────────── -->
<nav class="bottom-nav">
    <?php foreach ($__navItems as $item): ?>
        <a class="nav-item<?php echo $__navCurrent === $item['href'] ? ' active' : ''; ?>" href="<?php echo h($item['href']); ?>">
            <span class="nav-icon"><i class="bi <?php echo h($item['icon']); ?>"></i></span>
            <span class="nav-label"><?php echo h($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<button class="feedback-fab" onclick="openFeedbackModal()" title="Send feedback"><i class="bi bi-chat-dots-fill"></i></button>
<div id="feedback-modal" class="feedback-modal">
    <div class="feedback-modal-inner">
        <button type="button" class="feedback-modal-close" onclick="closeFeedbackModal()">&times;</button>
        <h3>Send Feedback</h3>
        <p class="muted" style="font-size:13px;">Let us know about bugs, ideas, or anything else.</p>
        <textarea id="feedback-message" rows="4" placeholder="Tell us what's on your mind..."></textarea>
        <button type="button" class="btn primary" onclick="submitFeedback()">Send</button>
        <p id="feedback-status" class="muted" style="font-size:13px;min-height:18px;"></p>
    </div>
</div>
<script>
function openFeedbackModal() {
    document.getElementById('feedback-modal').classList.add('open');
}
function closeFeedbackModal() {
    document.getElementById('feedback-modal').classList.remove('open');
    document.getElementById('feedback-status').textContent = '';
}
function submitFeedback() {
    var msgEl = document.getElementById('feedback-message');
    var status = document.getElementById('feedback-status');
    var msg = msgEl.value.trim();
    if (!msg) { status.textContent = 'Please enter a message.'; return; }
    status.textContent = 'Sending…';
    fetch('feedback_submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: msg })
    })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                status.textContent = 'Thanks for your feedback!';
                msgEl.value = '';
                setTimeout(closeFeedbackModal, 1200);
            } else {
                status.textContent = d.error || 'Something went wrong.';
            }
        })
        .catch(function () { status.textContent = 'Network error. Please try again.'; });
}
</script>
