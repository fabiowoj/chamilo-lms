<?php
/* For licensing terms, see /license.txt */

/**
 * @package chamilo.messages
 */
$cidReset = true;
require_once __DIR__.'/../inc/global.inc.php';
api_block_anonymous_users();

if (api_get_setting('allow_message_tool') != 'true') {
    api_not_allowed(true);
}

$allowSocial = api_get_setting('allow_social_tool') === 'true';
$allowMessage = api_get_setting('allow_message_tool') === 'true';

if ($allowSocial) {
    $this_section = SECTION_SOCIAL;
    $interbreadcrumb[] = ['url' => api_get_path(WEB_PATH).'main/social/home.php', 'name' => get_lang('SocialNetwork')];
} else {
    $this_section = SECTION_MYPROFILE;
    $interbreadcrumb[] = ['url' => api_get_path(WEB_PATH).'main/auth/profile.php', 'name' => get_lang('Profile')];
}
$interbreadcrumb[] = ['url' => 'inbox.php', 'name' => get_lang('Messages')];

$social_right_content = '<div class="actions">';
if (api_get_setting('allow_message_tool') === 'true') {
    $social_right_content .= '<a href="'.api_get_path(WEB_PATH).'main/messages/new_message.php">'.
        Display::return_icon('new-message.png', get_lang('ComposeMessage')).'</a>';
    $social_right_content .= '<a href="'.api_get_path(WEB_PATH).'main/messages/inbox.php">'.
        Display::return_icon('inbox.png', get_lang('Inbox')).'</a>';
    $social_right_content .= '<a href="'.api_get_path(WEB_PATH).'main/messages/outbox.php">'.
        Display::return_icon('outbox.png', get_lang('Outbox')).'</a>';
}
$social_right_content .= '</div>';

if (empty($_GET['id'])) {
    $messageId = $_GET['id_send'];
    $source = 'outbox';
    $show_menu = 'messages_outbox';
} else {
    $messageId = $_GET['id'];
    $source = 'inbox';
    $show_menu = 'messages_inbox';
}

$message = '';

$logInfo = [
    'tool' => 'Messages',
    'tool_id' => 0,
    'tool_id_detail' => 0,
    'action' => $source,
    'action_details' => 'view-message',
];
Event::registerLog($logInfo);

// LEFT COLUMN
if (api_get_setting('allow_social_tool') === 'true') {
    // Block Social Menu
    $social_menu_block = SocialManager::show_social_menu($show_menu);
}
// MAIN CONTENT
$message .= MessageManager::showMessageBox($messageId, $source);

if (!empty($message)) {
    $social_right_content .= $message;
} else {
    api_not_allowed(true);
}
$tpl = new Template(get_lang('View'));
// Block Social Avatar
SocialManager::setSocialUserBlock($tpl, api_get_user_id(), $show_menu);

if (api_get_setting('allow_social_tool') === 'true') {
    $tpl->assign('social_menu_block', $social_menu_block);
    $tpl->assign('social_right_content', $social_right_content);
    $social_layout = $tpl->get_template('social/inbox.tpl');
    $tpl->display($social_layout);
} else {
    $content = $social_right_content;

    $tpl->assign('content', $content);
    $tpl->display_one_col_template();
}
