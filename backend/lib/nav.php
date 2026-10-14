<?php


/**
 * Render the shared top navigation. The active link is detected automatically
 * from the current script name, so every page can just call renderNav().
 */
function renderNav(): void
{
    if (isAdmin()) {
        $links = [
            'dashboard.php'  => 'Dashboard',
            'analytics.php'  => 'Analytics',
            'sites.php'      => 'Websites',
            'categories.php' => 'Categories',
            'mapping.php'    => 'Map Websites',
            'publish.php'    => 'Publish to Websites',
            'publer.php'     => 'Schedule Posts',
            'tasks.php'      => 'Tasks',
            'system.php'     => 'System',
            'users.php'      => 'Users',
        ];
        $home = 'dashboard.php';
    } else {
        // Editors can only post to websites and social media.
        $links = [
            'editor_dashboard.php' => 'Dashboard',
            'publish.php'          => 'Publish to Websites',
            'publer.php'           => 'Schedule Posts',
            'tasks.php'            => 'Tasks',
        ];
        $home = 'editor_dashboard.php';
    }

    $current  = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $initial  = strtoupper(substr((string) ($_SESSION['user_name'] ?? 'U'), 0, 1));

    echo '<nav class="top-nav">';
    echo '<a class="nav-brand" href="' . $home . '">' . htmlspecialchars(APP_NAME) . '</a>';
    echo '<div class="nav-links">';
    foreach ($links as $href => $label) {
        $active = ($href === $current) ? ' active' : '';
        echo '<a class="nav-link' . $active . '" href="' . $href . '">' . htmlspecialchars($label) . '</a>';
    }

    // Workspace switcher — quick switch from anywhere (only if the user has workspaces).
    $navUserId = (int) ($_SESSION['user_id'] ?? 0);
    $wsList    = $navUserId ? getAllowedWorkspaces($navUserId) : [];
    if (!empty($wsList)) {
        $activeWs = getActiveWorkspaceId($navUserId);
        $wsIcon   = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>';
        echo '<form class="nav-ws" method="get" action="switch_workspace.php" title="Switch workspace">';
        echo '<input type="hidden" name="return" value="' . htmlspecialchars($current) . '" />';
        echo '<span class="nav-ws-icon">' . $wsIcon . '</span>';
        echo '<select name="ws" onchange="this.form.submit()" aria-label="Switch workspace">';
        foreach ($wsList as $w) {
            $sel = ($w['id'] === $activeWs) ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($w['id']) . '"' . $sel . '>' . htmlspecialchars($w['name']) . '</option>';
        }
        echo '</select>';
        echo '<noscript><button type="submit">Go</button></noscript>';
        echo '</form>';
    }

    // Notifications bell — task-assignment alerts for the signed-in user.
    if ($navUserId) {
        $unreadCount   = getUnreadNotificationCount($navUserId);
        $notifications = getNotificationsForUser($navUserId, 10);
        echo '<details class="nav-notify">';
        echo '<summary title="Notifications"><span class="nav-notify-btn">🔔';
        if ($unreadCount > 0) {
            echo '<span class="nav-notify-badge">' . ($unreadCount > 99 ? '99+' : $unreadCount) . '</span>';
        }
        echo '</span></summary>';
        echo '<div class="nav-notify-menu">';
        if (empty($notifications)) {
            echo '<div class="nav-notify-empty">No notifications yet.</div>';
        } else {
            foreach ($notifications as $n) {
                $itemClass = empty($n['is_read']) ? ' unread' : '';
                $target    = $n['task_id'] ? ('task.php?id=' . (int) $n['task_id']) : 'tasks.php';
                $href      = 'notifications.php?id=' . (int) $n['id'] . '&redirect=' . urlencode($target);
                echo '<a class="nav-notify-item' . $itemClass . '" href="' . htmlspecialchars($href) . '">';
                echo '<span class="nav-notify-msg">' . htmlspecialchars($n['message']) . '</span>';
                echo '<span class="nav-notify-time">' . htmlspecialchars(timeAgo($n['created_at'])) . '</span>';
                echo '</a>';
            }
            if ($unreadCount > 0) {
                echo '<form method="post" action="notifications.php" class="nav-notify-markall">';
                echo '<input type="hidden" name="action" value="mark_all_read" />';
                echo '<input type="hidden" name="redirect" value="' . htmlspecialchars($current !== '' ? $current : 'tasks.php') . '" />';
                echo '<button type="submit">Mark all as read</button>';
                echo '</form>';
            }
        }
        echo '</div>';
        echo '</details>';
    }

    // Account menu: Profile sits under the account/logout button.
    echo '<details class="nav-account">';
    echo '<summary title="Account"><span class="nav-account-btn">' . htmlspecialchars($initial) . '</span></summary>';
    echo '<div class="nav-account-menu">';
    echo '<a class="nav-link' . ($current === 'profile.php' ? ' active' : '') . '" href="profile.php">Profile</a>';
    echo '<a class="nav-link nav-logout" href="logout.php">Logout</a>';
    echo '</div>';
    echo '</details>';

    echo '</div>';
    echo '</nav>';
    echo '<script>document.addEventListener("click",function(e){document.querySelectorAll(".nav-account,.nav-notify").forEach(function(d){if(d.open&&!d.contains(e.target)){d.open=false;}});});</script>';
}
