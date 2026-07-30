<?php
// Shared global app header — search bar, notification bell, messages icon,
// theme toggle, avatar + name + profile dropdown. Include on every page
// right after partials/nav.php. Requires $user (current_user()) in scope.
// This is the ONE place this header is defined — do not duplicate its
// markup on individual pages.
//
// Mobile gets a dedicated experience, not a shrunk desktop one: a search icon
// that opens a full-screen overlay instead of a permanent search bar, the
// username hidden (avatar only), and the theme toggle moved into the profile
// menu to keep the header row down to search + bell + chat + avatar.
// Primary navigation on mobile is partials/nav.php's bottom tab bar; this
// header only carries secondary actions.
$__headerNotifCount = notif_unread_count((int) $user['id']);
$__headerAvatar      = avatar_inner_html($user['full_name'], $user['google_picture'] ?? null);
?>
<header class="app-header">
    <form class="app-search" action="courses.php" method="get">
        <i class="bi bi-search"></i>
        <input type="search" name="q" placeholder="Search courses, topics…" aria-label="Search">
    </form>

    <button type="button" class="app-icon-btn app-search-trigger" id="appSearchTrigger" aria-label="Search">
        <i class="bi bi-search"></i>
    </button>

    <div class="app-header-actions">
        <a class="app-icon-btn" href="notifications.php" title="Notifications">
            <i class="bi bi-bell-fill"></i>
            <?php if ($__headerNotifCount > 0): ?>
                <span class="notif-badge app-icon-badge"><?php echo $__headerNotifCount; ?></span>
            <?php endif; ?>
        </a>
        <a class="app-icon-btn" href="chat.php" title="Messages">
            <i class="bi bi-chat-dots-fill"></i>
        </a>
        <button class="theme-toggle app-header-theme-toggle" role="switch" onclick="toggleTheme()" title="Dark mode"></button>

        <div class="app-profile" id="appProfile">
            <button type="button" class="app-profile-btn" id="appProfileBtn" aria-haspopup="true" aria-expanded="false">
                <span class="app-avatar"><?php echo $__headerAvatar; ?></span>
                <span class="app-profile-name"><?php echo h($user['full_name']); ?></span>
                <i class="bi bi-chevron-down"></i>
            </button>
            <div class="app-profile-menu" id="appProfileMenu" hidden>
                <a href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
                <a href="settings.php"><i class="bi bi-gear-fill"></i> Settings</a>
                <?php if ($user['role'] !== 'student'): ?>
                    <a href="admin.php"><i class="bi bi-sliders"></i> Content Management</a>
                <?php endif; ?>
                <button type="button" class="app-profile-menu-theme" onclick="toggleTheme()">
                    <span class="app-profile-menu-theme-label"><i class="bi bi-moon-stars"></i> Dark mode</span>
                    <span class="theme-toggle app-profile-menu-toggle" role="switch"></span>
                </button>
                <div class="app-profile-menu-divider"></div>
                <a href="logout.php" class="app-profile-menu-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </div>
</header>

<!-- Mobile full-screen search overlay — replaces the permanent search bar,
     which stays desktop-only (see .app-search-trigger / .app-search in CSS). -->
<div class="app-search-overlay" id="appSearchOverlay" hidden>
    <form class="app-search-overlay-form" action="courses.php" method="get">
        <button type="button" class="app-search-overlay-back" id="appSearchOverlayClose" aria-label="Close search">
            <i class="bi bi-arrow-left"></i>
        </button>
        <input type="search" name="q" id="appSearchOverlayInput" placeholder="Search courses, topics…" aria-label="Search">
    </form>
</div>

<script>
    (function () {
        // ── Profile dropdown ────────────────────────────────────────────────
        var btn = document.getElementById('appProfileBtn');
        var menu = document.getElementById('appProfileMenu');
        if (btn && menu) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var wasOpen = !menu.hidden;
                menu.hidden = wasOpen;
                btn.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
            });
            document.addEventListener('click', function (e) {
                if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) {
                    menu.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    menu.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // ── Mobile full-screen search overlay ───────────────────────────────
        var trigger = document.getElementById('appSearchTrigger');
        var overlay = document.getElementById('appSearchOverlay');
        var overlayClose = document.getElementById('appSearchOverlayClose');
        var overlayInput = document.getElementById('appSearchOverlayInput');
        if (trigger && overlay) {
            trigger.addEventListener('click', function () {
                overlay.hidden = false;
                document.body.classList.add('drawer-open'); // reuse the same scroll-lock
                setTimeout(function () { overlayInput.focus(); }, 50);
            });
            function closeSearch() {
                overlay.hidden = true;
                document.body.classList.remove('drawer-open');
            }
            if (overlayClose) overlayClose.addEventListener('click', closeSearch);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) closeSearch();
            });
        }
    })();
</script>
