<?php
/* For licensing terms, see /license.txt */

use Chamilo\CoreBundle\Entity\Message;
use Chamilo\UserBundle\Entity\User;
use ChamiloSession as Session;

/**
 * Class MessageManager.
 *
 * This class provides methods for messages management.
 * Include/require it in your code to use its features.
 *
 * @package chamilo.library
 */
class MessageManager
{
    /**
     * Get count new messages for the current user from the database.
     *
     * @return int
     */
    public static function getCountNewMessages()
    {
        $userId = api_get_user_id();
        if (empty($userId)) {
            return false;
        }

        static $count;
        if (!isset($count)) {
            $cacheAvailable = api_get_configuration_value('apc');
            if ($cacheAvailable === true) {
                $var = api_get_configuration_value('apc_prefix').'social_messages_unread_u_'.$userId;
                if (apcu_exists($var)) {
                    $count = apcu_fetch($var);
                } else {
                    $count = self::getCountNewMessagesFromDB($userId);
                    apcu_store($var, $count, 60);
                }
            } else {
                $count = self::getCountNewMessagesFromDB($userId);
            }
        }

        return $count;
    }

    /**
     * Gets the total number of messages, used for the inbox sortable table.
     *
     * @param bool $unread
     * @param bool $listRead
     *
     * @return int
     */
    public static function getNumberOfMessages($unread = false, $listRead = false)
    {
        $table = Database::get_main_table(TABLE_MESSAGE);
        $list = null;
        if ($unread) {
            $condition_msg_status = ' msg_status = '.MESSAGE_STATUS_UNREAD.' ';
        } else {
            $condition_msg_status = ' msg_status IN('.MESSAGE_STATUS_NEW.','.MESSAGE_STATUS_UNREAD.') ';
        }

        $keyword = Session::read('message_search_keyword');
        $keywordCondition = '';
        if (!empty($keyword)) {
            $keyword = Database::escape_string($keyword);
            $keywordCondition = " AND (title like '%$keyword%' OR content LIKE '%$keyword%') ";
        }
        if ($listRead) {
            $sql = "SELECT * ";
        } else {
            $sql = "SELECT COUNT(id) as number_messages ";
        }
        $sql .= " FROM $table
                  WHERE $condition_msg_status AND
                    user_receiver_id=".api_get_user_id()."
                    $keywordCondition
                ";
        $result = Database::query($sql);
        if ($listRead) {
            while ($row = Database::fetch_array($result, 'ASSOC')) {
                $list[] = $row;
            }

            return $list;
        } else {
            $count = Database::fetch_array($result);
            if ($result) {
                return (int) $count['number_messages'];
            }
        }

        return 0;
    }

    /**
     * Gets information about some messages, used for the inbox sortable table.
     *
     * @param int    $from
     * @param int    $number_of_items
     * @param string $column
     * @param string $direction
     * @param int    $userId
     *
     * @return array
     */
    public static function get_message_data(
        $from,
        $number_of_items,
        $column,
        $direction,
        $userId = 0
    ) {
        $from = (int) $from;
        $number_of_items = (int) $number_of_items;
        $userId = empty($userId) ? api_get_user_id() : (int) $userId;

        // Forcing this order.
        if (!isset($direction)) {
            $column = 2;
            $direction = 'DESC';
        } else {
            $column = (int) $column;
            if (!in_array($direction, ['ASC', 'DESC'])) {
                $direction = 'ASC';
            }
        }

        if (!in_array($column, [0, 1, 2])) {
            $column = 2;
        }

        $keyword = Session::read('message_search_keyword');
        $keywordCondition = '';
        if (!empty($keyword)) {
            $keyword = Database::escape_string($keyword);
            $keywordCondition = " AND (title like '%$keyword%' OR content LIKE '%$keyword%') ";
        }

        $table = Database::get_main_table(TABLE_MESSAGE);
        $sql = "SELECT 
                    id as col0, 
                    title as col1, 
                    send_date as col2, 
                    msg_status as col3,
                    user_sender_id
                FROM $table
                WHERE
                    user_receiver_id=".$userId." AND
                    msg_status IN (".MESSAGE_STATUS_NEW.", ".MESSAGE_STATUS_UNREAD.")
                    $keywordCondition
                ORDER BY col$column $direction
                LIMIT $from, $number_of_items";

        $result = Database::query($sql);
        $messageList = [];
        $newMessageLink = api_get_path(WEB_CODE_PATH).'messages/new_message.php';
        while ($row = Database::fetch_array($result, 'ASSOC')) {
            $messageId = $row['col0'];
            $title = $row['col1'];
            $sendDate = $row['col2'];
            $status = $row['col3'];
            $senderId = $row['user_sender_id'];

            $title = Security::remove_XSS($title, STUDENT, true);
            $title = cut($title, 80, true);

            $class = 'class = "read"';
            if ($status == 1) {
                $class = 'class = "unread"';
            }

            $userInfo = api_get_user_info($senderId);
            if (!empty($senderId) && !empty($userInfo)) {
                $message[1] = '<img class="rounded-circle mr-2" src="'.$userInfo['avatar_small'].'"/>';
                $message[1] .= $userInfo['complete_name_with_username'];

                $message[2] = '<a '.$class.' href="view_message.php?id='.$messageId.'">'.$title.'</a>';
                $message[4] = '<div class="btn-group" role="group">'.
                    Display::url(
                        Display::returnFontAwesomeIcon('reply', 'sm'),
                        $newMessageLink.'?re_id='.$messageId,
                        ['title' => get_lang('ReplyToMessage'), 'class' => 'btn btn-outline-secondary btn-sm']
                    );
            } else {
                $message[1] = '<img class="rounded-circle" src="'.$userInfo['avatar_small'].'"/>';
                $message[1] .= get_lang('UnknownUser');

                $message[2] = '<a '.$class.' href="view_message.php?id='.$messageId.'">'.$title.'</a>';

                $message[4] = '<div class="btn-group" role="group">'.
                    Display::url(
                        Display::returnFontAwesomeIcon('reply', 'sm'),
                        '#',
                        ['title' => get_lang('ReplyToMessage'), 'class' => 'btn btn-outline-secondary btn-sm']
                    );
            }

            $message[0] = $messageId;
            $message[3] = api_convert_and_format_date($sendDate, DATE_TIME_FORMAT_LONG);
            $message[4] .=
                Display::url(
                    Display::returnFontAwesomeIcon('share', 'sm'),
                    $newMessageLink.'?forward_id='.$messageId,
                    ['title' => get_lang('ForwardMessage'), 'class' => 'btn btn-outline-secondary btn-sm']
                ).
                '<a class="btn btn-outline-secondary btn-sm" title="'.addslashes(
                    get_lang('DeleteMessage')
                ).'" onclick="javascript:if(!confirm('."'".addslashes(
                    api_htmlentities(get_lang('ConfirmDeleteMessage'))
                )."'".')) return false;" href="inbox.php?action=deleteone&id='.$messageId.'">'.
                Display::returnFontAwesomeIcon('trash', 'sm').'</a></div>';
            foreach ($message as $key => $value) {
                $message[$key] = api_xml_http_response_encode($value);
            }
            $messageList[] = $message;
        }

        return $messageList;
    }

    /**
     * @param array  $aboutUserInfo
     * @param array  $fromUserInfo
     * @param string $subject
     * @param string $content
     *
     * @return bool
     */
    public static function sendMessageAboutUser(
        $aboutUserInfo,
        $fromUserInfo,
        $subject,
        $content
    ) {
        if (empty($aboutUserInfo) || empty($fromUserInfo)) {
            return false;
        }

        if (empty($fromUserInfo['id']) || empty($aboutUserInfo['id'])) {
            return false;
        }

        $table = Database::get_main_table(TABLE_MESSAGE);
        $now = api_get_utc_datetime();
        $params = [
            'user_sender_id' => $fromUserInfo['id'],
            'user_receiver_id' => $aboutUserInfo['id'],
            'msg_status' => MESSAGE_STATUS_CONVERSATION,
            'send_date' => $now,
            'title' => $subject,
            'content' => $content,
            'group_id' => 0,
            'parent_id' => 0,
            'update_date' => $now,
        ];
        $id = Database::insert($table, $params);

        if ($id) {
            return true;
        }

        return false;
    }

    /**
     * @param array $aboutUserInfo
     *
     * @return array
     */
    public static function getMessagesAboutUser($aboutUserInfo)
    {
        if (!empty($aboutUserInfo)) {
            $table = Database::get_main_table(TABLE_MESSAGE);
            $sql = 'SELECT id FROM '.$table.'
                    WHERE 
                      user_receiver_id = '.$aboutUserInfo['id'].' AND 
                      msg_status = '.MESSAGE_STATUS_CONVERSATION.'                    
                    ';
            $result = Database::query($sql);
            $messages = [];
            $repo = Database::getManager()->getRepository('ChamiloCoreBundle:Message');
            while ($row = Database::fetch_array($result)) {
                $message = $repo->find($row['id']);
                $messages[] = $message;
            }

            return $messages;
        }

        return [];
    }

    /**
     * @param array $userInfo
     *
     * @return string
     */
    public static function getMessagesAboutUserToString($userInfo)
    {
        $messages = self::getMessagesAboutUser($userInfo);
        $html = '';
        if (!empty($messages)) {
            /** @var Message $message */
            foreach ($messages as $message) {
                $tag = 'message_'.$message->getId();
                $tagAccordion = 'accordion_'.$message->getId();
                $tagCollapse = 'collapse_'.$message->getId();
                $date = Display::dateToStringAgoAndLongDate(
                    $message->getSendDate()
                );
                $localTime = api_get_local_time(
                    $message->getSendDate(),
                    null,
                    null,
                    false,
                    false
                );
                $senderId = $message->getUserSenderId();
                $senderInfo = api_get_user_info($senderId);
                $html .= Display::panelCollapse(
                    $localTime.' '.$senderInfo['complete_name'].' '.$message->getTitle(),
                    $message->getContent().'<br />'.$date.'<br />'.get_lang(
                        'Author'
                    ).': '.$senderInfo['complete_name_with_message_link'],
                    $tag,
                    null,
                    $tagAccordion,
                    $tagCollapse,
                    false
                );
            }
        }

        return $html;
    }

    /**
     * @param int    $senderId
     * @param int    $receiverId
     * @param string $subject
     * @param string $message
     *
     * @return bool
     */
    public static function messageWasAlreadySent($senderId, $receiverId, $subject, $message)
    {
        $table = Database::get_main_table(TABLE_MESSAGE);
        $senderId = (int) $senderId;
        $receiverId = (int) $receiverId;
        $subject = Database::escape_string($subject);
        $message = Database::escape_string($message);

        $sql = "SELECT * FROM $table
                WHERE 
                    user_sender_id = $senderId AND
                    user_receiver_id = $receiverId AND 
                    title = '$subject' AND 
                    content = '$message' AND
                    (msg_status = ".MESSAGE_STATUS_UNREAD." OR msg_status = ".MESSAGE_STATUS_NEW.")                    
                ";
        $result = Database::query($sql);

        return Database::num_rows($result) > 0;
    }

    /**
     * Sends a message to a user/group.
     *
     * @param int    $receiver_user_id
     * @param string $subject
     * @param string $content
     * @param array  $attachments                files array($_FILES) (optional)
     * @param array  $fileCommentList            about attachment files (optional)
     * @param int    $group_id                   (optional)
     * @param int    $parent_id                  (optional)
     * @param int    $editMessageId              id for updating the message (optional)
     * @param int    $topic_id                   (optional) the default value is the current user_id
     * @param int    $sender_id
     * @param bool   $directMessage
     * @param int    $forwardId
     * @param array  $smsParameters
     * @param bool   $checkCurrentAudioId
     * @param bool   $forceTitleWhenSendingEmail force the use of $title as subject instead of "You have a new message"
     *
     * @return bool
     */
    public static function send_message(
        $receiver_user_id,
        $subject,
        $content,
        array $attachments = [],
        array $fileCommentList = [],
        $group_id = 0,
        $parent_id = 0,
        $editMessageId = 0,
        $topic_id = 0,
        $sender_id = 0,
        $directMessage = false,
        $forwardId = 0,
        $smsParameters = [],
        $checkCurrentAudioId = false,
        $forceTitleWhenSendingEmail = false
    ) {
        $table = Database::get_main_table(TABLE_MESSAGE);
        $group_id = (int) $group_id;
        $receiver_user_id = (int) $receiver_user_id;
        $parent_id = (int) $parent_id;
        $editMessageId = (int) $editMessageId;
        $topic_id = (int) $topic_id;

        if (!empty($receiver_user_id)) {
            $receiverUserInfo = api_get_user_info($receiver_user_id);

            // Disabling messages for inactive users.
            if ($receiverUserInfo['active'] == 0) {
                return false;
            }
        }

        $user_sender_id = empty($sender_id) ? api_get_user_id() : (int) $sender_id;
        if (empty($user_sender_id)) {
            Display::addFlash(Display::return_message(get_lang('UserDoesNotExist'), 'warning'));

            return false;
        }

        $totalFileSize = 0;
        $attachmentList = [];
        if (is_array($attachments)) {
            $counter = 0;
            foreach ($attachments as $attachment) {
                $attachment['comment'] = isset($fileCommentList[$counter]) ? $fileCommentList[$counter] : '';
                $fileSize = isset($attachment['size']) ? $attachment['size'] : 0;
                if (is_array($fileSize)) {
                    foreach ($fileSize as $size) {
                        $totalFileSize += $size;
                    }
                } else {
                    $totalFileSize += $fileSize;
                }
                $attachmentList[] = $attachment;
                $counter++;
            }
        }

        if ($checkCurrentAudioId) {
            // Add the audio file as an attachment
            $audioId = Session::read('current_audio_id');
            if (!empty($audioId)) {
                $file = api_get_uploaded_file('audio_message', api_get_user_id(), $audioId);
                if (!empty($file)) {
                    $audioAttachment = [
                        'name' => basename($file),
                        'comment' => 'audio_message',
                        'size' => filesize($file),
                        'tmp_name' => $file,
                        'error' => 0,
                        'type' => DocumentManager::file_get_mime_type(basename($file)),
                    ];
                    // create attachment from audio message
                    $attachmentList[] = $audioAttachment;
                }
            }
        }

        // Validating fields
        if (empty($subject) && empty($group_id)) {
            Display::addFlash(
                Display::return_message(
                    get_lang('YouShouldWriteASubject'),
                    'warning'
                )
            );

            return false;
        } elseif ($totalFileSize > intval(api_get_setting('message_max_upload_filesize'))) {
            $warning = sprintf(
                get_lang('FilesSizeExceedsX'),
                format_file_size(api_get_setting('message_max_upload_filesize'))
            );

            Display::addFlash(Display::return_message($warning, 'warning'));

            return false;
        }

        // Just in case we replace the and \n and \n\r while saving in the DB
        // $content = str_replace(array("\n", "\n\r"), '<br />', $content);
        $now = api_get_utc_datetime();
        if (!empty($receiver_user_id) || !empty($group_id)) {
            // message for user friend
            //@todo it's possible to edit a message? yes, only for groups
            if (!empty($editMessageId)) {
                $query = " UPDATE $table SET
                              update_date = '".$now."',
                              content = '".Database::escape_string($content)."'
                           WHERE id = '$editMessageId' ";
                Database::query($query);
                $messageId = $editMessageId;
            } else {
                $params = [
                    'user_sender_id' => $user_sender_id,
                    'msg_status' => MESSAGE_STATUS_UNREAD,
                    'send_date' => $now,
                    'title' => $subject,
                    'content' => $content,
                    'group_id' => $group_id,
                    'parent_id' => $parent_id,
                    'update_date' => $now,
                ];
                if (!empty($receiver_user_id)) {
                    $params['user_receiver_id'] = $receiver_user_id;
                }
                $messageId = Database::insert($table, $params);
            }

            // Forward also message attachments
            if (!empty($forwardId)) {
                $attachments = self::getAttachmentList($forwardId);
                foreach ($attachments as $attachment) {
                    if (!empty($attachment['file_source'])) {
                        $file = [
                            'name' => $attachment['filename'],
                            'tmp_name' => $attachment['file_source'],
                            'size' => $attachment['size'],
                            'error' => 0,
                            'comment' => $attachment['comment'],
                        ];

                        // Inject this array so files can be added when sending and email with the mailer
                        $attachmentList[] = $file;
                    }
                }
            }

            // Save attachment file for inbox messages
            if (is_array($attachmentList)) {
                foreach ($attachmentList as $attachment) {
                    if ($attachment['error'] == 0) {
                        $comment = $attachment['comment'];
                        self::saveMessageAttachmentFile(
                            $attachment,
                            $comment,
                            $messageId,
                            null,
                            $receiver_user_id,
                            $group_id
                        );
                    }
                }
            }

            if (empty($group_id)) {
                // message in outbox for user friend or group
                $params = [
                    'user_sender_id' => $user_sender_id,
                    'user_receiver_id' => $receiver_user_id,
                    'msg_status' => MESSAGE_STATUS_OUTBOX,
                    'send_date' => $now,
                    'title' => $subject,
                    'content' => $content,
                    'group_id' => $group_id,
                    'parent_id' => $parent_id,
                    'update_date' => $now,
                ];
                $outbox_last_id = Database::insert($table, $params);

                // save attachment file for outbox messages
                if (is_array($attachmentList)) {
                    foreach ($attachmentList as $attachment) {
                        if ($attachment['error'] == 0) {
                            $comment = $attachment['comment'];
                            self::saveMessageAttachmentFile(
                                $attachment,
                                $comment,
                                $outbox_last_id,
                                $user_sender_id
                            );
                        }
                    }
                }
            }

            // Load user settings.
            $notification = new Notification();
            $sender_info = api_get_user_info($user_sender_id);

            // add file attachment additional attributes
            $attachmentAddedByMail = [];
            foreach ($attachmentList as $attachment) {
                $attachmentAddedByMail[] = [
                    'path' => $attachment['tmp_name'],
                    'filename' => $attachment['name'],
                ];
            }

            if (empty($group_id)) {
                $type = Notification::NOTIFICATION_TYPE_MESSAGE;
                if ($directMessage) {
                    $type = Notification::NOTIFICATION_TYPE_DIRECT_MESSAGE;
                }
                $notification->saveNotification(
                    $messageId,
                    $type,
                    [$receiver_user_id],
                    $subject,
                    $content,
                    $sender_info,
                    $attachmentAddedByMail,
                    $smsParameters,
                    $forceTitleWhenSendingEmail
                );
            } else {
                $usergroup = new UserGroup();
                $group_info = $usergroup->get($group_id);
                $group_info['topic_id'] = $topic_id;
                $group_info['msg_id'] = $messageId;

                $user_list = $usergroup->get_users_by_group(
                    $group_id,
                    false,
                    [],
                    0,
                    1000
                );

                // Adding more sense to the message group
                $subject = sprintf(get_lang('ThereIsANewMessageInTheGroupX'), $group_info['name']);
                $new_user_list = [];
                foreach ($user_list as $user_data) {
                    $new_user_list[] = $user_data['id'];
                }
                $group_info = [
                    'group_info' => $group_info,
                    'user_info' => $sender_info,
                ];
                $notification->saveNotification(
                    $messageId,
                    Notification::NOTIFICATION_TYPE_GROUP,
                    $new_user_list,
                    $subject,
                    $content,
                    $group_info,
                    $attachmentAddedByMail,
                    $smsParameters
                );
            }

            return $messageId;
        }

        return false;
    }

    /**
     * @param int    $receiver_user_id
     * @param int    $subject
     * @param string $message
     * @param int    $sender_id
     * @param bool   $sendCopyToDrhUsers send copy to related DRH users
     * @param bool   $directMessage
     * @param array  $smsParameters
     * @param bool   $uploadFiles        Do not upload files using the MessageManager class
     * @param array  $attachmentList
     *
     * @return bool
     */
    public static function send_message_simple(
        $receiver_user_id,
        $subject,
        $message,
        $sender_id = 0,
        $sendCopyToDrhUsers = false,
        $directMessage = false,
        $smsParameters = [],
        $uploadFiles = true,
        $attachmentList = []
    ) {
        $files = $_FILES ? $_FILES : [];
        if ($uploadFiles === false) {
            $files = [];
        }
        // $attachmentList must have: tmp_name, name, size keys
        if (!empty($attachmentList)) {
            $files = $attachmentList;
        }
        $result = self::send_message(
            $receiver_user_id,
            $subject,
            $message,
            $files,
            [],
            null,
            null,
            null,
            null,
            $sender_id,
            $directMessage,
            0,
            $smsParameters
        );

        if ($sendCopyToDrhUsers) {
            $userInfo = api_get_user_info($receiver_user_id);
            $drhList = UserManager::getDrhListFromUser($receiver_user_id);
            if (!empty($drhList)) {
                foreach ($drhList as $drhInfo) {
                    $message = sprintf(
                        get_lang('CopyOfMessageSentToXUser'),
                        $userInfo['complete_name']
                    ).' <br />'.$message;

                    self::send_message_simple(
                        $drhInfo['user_id'],
                        $subject,
                        $message,
                        $sender_id,
                        false,
                        $directMessage
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Update parent ids for other receiver user from current message in groups.
     *
     * @author Christian Fasanando Flores
     *
     * @param int $parent_id
     * @param int $receiver_user_id
     * @param int $messageId
     */
    public static function update_parent_ids_from_reply(
        $parent_id,
        $receiver_user_id,
        $messageId
    ) {
        $table = Database::get_main_table(TABLE_MESSAGE);
        $parent_id = intval($parent_id);
        $receiver_user_id = intval($receiver_user_id);
        $messageId = intval($messageId);

        // first get data from message id (parent)
        $sql = "SELECT * FROM $table WHERE id = '$parent_id'";
        $rs_message = Database::query($sql);
        $row_message = Database::fetch_array($rs_message);

        // get message id from data found early for other receiver user
        $sql = "SELECT id FROM $table
                WHERE
                    user_sender_id ='{$row_message['user_sender_id']}' AND
                    title='{$row_message['title']}' AND
                    content='{$row_message['content']}' AND
                    group_id='{$row_message['group_id']}' AND
                    user_receiver_id='$receiver_user_id'";
        $result = Database::query($sql);
        $row = Database::fetch_array($result);

        // update parent_id for other user receiver
        $sql = "UPDATE $table SET parent_id = ".$row['id']."
                WHERE id = $messageId";
        Database::query($sql);
    }

    /**
     * @param int $user_receiver_id
     * @param int $id
     *
     * @return bool
     */
    public static function delete_message_by_user_receiver($user_receiver_id, $id)
    {
        $table = Database::get_main_table(TABLE_MESSAGE);
        if ($id != strval(intval($id))) {
            return false;
        }
        $id = (int) $id;
        $user_receiver_id = (int) $user_receiver_id;
        $sql = "SELECT * FROM $table
                WHERE id = ".$id." AND msg_status <>".MESSAGE_STATUS_OUTBOX;
        $rs = Database::query($sql);

        if (Database::num_rows($rs) > 0) {
            // delete attachment file
            self::delete_message_attachment_file($id, $user_receiver_id);
            // delete message
            $query = "UPDATE $table 
                      SET msg_status = ".MESSAGE_STATUS_DELETED."
                      WHERE 
                        user_receiver_id=".$user_receiver_id." AND 
                        id = ".$id;
            Database::query($query);

            return true;
        } else {
            return false;
        }
    }

    /**
     * Set status deleted.
     *
     * @author Isaac FLores Paz <isaac.flores@dokeos.com>
     *
     * @param  int
     * @param  int
     *
     * @return bool
     */
    public static function delete_message_by_user_sender($user_sender_id, $id)
    {
        if ($id != strval(intval($id))) {
            return false;
        }

        $table = Database::get_main_table(TABLE_MESSAGE);

        $id = intval($id);
        $user_sender_id = intval($user_sender_id);

        $sql = "SELECT * FROM $table WHERE id='$id'";
        $rs = Database::query($sql);

        if (Database::num_rows($rs) > 0) {
            // delete attachment file
            self::delete_message_attachment_file($id, $user_sender_id);
            // delete message
            $sql = "UPDATE $table 
                    SET msg_status = ".MESSAGE_STATUS_DELETED."
                    WHERE user_sender_id='$user_sender_id' AND id='$id'";
            Database::query($sql);

            return true;
        }

        return false;
    }

    /**
     * Saves a message attachment files.
     *
     * @param array $file_attach $_FILES['name']
     * @param  string    a comment about the uploaded file
     * @param  int        message id
     * @param  int        receiver user id (optional)
     * @param  int        sender user id (optional)
     * @param  int        group id (optional)
     */
    public static function saveMessageAttachmentFile(
        $file_attach,
        $file_comment,
        $message_id,
        $receiver_user_id = 0,
        $sender_user_id = 0,
        $group_id = 0
    ) {
        $table = Database::get_main_table(TABLE_MESSAGE_ATTACHMENT);

        // Try to add an extension to the file if it hasn't one
        $type = isset($file_attach['type']) ? $file_attach['type'] : '';
        if (empty($type)) {
            $type = DocumentManager::file_get_mime_type($file_attach['name']);
        }
        $new_file_name = add_ext_on_mime(stripslashes($file_attach['name']), $type);

        // user's file name
        $file_name = $file_attach['name'];
        if (!filter_extension($new_file_name)) {
            Display::addFlash(Display::return_message(get_lang('UplUnableToSaveFileFilteredExtension'), 'error'));
        } else {
            $new_file_name = uniqid('');
            if (!empty($receiver_user_id)) {
                $message_user_id = $receiver_user_id;
            } else {
                $message_user_id = $sender_user_id;
            }

            // User-reserved directory where photos have to be placed.*
            $userGroup = new UserGroup();
            if (!empty($group_id)) {
                $path_user_info = $userGroup->get_group_picture_path_by_id(
                    $group_id,
                    'system',
                    true
                );
            } else {
                $path_user_info['dir'] = UserManager::getUserPathById($message_user_id, 'system');
            }

            $path_message_attach = $path_user_info['dir'].'message_attachments/';
            // If this directory does not exist - we create it.
            if (!file_exists($path_message_attach)) {
                @mkdir($path_message_attach, api_get_permissions_for_new_directories(), true);
            }

            $new_path = $path_message_attach.$new_file_name;
            $fileCopied = false;
            if (isset($file_attach['tmp_name']) && !empty($file_attach['tmp_name'])) {
                if (is_uploaded_file($file_attach['tmp_name'])) {
                    @copy($file_attach['tmp_name'], $new_path);
                    $fileCopied = true;
                } else {
                    // 'tmp_name' can be set by the ticket or when forwarding a message
                    if (file_exists($file_attach['tmp_name'])) {
                        @copy($file_attach['tmp_name'], $new_path);
                        $fileCopied = true;
                    }
                }
            }

            if ($fileCopied) {
                // Storing the attachments if any
                $params = [
                    'filename' => $file_name,
                    'comment' => $file_comment,
                    'path' => $new_file_name,
                    'message_id' => $message_id,
                    'size' => $file_attach['size'],
                ];

                return Database::insert($table, $params);
            }
        }

        return false;
    }

    /**
     * Delete message attachment files (logically updating the row with a suffix _DELETE_id).
     *
     * @param  int    message id
     * @param  int    message user id (receiver user id or sender user id)
     * @param  int    group id (optional)
     */
    public static function delete_message_attachment_file(
        $message_id,
        $message_uid,
        $group_id = 0
    ) {
        $message_id = (int) $message_id;
        $message_uid = (int) $message_uid;
        $table_message_attach = Database::get_main_table(TABLE_MESSAGE_ATTACHMENT);

        $sql = "SELECT * FROM $table_message_attach 
                WHERE message_id = '$message_id'";
        $rs = Database::query($sql);
        while ($row = Database::fetch_array($rs)) {
            $path = $row['path'];
            $attach_id = $row['id'];
            $new_path = $path.'_DELETED_'.$attach_id;

            if (!empty($group_id)) {
                $userGroup = new UserGroup();
                $path_user_info = $userGroup->get_group_picture_path_by_id(
                    $group_id,
                    'system',
                    true
                );
            } else {
                $path_user_info['dir'] = UserManager::getUserPathById(
                    $message_uid,
                    'system'
                );
            }

            $path_message_attach = $path_user_info['dir'].'message_attachments/';
            if (is_file($path_message_attach.$path)) {
                if (rename($path_message_attach.$path, $path_message_attach.$new_path)) {
                    $sql = "UPDATE $table_message_attach set path='$new_path'
                            WHERE id ='$attach_id'";
                    Database::query($sql);
                }
            }
        }
    }

    /**
     * @param int $user_id
     * @param int $message_id
     * @param int $type
     *
     * @return bool
     */
    public static function update_message_status($user_id, $message_id, $type)
    {
        $user_id = (int) $user_id;
        $message_id = (int) $message_id;
        $type = (int) $type;

        if (empty($user_id) || empty($message_id)) {
            return false;
        }

        $table_message = Database::get_main_table(TABLE_MESSAGE);
        $sql = "UPDATE $table_message SET
                    msg_status = '$type'
                WHERE
                    user_receiver_id = ".$user_id." AND
                    id = '".$message_id."'";
        Database::query($sql);
    }

    /**
     * get messages by group id.
     *
     * @param int $group_id group id
     *
     * @return array
     */
    public static function get_messages_by_group($group_id)
    {
        if ($group_id != strval(intval($group_id))) {
            return false;
        }

        $table = Database::get_main_table(TABLE_MESSAGE);
        $group_id = intval($group_id);
        $sql = "SELECT * FROM $table
                WHERE
                    group_id= $group_id AND
                    msg_status NOT IN ('".MESSAGE_STATUS_OUTBOX."', '".MESSAGE_STATUS_DELETED."')
                ORDER BY id";
        $rs = Database::query($sql);
        $data = [];
        if (Database::num_rows($rs) > 0) {
            while ($row = Database::fetch_array($rs, 'ASSOC')) {
                $data[] = $row;
            }
        }

        return $data;
    }

    /**
     * get messages by group id.
     *
     * @param int $group_id
     * @param int $message_id
     *
     * @return array
     */
    public static function get_messages_by_group_by_message($group_id, $message_id)
    {
        if ($group_id != strval(intval($group_id))) {
            return false;
        }
        $table = Database::get_main_table(TABLE_MESSAGE);
        $group_id = intval($group_id);
        $sql = "SELECT * FROM $table
                WHERE
                    group_id = $group_id AND
                    msg_status NOT IN ('".MESSAGE_STATUS_OUTBOX."', '".MESSAGE_STATUS_DELETED."')
                ORDER BY id ";

        $rs = Database::query($sql);
        $data = [];
        $parents = [];
        if (Database::num_rows($rs) > 0) {
            while ($row = Database::fetch_array($rs, 'ASSOC')) {
                if ($message_id == $row['parent_id'] || in_array($row['parent_id'], $parents)) {
                    $parents[] = $row['id'];
                    $data[] = $row;
                }
            }
        }

        return $data;
    }

    /**
     * Get messages by parent id optionally with limit.
     *
     * @param  int        parent id
     * @param  int        group id (optional)
     * @param  int        offset (optional)
     * @param  int        limit (optional)
     *
     * @return array
     */
    public static function getMessagesByParent($parentId, $groupId = 0, $offset = 0, $limit = 0)
    {
        $table = Database::get_main_table(TABLE_MESSAGE);
        $parentId = (int) $parentId;

        if (empty($parentId)) {
            return [];
        }

        $condition_group_id = '';
        if (!empty($groupId)) {
            $groupId = (int) $groupId;
            $condition_group_id = " AND group_id = '$groupId' ";
        }

        $condition_limit = '';
        if ($offset && $limit) {
            $offset = (int) $offset;
            $limit = (int) $limit;
            $offset = ($offset - 1) * $limit;
            $condition_limit = " LIMIT $offset,$limit ";
        }

        $sql = "SELECT * FROM $table
                WHERE
                    parent_id='$parentId' AND
                    msg_status NOT IN (".MESSAGE_STATUS_OUTBOX.", ".MESSAGE_STATUS_WALL_DELETE.")                    
                    $condition_group_id
                ORDER BY send_date DESC $condition_limit ";
        $rs = Database::query($sql);
        $data = [];
        if (Database::num_rows($rs) > 0) {
            while ($row = Database::fetch_array($rs)) {
                $data[$row['id']] = $row;
            }
        }

        return $data;
    }

    /**
     * Gets information about messages sent.
     *
     * @param int
     * @param int
     * @param string
     * @param string
     *
     * @return array
     */
    public static function get_message_data_sent(
        $from,
        $number_of_items,
        $column,
        $direction
    ) {
        $from = (int) $from;
        $number_of_items = (int) $number_of_items;
        if (!isset($direction)) {
            $column = 2;
            $direction = 'DESC';
        } else {
            $column = (int) $column;
            if (!in_array($direction, ['ASC', 'DESC'])) {
                $direction = 'ASC';
            }
        }

        if (!in_array($column, [0, 1, 2])) {
            $column = 2;
        }
        $table = Database::get_main_table(TABLE_MESSAGE);
        $request = api_is_xml_http_request();
        $keyword = Session::read('message_sent_search_keyword');
        $keywordCondition = '';
        if (!empty($keyword)) {
            $keyword = Database::escape_string($keyword);
            $keywordCondition = " AND (title like '%$keyword%' OR content LIKE '%$keyword%') ";
        }

        $sql = "SELECT
                    id as col0, 
                    title as col1, 
                    send_date as col2, 
                    user_receiver_id, 
                    msg_status,
                    user_sender_id
                FROM $table
                WHERE
                    user_sender_id = ".api_get_user_id()." AND
                    msg_status = ".MESSAGE_STATUS_OUTBOX."
                    $keywordCondition
                ORDER BY col$column $direction
                LIMIT $from, $number_of_items";
        $result = Database::query($sql);

        $message_list = [];
        while ($row = Database::fetch_array($result, 'ASSOC')) {
            $messageId = $row['col0'];
            $title = $row['col1'];
            $sendDate = $row['col2'];
            $status = $row['msg_status'];
            $senderId = $row['user_sender_id'];

            if ($request === true) {
                $message[0] = '<input type="checkbox" value='.$messageId.' name="out[]">';
            } else {
                $message[0] = $messageId;
            }

            $class = 'class = "read"';
            $title = Security::remove_XSS($title);
            $userInfo = api_get_user_info($senderId);
            if ($request === true) {
                $message[1] = '<a onclick="show_sent_message('.$messageId.')" href="javascript:void(0)">'.
                    $userInfo['complete_name_with_username'].'</a>';
                $message[2] = '<a onclick="show_sent_message('.$messageId.')" href="javascript:void(0)">'.str_replace(
                        "\\",
                        "",
                        $title
                    ).'</a>';
                //date stays the same
                $message[3] = api_convert_and_format_date($sendDate, DATE_TIME_FORMAT_LONG);
                $message[4] = '&nbsp;&nbsp;<a title="'.addslashes(
                        get_lang('DeleteMessage')
                    ).'" onclick="delete_one_message_outbox('.$messageId.')" href="javascript:void(0)"  >'.
                    Display::returnFontAwesomeIcon('trash', 2).'</a>';
            } else {
                $message[1] = '<img class="rounded-circle mr-2" src="'.$userInfo['avatar_small'].'"/>';
                $message[1] .= $userInfo['complete_name_with_username'];
                $message[2] = '<a '.$class.' onclick="show_sent_message('.$messageId.')" href="../messages/view_message.php?id_send='.$messageId.'">'.$title.'</a>';
                $message[3] = api_convert_and_format_date($sendDate, DATE_TIME_FORMAT_LONG);
                $message[4] = '<a class="btn btn-outline-secondary btn-sm" title="'.addslashes(
                        get_lang('DeleteMessage')
                    ).'" href="outbox.php?action=deleteone&id='.$messageId.'"  onclick="javascript:if(!confirm('."'".addslashes(
                        api_htmlentities(get_lang('ConfirmDeleteMessage'))
                    )."'".')) return false;" >'.
                    Display::returnFontAwesomeIcon('trash', 'fa-sm').'</a>';
            }

            $message_list[] = $message;
        }

        return $message_list;
    }

    /**
     * Gets information about number messages sent.
     *
     * @author Isaac FLores Paz <isaac.flores@dokeos.com>
     *
     * @param void
     *
     * @return int
     */
    public static function getNumberOfMessagesSent()
    {
        $table = Database::get_main_table(TABLE_MESSAGE);
        $keyword = Session::read('message_sent_search_keyword');
        $keywordCondition = '';
        if (!empty($keyword)) {
            $keyword = Database::escape_string($keyword);
            $keywordCondition = " AND (title like '%$keyword%' OR content LIKE '%$keyword%') ";
        }

        $sql = "SELECT COUNT(id) as number_messages 
                FROM $table
                WHERE
                  msg_status = ".MESSAGE_STATUS_OUTBOX." AND
                  user_sender_id = ".api_get_user_id()."
                  $keywordCondition
                ";
        $result = Database::query($sql);
        $result = Database::fetch_array($result);

        return $result['number_messages'];
    }

    /**
     * display message box in the inbox.
     *
     * @param int the message id
     * @param string inbox or outbox strings are available
     *
     * @todo replace numbers with letters in the $row array pff...
     *
     * @return array the message content
     */
    public static function showMessageBox($messageId, $source = 'inbox')
    {
        $table = Database::get_main_table(TABLE_MESSAGE);
        $messageId = (int) $messageId;
        $currentUserId = api_get_user_id();
        $msg = [];

        if ($source == 'outbox') {
            if (isset($messageId) && is_numeric($messageId)) {
                $query = "SELECT * FROM $table
                          WHERE
                            user_sender_id = ".$currentUserId." AND
                            id = $messageId AND
                            msg_status = ".MESSAGE_STATUS_OUTBOX;
                $result = Database::query($query);
            }
        } else {
            if (is_numeric($messageId) && !empty($messageId)) {
                $query = "UPDATE $table SET
                          msg_status = '".MESSAGE_STATUS_NEW."'
                          WHERE
                            user_receiver_id=".$currentUserId." AND
                            id = '".$messageId."'";
                Database::query($query);

                $query = "SELECT * FROM $table
                          WHERE
                            msg_status<> ".MESSAGE_STATUS_OUTBOX." AND
                            user_receiver_id = ".$currentUserId." AND
                            id = '".$messageId."'";
                $result = Database::query($query);
            }
        }
        $row = Database::fetch_array($result, 'ASSOC');

        if (empty($row)) {
            return $msg;
        }

        $user_sender_id = $row['user_sender_id'];
        $msg['id'] = $row['id'];
        $msg['status'] = $row['msg_status'];
        $msg['user_id'] = $user_sender_id;

        // get file attachments by message id
        $files_attachments = self::getAttachmentLinkList(
            $messageId,
            $source
        );
        $msg['title'] = Security::remove_XSS($row['title'], STUDENT, true);
        $msg['attachments'] = $files_attachments;

        $contentMsg = str_replace('</br>', '<br />', $row['content']);
        $content = Security::remove_XSS($contentMsg, STUDENT, true);
        $msg['content'] = $content;
        $fromUser = api_get_user_info($user_sender_id);

        if (!empty($user_sender_id) && !empty($fromUser)) {
            $msg['form_user'] = [
                'id' => $fromUser['id'],
                'name' => $fromUser['complete_name'],
                'username' => $fromUser['username'],
                'avatar' => $fromUser['avatar_small'],
                'email' => $fromUser['email'],
            ];
        }
        $msg['date'] = Display::dateToStringAgoAndLongDate($row['send_date']);

        if (!empty($row['user_receiver_id'])) {
            $receiverUserInfo = api_get_user_info($row['user_receiver_id']);
            $msg['receiver_user'] = [
                'id' => $receiverUserInfo['id'],
                'name' => $receiverUserInfo['complete_name'],
                'username' => $receiverUserInfo['username'],
                'avatar' => $receiverUserInfo['avatar'],
                'email' => $receiverUserInfo['email'],
            ];
        }

        $urlMessage = [];
        $social_link = '';
        if (isset($_GET['f']) && $_GET['f'] == 'social') {
            $social_link = 'f=social';
        }
        if ($source == 'outbox') {
            $urlMessage['return'] = 'outbox.php?'.$social_link;
        } else {
            $urlMessage['back'] = 'inbox.php?'.$social_link;
            $urlMessage['new_message'] = 'new_message.php?re_id='.$messageId.'&'.$social_link;
        }
        $urlMessage['delete'] = 'inbox.php?action=deleteone&id='.$messageId.'&'.$social_link;

        $msg['url'] = $urlMessage;

        return $msg;
    }

    /**
     * get user id by user email.
     *
     * @param string $user_email
     *
     * @return int user id
     */
    public static function get_user_id_by_email($user_email)
    {
        $table = Database::get_main_table(TABLE_MAIN_USER);
        $sql = 'SELECT user_id 
                FROM '.$table.'
                WHERE email="'.Database::escape_string($user_email).'";';
        $rs = Database::query($sql);
        $row = Database::fetch_array($rs, 'ASSOC');
        if (isset($row['user_id'])) {
            return $row['user_id'];
        }

        return null;
    }

    /**
     * Displays messages of a group with nested view.
     *
     * @param int $groupId
     *
     * @return string
     */
    public static function display_messages_for_group($groupId)
    {
        global $my_group_role;

        $rows = self::get_messages_by_group($groupId);
        $topics_per_page = 10;
        $html_messages = '';
        $query_vars = ['id' => $groupId, 'topics_page_nr' => 0];

        if (is_array($rows) && count($rows) > 0) {
            // prepare array for topics with its items
            $topics = [];
            $x = 0;
            foreach ($rows as $index => $value) {
                if (empty($value['parent_id'])) {
                    $topics[$value['id']] = $value;
                }
            }

            $new_topics = [];

            foreach ($topics as $id => $value) {
                $rows = null;
                $rows = self::get_messages_by_group_by_message($groupId, $value['id']);
                if (!empty($rows)) {
                    $count = count(self::calculate_children($rows, $value['id']));
                } else {
                    $count = 0;
                }
                $value['count'] = $count;
                $new_topics[$id] = $value;
            }

            $array_html = [];
            foreach ($new_topics as $index => $topic) {
                $html = '';
                // topics
                $user_sender_info = api_get_user_info($topic['user_sender_id']);
                $name = $user_sender_info['complete_name'];
                $html .= '<div class="groups-messages">';
                $html .= '<div class="row">';

                $items = $topic['count'];
                $reply_label = ($items == 1) ? get_lang('GroupReply') : get_lang('GroupReplies');
                $label = '<i class="fa fa-envelope"></i> '.$items.' '.$reply_label;
                $topic['title'] = trim($topic['title']);

                if (empty($topic['title'])) {
                    $topic['title'] = get_lang('Untitled');
                }

                $html .= '<div class="col-xs-8 col-md-10">';
                $html .= Display::tag(
                    'h4',
                    Display::url(
                        Security::remove_XSS($topic['title'], STUDENT, true),
                        api_get_path(WEB_CODE_PATH).'social/group_topics.php?id='.$groupId.'&topic_id='.$topic['id']
                    ),
                    ['class' => 'title']
                );
                $actions = '';
                if ($my_group_role == GROUP_USER_PERMISSION_ADMIN ||
                    $my_group_role == GROUP_USER_PERMISSION_MODERATOR
                ) {
                    $actions = '<br />'.Display::url(
                            get_lang('Delete'),
                            api_get_path(
                                WEB_CODE_PATH
                            ).'social/group_topics.php?action=delete&id='.$groupId.'&topic_id='.$topic['id'],
                            ['class' => 'btn btn-default']
                        );
                }

                $date = '';
                if ($topic['send_date'] != $topic['update_date']) {
                    if (!empty($topic['update_date'])) {
                        $date .= '<i class="fa fa-calendar"></i> '.get_lang(
                                'LastUpdate'
                            ).' '.Display::dateToStringAgoAndLongDate($topic['update_date']);
                    }
                } else {
                    $date .= '<i class="fa fa-calendar"></i> '.get_lang(
                            'Created'
                        ).' '.Display::dateToStringAgoAndLongDate($topic['send_date']);
                }
                $html .= '<div class="date">'.$label.' - '.$date.$actions.'</div>';
                $html .= '</div>';

                $image = $user_sender_info['avatar'];

                $user_info = '<div class="author text-center"><img class="img-fluid rounded-circle" src="'.$image.'" alt="'.$name.'"  width="64" height="64" title="'.$name.'" /></div>';
                $user_info .= '<div class="name text-center"><a href="'.api_get_path(
                        WEB_PATH
                    ).'main/social/profile.php?u='.$topic['user_sender_id'].'">'.$name.'&nbsp;</a></div>';

                $html .= '<div class="col-xs-4 col-md-2">';
                $html .= $user_info;
                $html .= '</div>';
                $html .= '</div>';
                $html .= '</div>';

                $array_html[] = [$html];
            }

            // grids for items and topics  with paginations
            $html_messages .= Display::return_sortable_grid(
                'topics',
                [],
                $array_html,
                [
                    'hide_navigation' => false,
                    'per_page' => $topics_per_page,
                ],
                $query_vars,
                false,
                [true, true, true, false],
                false
            );
        }

        return $html_messages;
    }

    /**
     * Displays messages of a group with nested view.
     *
     * @param $groupId
     * @param $topic_id
     * @param $is_member
     * @param $messageId
     *
     * @return string
     */
    public static function display_message_for_group($groupId, $topic_id, $is_member, $messageId)
    {
        global $my_group_role;
        $main_message = self::get_message_by_id($topic_id);
        if (empty($main_message)) {
            return false;
        }

        $webCodePath = api_get_path(WEB_CODE_PATH);
        $iconCalendar = Display::returnFontAwesomeIcon('calendar');

        $langEdit = get_lang('Edit');
        $langReply = get_lang('Reply');
        $langLastUpdated = get_lang('LastUpdated');
        $langCreated = get_lang('Created');

        $rows = self::get_messages_by_group_by_message($groupId, $topic_id);
        $rows = self::calculate_children($rows, $topic_id);
        $current_user_id = api_get_user_id();

        $items_per_page = 50;
        $query_vars = ['id' => $groupId, 'topic_id' => $topic_id, 'topics_page_nr' => 0];

        // Main message
        $links = '';
        $main_content = '';
        $html = '';
        $items_page_nr = null;

        $user_sender_info = api_get_user_info($main_message['user_sender_id']);
        $files_attachments = self::getAttachmentLinkList($main_message['id']);
        $name = $user_sender_info['complete_name'];

        $topic_page_nr = isset($_GET['topics_page_nr']) ? (int) $_GET['topics_page_nr'] : null;

        $links .= '<div class="pull-right">';
        $links .= '<div class="btn-group btn-group-sm">';

        if (($my_group_role == GROUP_USER_PERMISSION_ADMIN || $my_group_role == GROUP_USER_PERMISSION_MODERATOR) ||
            $main_message['user_sender_id'] == $current_user_id
        ) {
            $urlEdit = $webCodePath.'social/message_for_group_form.inc.php?'
                .http_build_query(
                    [
                        'user_friend' => $current_user_id,
                        'group_id' => $groupId,
                        'message_id' => $main_message['id'],
                        'action' => 'edit_message_group',
                        'anchor_topic' => 'topic_'.$main_message['id'],
                        'topics_page_nr' => $topic_page_nr,
                        'items_page_nr' => $items_page_nr,
                        'topic_id' => $main_message['id'],
                    ]
                );

            $links .= Display::toolbarButton(
                $langEdit,
                $urlEdit,
                'pencil',
                'default',
                ['class' => 'ajax', 'data-title' => $langEdit, 'data-size' => 'lg'],
                false
            );
        }

        $links .= self::getLikesButton($main_message['id'], $current_user_id, $groupId);

        $urlReply = $webCodePath.'social/message_for_group_form.inc.php?'
            .http_build_query(
                [
                    'user_friend' => $current_user_id,
                    'group_id' => $groupId,
                    'message_id' => $main_message['id'],
                    'action' => 'reply_message_group',
                    'anchor_topic' => 'topic_'.$main_message['id'],
                    'topics_page_nr' => $topic_page_nr,
                    'topic_id' => $main_message['id'],
                ]
            );

        $links .= Display::toolbarButton(
            $langReply,
            $urlReply,
            'commenting',
            'default',
            ['class' => 'ajax', 'data-title' => $langReply, 'data-size' => 'lg'],
            false
        );

        if (api_is_platform_admin()) {
            $links .= Display::toolbarButton(
                get_lang('Delete'),
                'group_topics.php?action=delete&id='.$groupId.'&topic_id='.$topic_id,
                'trash',
                'default',
                [],
                false
            );
        }

        $links .= '</div>';
        $links .= '</div>';

        $title = '<h4>'.Security::remove_XSS($main_message['title'], STUDENT, true).$links.'</h4>';

        $userPicture = $user_sender_info['avatar'];
        $main_content .= '<div class="row">';
        $main_content .= '<div class="col-md-2">';
        $main_content .= '<div class="avatar-author">';
        $main_content .= Display::img(
            $userPicture,
            $name,
            ['width' => '60px', 'class' => 'img-fluid img-circle'],
            false
        );
        $main_content .= '</div>';
        $main_content .= '</div>';

        $date = '';
        if ($main_message['send_date'] != $main_message['update_date']) {
            if (!empty($main_message['update_date'])) {
                $date = '<div class="date"> '
                    ."$iconCalendar $langLastUpdated "
                    .Display::dateToStringAgoAndLongDate($main_message['update_date'])
                    .'</div>';
            }
        } else {
            $date = '<div class="date"> '
                ."$iconCalendar $langCreated "
                .Display::dateToStringAgoAndLongDate($main_message['send_date'])
                .'</div>';
        }
        $attachment = '<div class="message-attach">'
            .(!empty($files_attachments) ? implode('<br />', $files_attachments) : '')
            .'</div>';
        $main_content .= '<div class="col-md-10">';
        $user_link = Display::url(
            $name,
            $webCodePath.'social/profile.php?u='.$main_message['user_sender_id']
        );
        $main_content .= '<div class="message-content"> ';
        $main_content .= '<div class="username">'.$user_link.'</div>';
        $main_content .= $date;
        $main_content .= '<div class="message">'.$main_message['content'].$attachment.'</div></div>';
        $main_content .= '</div>';
        $main_content .= '</div>';

        $html .= Display::div(
            Display::div(
                $title.$main_content,
                ['class' => 'message-topic']
            ),
            ['class' => 'sm-groups-message']
        );

        $topic_id = $main_message['id'];

        if (is_array($rows) && count($rows) > 0) {
            $topics = $rows;
            $array_html_items = [];

            foreach ($topics as $index => $topic) {
                if (empty($topic['id'])) {
                    continue;
                }
                $items_page_nr = isset($_GET['items_'.$topic['id'].'_page_nr'])
                    ? (int) $_GET['items_'.$topic['id'].'_page_nr']
                    : null;
                $links = '';
                $links .= '<div class="pull-right">';
                $html_items = '';
                $user_sender_info = api_get_user_info($topic['user_sender_id']);
                $files_attachments = self::getAttachmentLinkList($topic['id']);
                $name = $user_sender_info['complete_name'];

                $links .= '<div class="btn-group btn-group-sm">';
                if (
                    ($my_group_role == GROUP_USER_PERMISSION_ADMIN ||
                        $my_group_role == GROUP_USER_PERMISSION_MODERATOR
                    ) ||
                    $topic['user_sender_id'] == $current_user_id
                ) {
                    $links .= Display::toolbarButton(
                        $langEdit,
                        $webCodePath.'social/message_for_group_form.inc.php?'
                            .http_build_query(
                                [
                                    'user_friend' => $current_user_id,
                                    'group_id' => $groupId,
                                    'message_id' => $topic['id'],
                                    'action' => 'edit_message_group',
                                    'anchor_topic' => 'topic_'.$topic_id,
                                    'topics_page_nr' => $topic_page_nr,
                                    'items_page_nr' => $items_page_nr,
                                    'topic_id' => $topic_id,
                                ]
                            ),
                        'pencil',
                        'default',
                        ['class' => 'ajax', 'data-title' => $langEdit, 'data-size' => 'lg'],
                        false
                    );
                }

                $links .= self::getLikesButton($topic['id'], $current_user_id, $groupId);

                $links .= Display::toolbarButton(
                    $langReply,
                    $webCodePath.'social/message_for_group_form.inc.php?'
                        .http_build_query(
                            [
                                'user_friend' => $current_user_id,
                                'group_id' => $groupId,
                                'message_id' => $topic['id'],
                                'action' => 'reply_message_group',
                                'anchor_topic' => 'topic_'.$topic_id,
                                'topics_page_nr' => $topic_page_nr,
                                'items_page_nr' => $items_page_nr,
                                'topic_id' => $topic_id,
                            ]
                        ),
                    'commenting',
                    'default',
                    ['class' => 'ajax', 'data-title' => $langReply, 'data-size' => 'lg'],
                    false
                );
                $links .= '</div>';
                $links .= '</div>';

                $userPicture = $user_sender_info['avatar'];
                $user_link = Display::url(
                    $name,
                    $webCodePath.'social/profile.php?u='.$topic['user_sender_id']
                );
                $html_items .= '<div class="row">';
                $html_items .= '<div class="col-md-2">';
                $html_items .= '<div class="avatar-author">';
                $html_items .= Display::img(
                    $userPicture,
                    $name,
                    ['width' => '60px', 'class' => 'img-fluid img-circle'],
                    false
                );
                $html_items .= '</div>';
                $html_items .= '</div>';

                $date = '';
                if ($topic['send_date'] != $topic['update_date']) {
                    if (!empty($topic['update_date'])) {
                        $date = '<div class="date"> '
                            ."$iconCalendar $langLastUpdated "
                            .Display::dateToStringAgoAndLongDate($topic['update_date'])
                            .'</div>';
                    }
                } else {
                    $date = '<div class="date"> '
                        ."$iconCalendar $langCreated "
                        .Display::dateToStringAgoAndLongDate($topic['send_date'])
                        .'</div>';
                }
                $attachment = '<div class="message-attach">'
                    .(!empty($files_attachments) ? implode('<br />', $files_attachments) : '')
                    .'</div>';
                $html_items .= '<div class="col-md-10">'
                    .'<div class="message-content">'
                    .$links
                    .'<div class="username">'.$user_link.'</div>'
                    .$date
                    .'<div class="message">'
                    .Security::remove_XSS($topic['content'], STUDENT, true)
                    .'</div>'.$attachment.'</div>'
                    .'</div>'
                    .'</div>';

                $base_padding = 20;

                if ($topic['indent_cnt'] == 0) {
                    $indent = $base_padding;
                } else {
                    $indent = (int) $topic['indent_cnt'] * $base_padding + $base_padding;
                }

                $html_items = Display::div($html_items, ['class' => 'message-post', 'id' => 'msg_'.$topic['id']]);
                $html_items = Display::div($html_items, ['class' => '', 'style' => 'margin-left:'.$indent.'px']);
                $array_html_items[] = [$html_items];
            }

            // grids for items with paginations
            $options = ['hide_navigation' => false, 'per_page' => $items_per_page];
            $visibility = [true, true, true, false];

            $style_class = [
                'item' => ['class' => 'user-post'],
                'main' => ['class' => 'user-list'],
            ];
            if (!empty($array_html_items)) {
                $html .= Display::return_sortable_grid(
                    'items_'.$topic['id'],
                    [],
                    $array_html_items,
                    $options,
                    $query_vars,
                    null,
                    $visibility,
                    false,
                    $style_class
                );
            }
        }

        return $html;
    }

    /**
     * Add children to messages by id is used for nested view messages.
     *
     * @param array $rows rows of messages
     *
     * @return array $first_seed new list adding the item children
     */
    public static function calculate_children($rows, $first_seed)
    {
        $rows_with_children = [];
        foreach ($rows as $row) {
            $rows_with_children[$row["id"]] = $row;
            $rows_with_children[$row["parent_id"]]["children"][] = $row["id"];
        }
        $rows = $rows_with_children;
        $sorted_rows = [0 => []];
        self::message_recursive_sort($rows, $sorted_rows, $first_seed);
        unset($sorted_rows[0]);

        return $sorted_rows;
    }

    /**
     * Sort recursively the messages, is used for for nested view messages.
     *
     * @param array  original rows of messages
     * @param array  list recursive of messages
     * @param int   seed for calculate the indent
     * @param int   indent for nested view
     */
    public static function message_recursive_sort(
        $rows,
        &$messages,
        $seed = 0,
        $indent = 0
    ) {
        if ($seed > 0 && isset($rows[$seed]["id"])) {
            $messages[$rows[$seed]["id"]] = $rows[$seed];
            $messages[$rows[$seed]["id"]]["indent_cnt"] = $indent;
            $indent++;
        }

        if (isset($rows[$seed]["children"])) {
            foreach ($rows[$seed]["children"] as $child) {
                self::message_recursive_sort($rows, $messages, $child, $indent);
            }
        }
    }

    /**
     * @param int $messageId
     *
     * @return array
     */
    public static function getAttachmentList($messageId)
    {
        $table = Database::get_main_table(TABLE_MESSAGE_ATTACHMENT);
        $messageId = (int) $messageId;

        if (empty($messageId)) {
            return [];
        }

        $messageInfo = self::get_message_by_id($messageId);

        if (empty($messageInfo)) {
            return [];
        }

        $attachmentDir = UserManager::getUserPathById($messageInfo['user_receiver_id'], 'system');
        $attachmentDir .= 'message_attachments/';

        $sql = "SELECT * FROM $table
                WHERE message_id = '$messageId'";
        $result = Database::query($sql);
        $files = [];
        while ($row = Database::fetch_array($result, 'ASSOC')) {
            $row['file_source'] = '';
            if (file_exists($attachmentDir.$row['path'])) {
                $row['file_source'] = $attachmentDir.$row['path'];
            }
            $files[] = $row;
        }

        return $files;
    }

    /**
     * Get array of links (download) for message attachment files.
     *
     * @param int    $messageId
     * @param string $type      message list (inbox/outbox)
     *
     * @return array
     */
    public static function getAttachmentLinkList($messageId, $type = '')
    {
        $files = self::getAttachmentList($messageId);
        // get file attachments by message id
        $list = [];
        $totalSize = 0;

        if ($files) {
            $archiveURL = api_get_path(WEB_CODE_PATH).'messages/download.php?type='.$type.'&file=';
            $countFiles = count($files);

            foreach ($files as $row_file) {
                $archiveFile = $row_file['path'];
                $filename = $row_file['filename'];
                $totalSize += $row_file['size'];
                $size = format_file_size($row_file['size']);
                $comment = Security::remove_XSS($row_file['comment']);
                $filename = Security::remove_XSS($filename);
                $type = 'file';

                if ($row_file['comment'] == 'audio_message') {
                    $type = 'audio';
                }

                $listTemp = [
                    'filename' => $filename,
                    'size' => $size,
                    'comment' => $comment,
                    'url' => $archiveURL.$archiveFile,
                    'type' => $type,
                ];

                $list[] = $listTemp;
            }

            return [
                'quantity' => $countFiles,
                'total_size' => format_file_size($totalSize),
                'files' => $list,
            ];
        } else {
            return [];
        }
    }

    /**
     * Get message list by id.
     *
     * @param int $messageId
     *
     * @return array
     */
    public static function get_message_by_id($messageId)
    {
        $table = Database::get_main_table(TABLE_MESSAGE);
        $messageId = (int) $messageId;
        $sql = "SELECT * FROM $table
                WHERE 
                    id = '$messageId' AND 
                    msg_status <> '".MESSAGE_STATUS_DELETED."' ";
        $res = Database::query($sql);
        $item = [];
        if (Database::num_rows($res) > 0) {
            $item = Database::fetch_array($res, 'ASSOC');
        }

        return $item;
    }

    /**
     * @return string
     */
    public static function generate_message_form()
    {
        $form = new FormValidator('send_message');
        $form->addText(
            'subject',
            get_lang('Subject'),
            false,
            ['id' => 'subject_id']
        );
        $form->addTextarea(
            'content',
            get_lang('Message'),
            ['id' => 'content_id', 'rows' => '5']
        );

        return $form->returnForm();
    }

    /**
     * @return string
     */
    public static function generate_invitation_form()
    {
        $form = new FormValidator('send_invitation');
        $form->addTextarea(
            'content',
            get_lang('AddPersonalMessage'),
            ['id' => 'content_invitation_id', 'rows' => 5]
        );

        return $form->returnForm();
    }

    /**
     * @param string $keyword
     *
     * @return string
     */
    public static function inbox_display($keyword = '')
    {
        $success = get_lang('SelectedMessagesDeleted');
        $success_read = get_lang('SelectedMessagesRead');
        $success_unread = get_lang('SelectedMessagesUnRead');
        $html = '';

        Session::write('message_search_keyword', $keyword);

        if (isset($_REQUEST['action'])) {
            switch ($_REQUEST['action']) {
                case 'mark_as_unread':
                    if (is_array($_POST['id'])) {
                        foreach ($_POST['id'] as $index => $message_id) {
                            self::update_message_status(
                                api_get_user_id(),
                                $message_id,
                                MESSAGE_STATUS_UNREAD
                            );
                        }
                    }
                    $html .= Display::return_message(
                        api_xml_http_response_encode($success_unread),
                        'normal',
                        false
                    );
                    break;
                case 'mark_as_read':
                    if (is_array($_POST['id'])) {
                        foreach ($_POST['id'] as $index => $message_id) {
                            self::update_message_status(
                                api_get_user_id(),
                                $message_id,
                                MESSAGE_STATUS_NEW
                            );
                        }
                    }
                    $html .= Display::return_message(
                        api_xml_http_response_encode($success_read),
                        'normal',
                        false
                    );
                    break;
                case 'delete':
                    foreach ($_POST['id'] as $index => $message_id) {
                        self::delete_message_by_user_receiver(api_get_user_id(), $message_id);
                    }
                    $html .= Display::return_message(
                        api_xml_http_response_encode($success),
                        'normal',
                        false
                    );
                    break;
                case 'deleteone':
                    self::delete_message_by_user_receiver(api_get_user_id(), $_GET['id']);
                    $html .= Display::return_message(
                        api_xml_http_response_encode($success),
                        'confirmation',
                        false
                    );
                    break;
            }
        }

        // display sortable table with messages of the current user
        $table = new SortableTable(
            'message_inbox',
            ['MessageManager', 'getNumberOfMessages'],
            ['MessageManager', 'get_message_data'],
            2,
            20,
            'DESC',
            null,
            'table-custom'
        );
        $table->set_header(0, '', false, ['style' => 'width:15px;']);
        $table->set_header(1, get_lang('Messages'), false);
        $table->set_header(2, get_lang('Subject'), false);
        $table->set_header(3, get_lang('Date'), true, ['style' => 'width:180px;']);
        $table->set_header(4, get_lang('Modify'), false, ['style' => 'width:120px;']);

        if (isset($_REQUEST['f']) && $_REQUEST['f'] == 'social') {
            $parameters['f'] = 'social';
            $table->set_additional_parameters($parameters);
        }
        $table->set_form_actions(
            [
                'delete' => get_lang('DeleteSelectedMessages'),
                'mark_as_unread' => get_lang('MailMarkSelectedAsUnread'),
                'mark_as_read' => get_lang('MailMarkSelectedAsRead'),
            ]
        );
        $html .= $table->return_table();

        Session::erase('message_search_keyword');

        return $html;
    }

    /**
     * @param string $keyword
     *
     * @return string
     */
    public static function outbox_display($keyword = '')
    {
        Session::write('message_sent_search_keyword', $keyword);
        $success = get_lang('SelectedMessagesDeleted').'&nbsp</b><br />
                    <a href="outbox.php">'.get_lang('BackToOutbox').'</a>';

        $html = '';
        if (isset($_REQUEST['action'])) {
            switch ($_REQUEST['action']) {
                case 'delete':
                    $number_of_selected_messages = count($_POST['id']);
                    if ($number_of_selected_messages != 0) {
                        foreach ($_POST['id'] as $index => $message_id) {
                            self::delete_message_by_user_receiver(
                                api_get_user_id(),
                                $message_id
                            );
                        }
                    }
                    $html .= Display::return_message(api_xml_http_response_encode($success), 'normal', false);
                    break;
                case 'deleteone':
                    self::delete_message_by_user_receiver(api_get_user_id(), $_GET['id']);
                    $html .= Display::return_message(api_xml_http_response_encode($success), 'normal', false);
                    $html .= '<br/>';
                    break;
            }
        }

        // display sortable table with messages of the current user
        $table = new SortableTable(
            'message_outbox',
            ['MessageManager', 'getNumberOfMessagesSent'],
            ['MessageManager', 'get_message_data_sent'],
            2,
            20,
            'DESC'
        );

        $table->set_header(0, '', false, ['style' => 'width:15px;']);
        $table->set_header(1, get_lang('Messages'), false);
        $table->set_header(2, get_lang('Subject'), false);
        $table->set_header(3, get_lang('Date'), true, ['style' => 'width:180px;']);
        $table->set_header(4, get_lang('Modify'), false, ['style' => 'width:70px;']);

        $table->set_form_actions(['delete' => get_lang('DeleteSelectedMessages')]);
        $html .= $table->return_table();

        Session::erase('message_sent_search_keyword');

        return $html;
    }

    /**
     * Get the count of the last received messages for a user.
     *
     * @param int $userId The user id
     * @param int $lastId The id of the last received message
     *
     * @return int The count of new messages
     */
    public static function countMessagesFromLastReceivedMessage($userId, $lastId = 0)
    {
        $userId = intval($userId);
        $lastId = intval($lastId);

        if (empty($userId)) {
            return 0;
        }

        $messagesTable = Database::get_main_table(TABLE_MESSAGE);

        $conditions = [
            'where' => [
                'user_receiver_id = ?' => $userId,
                'AND msg_status = ?' => MESSAGE_STATUS_UNREAD,
                'AND id > ?' => $lastId,
            ],
        ];

        $result = Database::select('COUNT(1) AS qty', $messagesTable, $conditions);

        if (!empty($result)) {
            $row = current($result);

            return $row['qty'];
        }

        return 0;
    }

    /**
     * Get the data of the last received messages for a user.
     *
     * @deprecated 2.0 Use Chamilo\CoreBundle\Repository\MessageRepository::getFromLastOneReceived
     *
     * @param int $userId The user id
     * @param int $lastId The id of the last received message
     *
     * @return array
     */
    public static function getMessagesFromLastReceivedMessage($userId, $lastId = 0)
    {
        $userId = (int) $userId;
        $lastId = (int) $lastId;

        if (empty($userId)) {
            return [];
        }

        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userTable = Database::get_main_table(TABLE_MAIN_USER);

        $sql = "SELECT m.*, u.user_id, u.lastname, u.firstname
                FROM $messagesTable as m
                INNER JOIN $userTable as u
                ON m.user_sender_id = u.user_id
                WHERE
                    m.user_receiver_id = $userId AND
                    m.msg_status = ".MESSAGE_STATUS_UNREAD."
                    AND m.id > $lastId
                ORDER BY m.send_date DESC";

        $result = Database::query($sql);

        $messages = [];
        if ($result !== false) {
            while ($row = Database::fetch_assoc($result)) {
                $messages[] = $row;
            }
        }

        return $messages;
    }

    /**
     * Check whether a message has attachments.
     *
     * @param int $messageId The message id
     *
     * @return bool Whether the message has attachments return true. Otherwise return false
     */
    public static function hasAttachments($messageId)
    {
        $messageId = (int) $messageId;

        if (empty($messageId)) {
            return false;
        }

        $messageAttachmentTable = Database::get_main_table(TABLE_MESSAGE_ATTACHMENT);

        $conditions = [
            'where' => [
                'message_id = ?' => $messageId,
            ],
        ];

        $result = Database::select(
            'COUNT(1) AS qty',
            $messageAttachmentTable,
            $conditions,
            'first'
        );

        if (!empty($result)) {
            if ($result['qty'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $messageId
     *
     * @return array|bool
     */
    public static function getAttachment($messageId)
    {
        $messageId = (int) $messageId;

        if (empty($messageId)) {
            return false;
        }

        $table = Database::get_main_table(TABLE_MESSAGE_ATTACHMENT);

        $conditions = [
            'where' => [
                'id = ?' => $messageId,
            ],
        ];

        $result = Database::select(
            '*',
            $table,
            $conditions,
            'first'
        );

        if (!empty($result)) {
            return $result;
        }

        return false;
    }

    /**
     * @param string $url
     *
     * @return FormValidator
     */
    public static function getSearchForm($url)
    {
        $form = new FormValidator(
            'search',
            'post',
            $url,
            null,
            [],
            FormValidator::LAYOUT_INLINE
        );

        $form->addElement(
            'text',
            'keyword',
            false,
            [
                'aria-label' => get_lang('Search'),
            ]
        );
        $form->addButtonSearch(get_lang('Search'));

        return $form;
    }

    /**
     * Send a notification to all admins when a new user is registered.
     *
     * @param User $user
     */
    public static function sendNotificationByRegisteredUser(User $user)
    {
        $tplMailBody = new Template(
            null,
            false,
            false,
            false,
            false,
            false,
            false
        );
        $tplMailBody->assign('user', $user);
        $tplMailBody->assign('is_western_name_order', api_is_western_name_order());
        $tplMailBody->assign(
            'manageUrl',
            api_get_path(WEB_CODE_PATH).'admin/user_edit.php?user_id='.$user->getId()
        );

        $layoutContent = $tplMailBody->get_template('mail/new_user_mail_to_admin.tpl');

        $emailsubject = '['.get_lang('UserRegistered').'] '.$user->getUsername();
        $emailbody = $tplMailBody->fetch($layoutContent);

        $admins = UserManager::get_all_administrators();

        foreach ($admins as $admin_info) {
            self::send_message(
                $admin_info['user_id'],
                $emailsubject,
                $emailbody,
                [],
                [],
                null,
                null,
                null,
                null,
                $user->getId()
            );
        }
    }

    /**
     * Get the error log from failed mailing
     * This assumes a complex setup where you have a cron script regularly copying the mail queue log
     * into app/cache/mail/mailq.
     * This can be done with a cron command like (check the location of your mail log file first):.
     *
     * @example 0,30 * * * * root cp /var/log/exim4/mainlog /var/www/chamilo/app/cache/mail/mailq
     *
     * @return array|bool
     */
    public static function failedSentMailErrors()
    {
        $base = api_get_path(SYS_ARCHIVE_PATH).'mail/';
        $mailq = $base.'mailq';

        if (!file_exists($mailq) || !is_readable($mailq)) {
            return false;
        }

        $file = fopen($mailq, 'r');
        $i = 1;
        while (!feof($file)) {
            $line = fgets($file);
            // $line = trim($line);

            if (trim($line) == '') {
                continue;
            }

            // Get the mail code, something like 1WBumL-0002xg-FF
            if (preg_match('/(.*)\s((.*)-(.*)-(.*))\s<(.*)$/', $line, $codeMatches)) {
                $mail_queue[$i]['code'] = $codeMatches[2];
            }

            $fullMail = $base.$mail_queue[$i]['code'];
            $mailFile = fopen($fullMail, 'r');

            // Get the reason of mail fail
            $iX = 1;
            while (!feof($mailFile)) {
                $mailLine = fgets($mailFile);
                //if ($iX == 4 && preg_match('/(.*):\s(.*)$/', $mailLine, $matches)) {
                if ($iX == 2 &&
                    preg_match('/(.*)(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s(.*)/', $mailLine, $detailsMatches)
                ) {
                    $mail_queue[$i]['reason'] = $detailsMatches[3];
                }
                $iX++;
            }

            fclose($mailFile);

            // Get the time of mail fail
            if (preg_match('/^\s?(\d+)(\D+)\s+(.*)$/', $line, $timeMatches)) {
                $mail_queue[$i]['time'] = $timeMatches[1].$timeMatches[2];
            } elseif (preg_match('/^(\s+)((.*)@(.*))\s+(.*)$/', $line, $emailMatches)) {
                $mail_queue[$i]['mail'] = $emailMatches[2];
                $i++;
            }
        }

        fclose($file);

        return array_reverse($mail_queue);
    }

    /**
     * @param int $userId
     *
     * @return array
     */
    public static function getUsersThatHadConversationWithUser($userId)
    {
        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userId = (int) $userId;

        $sql = "SELECT DISTINCT
                    user_sender_id
                FROM $messagesTable
                WHERE
                    user_receiver_id = ".$userId;
        $result = Database::query($sql);
        $users = Database::store_result($result);
        $userList = [];
        foreach ($users as $userData) {
            $userId = $userData['user_sender_id'];
            if (empty($userId)) {
                continue;
            }
            $userInfo = api_get_user_info($userId);
            if ($userInfo) {
                $userList[$userId] = $userInfo;
            }
        }

        return $userList;
    }

    /**
     * @param int $userId
     * @param int $otherUserId
     *
     * @return array
     */
    public static function getAllMessagesBetweenStudents($userId, $otherUserId)
    {
        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userId = (int) $userId;
        $otherUserId = (int) $otherUserId;

        if (empty($otherUserId) || empty($userId)) {
            return [];
        }

        $sql = "SELECT DISTINCT * 
                FROM $messagesTable
                WHERE
                    (user_receiver_id = $userId AND user_sender_id = $otherUserId) OR
                    (user_receiver_id = $otherUserId AND user_sender_id = $userId)
                ORDER BY send_date DESC
            ";
        $result = Database::query($sql);
        $messages = Database::store_result($result);
        $list = [];
        foreach ($messages as $message) {
            $list[] = $message;
        }

        return $list;
    }

    /**
     * @param string $subject
     * @param string $message
     * @param array  $courseInfo
     * @param int    $sessionId
     *
     * @return bool
     */
    public static function sendMessageToAllUsersInCourse($subject, $message, $courseInfo, $sessionId = 0)
    {
        if (empty($courseInfo)) {
            return false;
        }

        $senderId = api_get_user_id();
        if (empty($senderId)) {
            return false;
        }
        if (empty($sessionId)) {
            // Course students and teachers
            $users = CourseManager::get_user_list_from_course_code($courseInfo['code']);
        } else {
            // Course-session students and course session coaches
            $users = CourseManager::get_user_list_from_course_code($courseInfo['code'], $sessionId);
        }

        if (empty($users)) {
            return false;
        }

        foreach ($users as $userInfo) {
            self::send_message_simple(
                $userInfo['user_id'],
                $subject,
                $message,
                $senderId,
                false,
                false,
                [],
                false
            );
        }
    }

    /**
     * Clean audio messages already added in the message tool.
     */
    public static function cleanAudioMessage()
    {
        $audioId = Session::read('current_audio_id');
        if (!empty($audioId)) {
            api_remove_uploaded_file_by_id('audio_message', api_get_user_id(), $audioId);
            Session::erase('current_audio_id');
        }
    }

    /**
     * @param int    $senderId
     * @param string $subject
     * @param string $message
     */
    public static function sendMessageToAllAdminUsers(
        $senderId,
        $subject,
        $message
    ) {
        $admins = UserManager::get_all_administrators();
        foreach ($admins as $admin) {
            self::send_message_simple($admin['user_id'], $subject, $message, $senderId);
        }
    }

    /**
     * @param int $messageId
     * @param int $userId
     *
     * @return array
     */
    public static function countLikesAndDislikes($messageId, $userId)
    {
        if (!api_get_configuration_value('social_enable_messages_feedback')) {
            return [];
        }

        $messageId = (int) $messageId;
        $userId = (int) $userId;

        $em = Database::getManager();
        $query = $em
            ->createQuery('
                SELECT SUM(l.liked) AS likes, SUM(l.disliked) AS dislikes FROM ChamiloCoreBundle:MessageFeedback l
                WHERE l.message = :message
            ')
            ->setParameters(['message' => $messageId]);

        try {
            $counts = $query->getSingleResult();
        } catch (Exception $e) {
            $counts = ['likes' => 0, 'dislikes' => 0];
        }

        $userLike = $em
            ->getRepository('ChamiloCoreBundle:MessageFeedback')
            ->findOneBy(['message' => $messageId, 'user' => $userId]);

        return [
            'likes' => (int) $counts['likes'],
            'dislikes' => (int) $counts['dislikes'],
            'user_liked' => $userLike ? $userLike->isLiked() : false,
            'user_disliked' => $userLike ? $userLike->isDisliked() : false,
        ];
    }

    /**
     * @param int $messageId
     * @param int $userId
     * @param int $groupId   Optional.
     *
     * @return string
     */
    public static function getLikesButton($messageId, $userId, $groupId = 0)
    {
        if (!api_get_configuration_value('social_enable_messages_feedback')) {
            return '';
        }

        $countLikes = self::countLikesAndDislikes($messageId, $userId);

        $btnLike = Display::button(
            'like',
            Display::returnFontAwesomeIcon('thumbs-up', '', true)
                .PHP_EOL.'<span>'.$countLikes['likes'].'</span>',
            [
                'title' => get_lang('VoteLike'),
                'class' => 'btn btn-default social-like '.($countLikes['user_liked'] ? 'disabled' : ''),
                'data-status' => 'like',
                'data-message' => $messageId,
                'data-group' => $groupId,
            ]
        );
        $btnDislike = Display::button(
            'like',
            Display::returnFontAwesomeIcon('thumbs-down', '', true)
            .PHP_EOL.'<span>'.$countLikes['dislikes'].'</span>',
            [
                'title' => get_lang('VoteDislike'),
                'class' => 'btn btn-default social-like '.($countLikes['user_disliked'] ? 'disabled' : ''),
                'data-status' => 'dislike',
                'data-message' => $messageId,
                'data-group' => $groupId,
            ]
        );

        return $btnLike.PHP_EOL.$btnDislike;
    }

    /**
     * Execute the SQL necessary to know the number of messages in the database.
     *
     * @param int $userId The user for which we need the unread messages count
     *
     * @return int The number of unread messages in the database for the given user
     */
    public static function getCountNewMessagesFromDB($userId)
    {
        $userId = (int) $userId;

        if (empty($userId)) {
            return 0;
        }

        $table = Database::get_main_table(TABLE_MESSAGE);
        $sql = "SELECT COUNT(id) as count 
                FROM $table
                WHERE
                    user_receiver_id=".$userId." AND
                    msg_status = ".MESSAGE_STATUS_UNREAD;
        $result = Database::query($sql);
        $row = Database::fetch_assoc($result);

        return $row['count'];
    }
}
