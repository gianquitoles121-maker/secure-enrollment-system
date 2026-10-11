<?php

session_start();

require_once "../config/database.php";
require_once "../config/csrf.php";


/*
|--------------------------------------------------------------------------
| STUDENT ACCESS PROTECTION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "student"
) {
    header("Location: ../login.php");
    exit;
}


$user_id = (int) $_SESSION["user_id"];

$message = "";
$message_type = "";


/*
|--------------------------------------------------------------------------
| INPUT LIMITS
|--------------------------------------------------------------------------
*/

$MAX_SUBJECT_LENGTH = 150;
$MAX_MESSAGE_LENGTH = 5000;


/*
|--------------------------------------------------------------------------
| CREATE NEW TICKET
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "create_ticket"
) {

    verify_csrf_token();

    $subject = trim($_POST["subject"] ?? "");
    $support_message = trim($_POST["message"] ?? "");


    if (
        $subject === "" ||
        $support_message === ""
    ) {

        $message = "Please complete all fields.";
        $message_type = "error";

    } elseif (
        strlen($subject) > $MAX_SUBJECT_LENGTH
    ) {

        $message =
            "Subject must not exceed 150 characters.";

        $message_type = "error";

    } elseif (
        strlen($support_message) > $MAX_MESSAGE_LENGTH
    ) {

        $message =
            "Message must not exceed 5,000 characters.";

        $message_type = "error";

    } else {

        $transaction_started = false;

        try {

            $conn->begin_transaction();
            $transaction_started = true;


            /*
            |--------------------------------------------------------------------------
            | CREATE TICKET
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare(
                "INSERT INTO support_tickets
                (
                    user_id,
                    subject,
                    status
                )
                VALUES (?, ?, 'open')"
            );

            $stmt->bind_param(
                "is",
                $user_id,
                $subject
            );

            $stmt->execute();

            $ticket_id = (int) $conn->insert_id;

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | CREATE FIRST MESSAGE
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare(
                "INSERT INTO support_messages
                (
                    ticket_id,
                    user_id,
                    message
                )
                VALUES (?, ?, ?)"
            );

            $stmt->bind_param(
                "iis",
                $ticket_id,
                $user_id,
                $support_message
            );

            $stmt->execute();

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $conn->commit();
            $transaction_started = false;


            /*
            |--------------------------------------------------------------------------
            | POST / REDIRECT / GET
            |--------------------------------------------------------------------------
            */

            header(
                "Location: support.php?ticket=" .
                $ticket_id
            );

            exit;


        } catch (Throwable $e) {

            if ($transaction_started) {
                $conn->rollback();
            }

            $message =
                "Unable to create your support request. Please try again.";

            $message_type = "error";
        }
    }
}


/*
|--------------------------------------------------------------------------
| REPLY TO TICKET
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "reply_ticket"
) {

    verify_csrf_token();


    $ticket_id =
        filter_input(
            INPUT_POST,
            "ticket_id",
            FILTER_VALIDATE_INT
        );

    $reply_message =
        trim($_POST["reply_message"] ?? "");


    if (!$ticket_id || $ticket_id <= 0) {

        $message =
            "Invalid support ticket.";

        $message_type = "error";

    } elseif ($reply_message === "") {

        $message =
            "Please enter a message.";

        $message_type = "error";

    } elseif (
        strlen($reply_message) > $MAX_MESSAGE_LENGTH
    ) {

        $message =
            "Message must not exceed 5,000 characters.";

        $message_type = "error";

    } else {

        $transaction_started = false;

        try {

            $conn->begin_transaction();
            $transaction_started = true;


            /*
            |--------------------------------------------------------------------------
            | LOCK AND VERIFY TICKET OWNERSHIP
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare(
                "SELECT
                    id,
                    status
                 FROM support_tickets
                 WHERE id = ?
                 AND user_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->bind_param(
                "ii",
                $ticket_id,
                $user_id
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $ticket = $result->fetch_assoc();

            $stmt->close();


            if (!$ticket) {

                throw new Exception(
                    "Support ticket not found."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | RESOLVED TICKET CANNOT RECEIVE REPLIES
            |--------------------------------------------------------------------------
            */

            if ($ticket["status"] === "resolved") {

                throw new Exception(
                    "This support ticket has already been resolved."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | INSERT REPLY
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare(
                "INSERT INTO support_messages
                (
                    ticket_id,
                    user_id,
                    message
                )
                VALUES (?, ?, ?)"
            );

            $stmt->bind_param(
                "iis",
                $ticket_id,
                $user_id,
                $reply_message
            );

            $stmt->execute();

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | REOPEN TICKET AS OPEN
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare(
                "UPDATE support_tickets
                 SET status = 'open'
                 WHERE id = ?
                 AND user_id = ?
                 AND status != 'resolved'"
            );

            $stmt->bind_param(
                "ii",
                $ticket_id,
                $user_id
            );

            $stmt->execute();

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $conn->commit();
            $transaction_started = false;


            /*
            |--------------------------------------------------------------------------
            | POST / REDIRECT / GET
            |--------------------------------------------------------------------------
            */

            header(
                "Location: support.php?ticket=" .
                $ticket_id
            );

            exit;


        } catch (Throwable $e) {

            if ($transaction_started) {
                $conn->rollback();
            }

            $message =
                $e->getMessage();

            $message_type = "error";
        }
    }
}


/*
|--------------------------------------------------------------------------
| GET STUDENT TICKETS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        id,
        subject,
        status,
        created_at,
        updated_at
     FROM support_tickets
     WHERE user_id = ?
     ORDER BY updated_at DESC"
);

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$tickets_result = $stmt->get_result();

$stmt->close();


/*
|--------------------------------------------------------------------------
| SELECT TICKET
|--------------------------------------------------------------------------
*/

$selected_ticket = null;
$selected_messages = null;


if (isset($_GET["ticket"])) {

    $ticket_id =
        filter_input(
            INPUT_GET,
            "ticket",
            FILTER_VALIDATE_INT
        );


    if ($ticket_id && $ticket_id > 0) {

        /*
        |--------------------------------------------------------------------------
        | VERIFY TICKET OWNERSHIP
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare(
            "SELECT
                id,
                subject,
                status,
                created_at,
                updated_at
             FROM support_tickets
             WHERE id = ?
             AND user_id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            "ii",
            $ticket_id,
            $user_id
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $selected_ticket =
            $result->fetch_assoc();

        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | GET MESSAGES
        |--------------------------------------------------------------------------
        */

        if ($selected_ticket) {

            $stmt = $conn->prepare(
                "SELECT
                    sm.id,
                    sm.user_id,
                    sm.message,
                    sm.created_at,
                    u.username,
                    u.role
                 FROM support_messages sm
                 INNER JOIN users u
                    ON sm.user_id = u.id
                 WHERE sm.ticket_id = ?
                 ORDER BY sm.created_at ASC, sm.id ASC"
            );

            $stmt->bind_param(
                "i",
                $ticket_id
            );

            $stmt->execute();

            $selected_messages =
                $stmt->get_result();

            $stmt->close();
        }
    }
}

?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Contact Support | Secure Enrollment
</title>


<style>

* {
    box-sizing: border-box;
}


body {
    margin: 0;

    font-family:
        Arial,
        sans-serif;

    background:
        linear-gradient(
            135deg,
            #0f172a,
            #1d4ed8
        );

    min-height: 100vh;

    padding: 25px;
}


.container {
    width: 100%;

    max-width: 1100px;

    margin: 0 auto;
}


.header {
    background: white;

    padding: 22px 25px;

    border-radius: 14px;

    margin-bottom: 20px;

    box-shadow:
        0 10px 30px
        rgba(0, 0, 0, 0.20);
}


.header h1 {
    margin: 0;

    color: #1d4ed8;
}


.header p {
    margin: 6px 0 0;

    color: #64748b;
}


.back-link {
    display: inline-block;

    margin-top: 12px;

    color: #2563eb;

    text-decoration: none;

    font-weight: bold;
}


.layout {
    display: grid;

    grid-template-columns:
        300px 1fr;

    gap: 20px;
}


.card {
    background: white;

    border-radius: 14px;

    padding: 20px;

    box-shadow:
        0 10px 30px
        rgba(0, 0, 0, 0.20);
}


.card h2 {
    margin-top: 0;

    color: #1e293b;

    font-size: 20px;
}


.new-ticket {
    margin-bottom: 20px;
}


label {
    display: block;

    margin: 12px 0 6px;

    font-weight: bold;

    color: #334155;
}


input,
textarea {
    width: 100%;

    padding: 11px;

    border:
        1px solid #cbd5e1;

    border-radius: 8px;

    font-family:
        Arial,
        sans-serif;

    font-size: 14px;
}


textarea {
    resize: vertical;

    min-height: 110px;
}


input:focus,
textarea:focus {
    outline: 2px solid #93c5fd;

    border-color: #2563eb;
}


button {
    width: 100%;

    margin-top: 15px;

    padding: 11px;

    border: none;

    border-radius: 8px;

    background: #2563eb;

    color: white;

    font-weight: bold;

    cursor: pointer;
}


button:hover {
    background: #1d4ed8;
}


.ticket {
    display: block;

    padding: 13px;

    margin-bottom: 10px;

    border:
        1px solid #e2e8f0;

    border-radius: 9px;

    text-decoration: none;

    color: #1e293b;

    background: #f8fafc;
}


.ticket:hover {
    background: #eff6ff;

    border-color: #93c5fd;
}


.ticket.active {
    background: #eff6ff;

    border-color: #2563eb;
}


.ticket-subject {
    font-weight: bold;

    margin-bottom: 7px;
}


.ticket-date {
    font-size: 12px;

    color: #64748b;
}


.status {
    display: inline-block;

    padding: 4px 8px;

    border-radius: 999px;

    font-size: 12px;

    font-weight: bold;

    margin-top: 7px;
}


.status.open {
    background: #fef3c7;

    color: #92400e;
}


.status.in_progress {
    background: #dbeafe;

    color: #1e40af;
}


.status.resolved {
    background: #dcfce7;

    color: #166534;
}


.message {
    padding: 12px;

    border-radius: 8px;

    margin-bottom: 15px;

    line-height: 1.5;
}


.message.error {
    background: #fee2e2;

    color: #991b1b;
}


/*
|--------------------------------------------------------------------------
| CHAT HEADER
|--------------------------------------------------------------------------
*/

.chat-header {
    padding: 18px;

    border-bottom:
        1px solid #e2e8f0;

    margin-bottom: 0;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    flex-wrap: wrap;
}


.chat-header h2 {
    margin: 0;

    font-size: 20px;

    color: #1e293b;
}


/*
|--------------------------------------------------------------------------
| CHAT BOX
|--------------------------------------------------------------------------
*/

.chat-box {
    height: 500px;

    overflow-y: auto;

    padding: 20px;

    background: #f8fafc;
}


/*
|--------------------------------------------------------------------------
| MESSAGE ROW
|--------------------------------------------------------------------------
*/

.chat-message {
    display: flex;

    margin-bottom: 15px;
}


/*
|--------------------------------------------------------------------------
| STUDENT MESSAGE
| RIGHT SIDE
|--------------------------------------------------------------------------
*/

.chat-message.student {
    justify-content: flex-end;
}


/*
|--------------------------------------------------------------------------
| ADMIN MESSAGE
| LEFT SIDE
|--------------------------------------------------------------------------
*/

.chat-message.admin {
    justify-content: flex-start;
}


/*
|--------------------------------------------------------------------------
| MESSAGE BUBBLE
| SAME STYLE AS ADMIN SUPPORT
|--------------------------------------------------------------------------
*/

.bubble {
    max-width: 75%;

    padding: 12px 15px;

    border-radius: 12px;

    background: white;

    box-shadow:
        0 2px 8px
        rgba(0, 0, 0, 0.06);

    line-height: 1.5;

    overflow-wrap: anywhere;

    word-break: break-word;
}


/*
|--------------------------------------------------------------------------
| STUDENT BUBBLE
|--------------------------------------------------------------------------
*/

.chat-message.student .bubble {
    background: #dbeafe;

    color: #0f172a;
}


/*
|--------------------------------------------------------------------------
| ADMIN BUBBLE
|--------------------------------------------------------------------------
*/

.chat-message.admin .bubble {
    background: white;

    color: #0f172a;
}


/*
|--------------------------------------------------------------------------
| SENDER
|--------------------------------------------------------------------------
*/

.sender {
    font-size: 12px;

    font-weight: bold;

    color: #475569;

    margin-bottom: 5px;
}


/*
|--------------------------------------------------------------------------
| MESSAGE TEXT
|--------------------------------------------------------------------------
*/

.message-text {
    white-space: pre-wrap;

    line-height: 1.5;
}


/*
|--------------------------------------------------------------------------
| MESSAGE TIME
|--------------------------------------------------------------------------
*/

.time {
    font-size: 11px;

    color: #64748b;

    margin-top: 7px;
}


/*
|--------------------------------------------------------------------------
| REPLY FORM
|--------------------------------------------------------------------------
*/

.reply-form {
    padding: 15px;

    border-top:
        1px solid #e2e8f0;
}


.reply-form textarea {
    width: 100%;

    min-height: 100px;

    resize: vertical;
}


.reply-footer {
    margin-top: 10px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 10px;
}


.char-limit {
    color: #64748b;

    font-size: 12px;
}


.reply-button {
    width: auto;

    padding:
        11px 18px;

    margin-top: 0;
}


.resolved-message {
    padding: 15px;

    background: #f1f5f9;

    color: #64748b;

    text-align: center;

    border-top:
        1px solid #e2e8f0;

    font-weight: bold;
}


.empty {
    text-align: center;

    color: #64748b;

    padding: 40px 10px;
}


.auto-update-status {
    margin-top: 10px;

    font-size: 12px;

    color: #64748b;
}


@media (max-width: 800px) {

    .layout {
        grid-template-columns: 1fr;
    }


    .ticket-list {
        max-height: 300px;

        overflow-y: auto;
    }


    .bubble {
        max-width: 90%;
    }


    .chat-box {
        height: 450px;
    }

}


@media (max-width: 600px) {

    body {
        padding: 10px;
    }


    .chat-header {
        align-items: flex-start;
    }


    .reply-footer {
        flex-direction: column;

        align-items: stretch;
    }


    .reply-button {
        width: 100%;
    }

}

</style>

</head>


<body>


<div class="container">


<div class="header">

<h1>
    Contact Support
</h1>


<p>
    Need help? Send a message to the administrator.
</p>


<a
    href="dashboard.php"
    class="back-link"
>
    ← Back to Dashboard
</a>


<div class="auto-update-status">
    ● Support conversation updates automatically
</div>

</div>


<?php if ($message !== ""): ?>

<div class="message <?= htmlspecialchars(
    $message_type,
    ENT_QUOTES,
    "UTF-8"
) ?>">

<?= htmlspecialchars(
    $message,
    ENT_QUOTES,
    "UTF-8"
) ?>

</div>

<?php endif; ?>


<div
    class="layout"
    id="supportLayout"
>


<!--
|--------------------------------------------------------------------------
| LEFT SIDE
|--------------------------------------------------------------------------
-->

<div>


<div class="card new-ticket">

<h2>
    New Concern
</h2>


<form
    method="POST"
    action=""
>


<?= csrf_field() ?>


<input
    type="hidden"
    name="action"
    value="create_ticket"
>


<label for="subject">
    Subject
</label>


<input
    type="text"
    id="subject"
    name="subject"
    maxlength="150"
    required
    placeholder="Example: Payment concern"
>


<label for="message">
    Message
</label>


<textarea
    id="message"
    name="message"
    maxlength="5000"
    required
    placeholder="Describe your concern..."
></textarea>


<button type="submit">
    Send Concern
</button>


</form>

</div>


<div
    class="card"
    id="ticketList"
>


<h2>
    My Support Tickets
</h2>


<?php if ($tickets_result->num_rows === 0): ?>

<div class="empty">
    No support tickets yet.
</div>


<?php else: ?>


<?php while (
    $ticket_row =
    $tickets_result->fetch_assoc()
): ?>


<a
    href="support.php?ticket=<?= (int) $ticket_row["id"] ?>"
    class="ticket <?= (
        $selected_ticket &&
        (int) $selected_ticket["id"] ===
        (int) $ticket_row["id"]
    ) ? "active" : "" ?>"
>


<div class="ticket-subject">

<?= htmlspecialchars(
    $ticket_row["subject"],
    ENT_QUOTES,
    "UTF-8"
) ?>

</div>


<div class="ticket-date">

<?= htmlspecialchars(
    $ticket_row["created_at"],
    ENT_QUOTES,
    "UTF-8"
) ?>

</div>


<span class="status <?= htmlspecialchars(
    $ticket_row["status"],
    ENT_QUOTES,
    "UTF-8"
) ?>">

<?= htmlspecialchars(
    strtoupper(
        str_replace(
            "_",
            " ",
            $ticket_row["status"]
        )
    ),
    ENT_QUOTES,
    "UTF-8"
) ?>

</span>


</a>


<?php endwhile; ?>


<?php endif; ?>


</div>


</div>


<!--
|--------------------------------------------------------------------------
| RIGHT SIDE - CONVERSATION
|--------------------------------------------------------------------------
-->

<div
    class="card"
    id="supportConversation"
>


<?php if ($selected_ticket): ?>


<div class="chat-header">


<div>

<h2>

<?= htmlspecialchars(
    $selected_ticket["subject"],
    ENT_QUOTES,
    "UTF-8"
) ?>

</h2>

</div>


<span class="status <?= htmlspecialchars(
    $selected_ticket["status"],
    ENT_QUOTES,
    "UTF-8"
) ?>">

<?= htmlspecialchars(
    strtoupper(
        str_replace(
            "_",
            " ",
            $selected_ticket["status"]
        )
    ),
    ENT_QUOTES,
    "UTF-8"
) ?>

</span>


</div>


<div
    class="chat-box"
    id="chatBox"
>


<?php if (
    $selected_messages &&
    $selected_messages->num_rows > 0
): ?>


<?php while (
    $chat =
    $selected_messages->fetch_assoc()
): ?>


<?php

$is_student =
    (
        (int) $chat["user_id"] ===
        $user_id
    );

?>


<div
    class="chat-message <?= $is_student
        ? "student"
        : "admin" ?>"
>


<div class="bubble">


<div class="sender">

<?= $is_student
    ? "You"
    : "Admin" ?>

</div>


<div class="message-text">

<?= htmlspecialchars(
    $chat["message"],
    ENT_QUOTES,
    "UTF-8"
) ?>

</div>


<div class="time">

<?= htmlspecialchars(
    $chat["created_at"],
    ENT_QUOTES,
    "UTF-8"
) ?>

</div>


</div>


</div>


<?php endwhile; ?>


<?php else: ?>


<div class="empty">
    No messages yet.
</div>


<?php endif; ?>


</div>


<?php if (
    $selected_ticket["status"] !== "resolved"
): ?>


<div class="reply-form">


<form
    method="POST"
    action=""
    id="replyForm"
>


<?= csrf_field() ?>


<input
    type="hidden"
    name="action"
    value="reply_ticket"
>


<input
    type="hidden"
    name="ticket_id"
    value="<?= (int) $selected_ticket["id"] ?>"
>


<label for="reply_message">
    Reply
</label>


<textarea
    id="reply_message"
    name="reply_message"
    maxlength="5000"
    required
    placeholder="Type your message..."
></textarea>


<div class="reply-footer">

<span class="char-limit">
    Maximum: 5,000 characters
</span>


<button
    type="submit"
    class="reply-button"
>
    Send Message
</button>

</div>


</form>


</div>


<?php else: ?>


<div class="resolved-message">

🔒 This support concern has been resolved.

</div>


<?php endif; ?>


<?php else: ?>


<div class="empty">

<h2>
    Select a Support Ticket
</h2>


<p>
    Choose a concern from the left
    to view the conversation.
</p>

</div>


<?php endif; ?>


</div>


</div>


</div>


<script>

(function () {

    const AUTO_REFRESH_INTERVAL = 5000;

    let isUpdating = false;


    /*
    |--------------------------------------------------------------------------
    | SCROLL CHAT TO BOTTOM
    |--------------------------------------------------------------------------
    */

    function scrollChatToBottom() {

        const chatBox =
            document.getElementById(
                "chatBox"
            );


        if (chatBox) {

            chatBox.scrollTop =
                chatBox.scrollHeight;

        }

    }


    scrollChatToBottom();


    /*
    |--------------------------------------------------------------------------
    | AUTO UPDATE
    |--------------------------------------------------------------------------
    */

    async function updateSupport() {

        if (isUpdating) {
            return;
        }


        isUpdating = true;


        try {

            const replyBox =
                document.getElementById(
                    "reply_message"
                );


            const newConcernMessage =
                document.getElementById(
                    "message"
                );


            const isTypingReply =
                replyBox &&
                (
                    document.activeElement ===
                    replyBox ||
                    replyBox.value.trim() !== ""
                );


            const isTypingNewConcern =
                newConcernMessage &&
                (
                    document.activeElement ===
                    newConcernMessage ||
                    newConcernMessage.value.trim() !== ""
                );


            /*
            |--------------------------------------------------------------------------
            | GET UPDATED PAGE
            |--------------------------------------------------------------------------
            */

            const response =
                await fetch(
                    window.location.href,
                    {
                        method: "GET",

                        headers: {
                            "X-Requested-With":
                                "XMLHttpRequest",

                            "Cache-Control":
                                "no-cache"
                        },

                        cache: "no-store"
                    }
                );


            if (!response.ok) {
                return;
            }


            const html =
                await response.text();


            const parser =
                new DOMParser();


            const newDocument =
                parser.parseFromString(
                    html,
                    "text/html"
                );


            const currentTicketList =
                document.getElementById(
                    "ticketList"
                );


            const currentConversation =
                document.getElementById(
                    "supportConversation"
                );


            const newTicketList =
                newDocument.getElementById(
                    "ticketList"
                );


            const newConversation =
                newDocument.getElementById(
                    "supportConversation"
                );


            /*
            |--------------------------------------------------------------------------
            | UPDATE TICKET LIST
            |--------------------------------------------------------------------------
            */

            if (
                currentTicketList &&
                newTicketList &&
                currentTicketList.innerHTML !==
                    newTicketList.innerHTML
            ) {

                currentTicketList.innerHTML =
                    newTicketList.innerHTML;

            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE CONVERSATION
            |--------------------------------------------------------------------------
            */

            if (
                currentConversation &&
                newConversation &&
                !isTypingReply &&
                !isTypingNewConcern &&
                currentConversation.innerHTML !==
                    newConversation.innerHTML
            ) {

                const currentChat =
                    currentConversation.querySelector(
                        ".chat-box"
                    );


                const wasNearBottom =
                    currentChat
                        ? (
                            currentChat.scrollHeight -
                            currentChat.scrollTop -
                            currentChat.clientHeight
                        ) < 150
                        : true;


                currentConversation.innerHTML =
                    newConversation.innerHTML;


                const updatedChat =
                    currentConversation.querySelector(
                        ".chat-box"
                    );


                if (
                    updatedChat &&
                    wasNearBottom
                ) {

                    updatedChat.scrollTop =
                        updatedChat.scrollHeight;

                }

            }


        } catch (error) {

            console.warn(
                "Support auto-update failed:",
                error
            );

        } finally {

            isUpdating = false;

        }

    }


    /*
    |--------------------------------------------------------------------------
    | START AUTO UPDATE
    |--------------------------------------------------------------------------
    */

    setInterval(
        updateSupport,
        AUTO_REFRESH_INTERVAL
    );


})();

</script>


</body>

</html>
