<?php

session_start();

require_once "../config/database.php";
require_once "../config/csrf.php";

/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}

$admin_id = (int) $_SESSION["user_id"];

$message = "";
$error = "";

$MAX_MESSAGE_LENGTH = 5000;


/*
|--------------------------------------------------------------------------
| NO CACHE
|--------------------------------------------------------------------------
*/

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verify_csrf_token();

    $action = trim($_POST["action"] ?? "");


    /*
    |--------------------------------------------------------------------------
    | ADMIN REPLY
    |--------------------------------------------------------------------------
    */

    if ($action === "reply") {

        $ticket_id = filter_input(
            INPUT_POST,
            "ticket_id",
            FILTER_VALIDATE_INT
        );

        $reply = trim($_POST["message"] ?? "");

        if (!$ticket_id || $ticket_id <= 0) {

            $error = "Invalid ticket.";

        } elseif ($reply === "") {

            $error = "Reply cannot be empty.";

        } elseif (strlen($reply) > $MAX_MESSAGE_LENGTH) {

            $error = "Reply must not exceed 5,000 characters.";

        } else {

            $transaction_started = false;

            try {

                $conn->begin_transaction();
                $transaction_started = true;


                /*
                |--------------------------------------------------------------------------
                | LOCK TICKET
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare(
                    "SELECT id, user_id, status
                     FROM support_tickets
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                $stmt->bind_param(
                    "i",
                    $ticket_id
                );

                $stmt->execute();

                $result = $stmt->get_result();

                $ticket = $result->fetch_assoc();

                $stmt->close();


                if (!$ticket) {

                    throw new Exception(
                        "Ticket not found."
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | RESOLVED TICKET CANNOT RECEIVE REPLIES
                |--------------------------------------------------------------------------
                */

                if ($ticket["status"] === "resolved") {

                    throw new Exception(
                        "This ticket has already been resolved."
                    );
                }


                $student_user_id = (int) $ticket["user_id"];


                /*
                |--------------------------------------------------------------------------
                | INSERT MESSAGE
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare(
                    "INSERT INTO support_messages
                        (ticket_id, user_id, message)
                     VALUES (?, ?, ?)"
                );

                $stmt->bind_param(
                    "iis",
                    $ticket_id,
                    $admin_id,
                    $reply
                );

                $stmt->execute();

                $stmt->close();


                /*
                |--------------------------------------------------------------------------
                | UPDATE TICKET STATUS
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare(
                    "UPDATE support_tickets
                     SET status = 'in_progress'
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "i",
                    $ticket_id
                );

                $stmt->execute();

                $stmt->close();


                /*
                |--------------------------------------------------------------------------
                | NOTIFICATION
                |--------------------------------------------------------------------------
                */

                $notification_message =
                    "An admin replied to your support ticket.";

                $stmt = $conn->prepare(
                    "INSERT INTO notifications
                        (user_id, message)
                     VALUES (?, ?)"
                );

                $stmt->bind_param(
                    "is",
                    $student_user_id,
                    $notification_message
                );

                $stmt->execute();

                $stmt->close();


                /*
                |--------------------------------------------------------------------------
                | ACTIVITY LOG
                |--------------------------------------------------------------------------
                */

                $activity =
                    "Admin replied to support ticket #" .
                    $ticket_id;

                $ip_address =
                    $_SERVER["REMOTE_ADDR"] ?? "";

                $stmt = $conn->prepare(
                    "INSERT INTO activity_logs
                        (user_id, action, ip_address)
                     VALUES (?, ?, ?)"
                );

                $stmt->bind_param(
                    "iss",
                    $admin_id,
                    $activity,
                    $ip_address
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
                | PRG
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: support.php?ticket=" .
                    $ticket_id .
                    "&sent=1"
                );

                exit;


            } catch (Throwable $e) {

                if ($transaction_started) {
                    $conn->rollback();
                }

                $error =
                    "Unable to send the reply. Please try again.";
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE TICKET STATUS
    |--------------------------------------------------------------------------
    */

    elseif ($action === "status") {

        $ticket_id = filter_input(
            INPUT_POST,
            "ticket_id",
            FILTER_VALIDATE_INT
        );

        $new_status = trim(
            $_POST["status"] ?? ""
        );

        $allowed_statuses = [
            "open",
            "in_progress",
            "resolved"
        ];


        if (!$ticket_id || $ticket_id <= 0) {

            $error = "Invalid ticket.";

        } elseif (
            !in_array(
                $new_status,
                $allowed_statuses,
                true
            )
        ) {

            $error = "Invalid ticket status.";

        } else {

            $transaction_started = false;

            try {

                $conn->begin_transaction();
                $transaction_started = true;


                /*
                |--------------------------------------------------------------------------
                | LOCK TICKET
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare(
                    "SELECT id, user_id, status
                     FROM support_tickets
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                $stmt->bind_param(
                    "i",
                    $ticket_id
                );

                $stmt->execute();

                $result = $stmt->get_result();

                $ticket = $result->fetch_assoc();

                $stmt->close();


                if (!$ticket) {

                    throw new Exception(
                        "Ticket not found."
                    );
                }


                $student_user_id =
                    (int) $ticket["user_id"];

                $old_status =
                    $ticket["status"];


                /*
                |--------------------------------------------------------------------------
                | SAME STATUS
                |--------------------------------------------------------------------------
                */

                if ($old_status === $new_status) {

                    $conn->commit();
                    $transaction_started = false;

                    header(
                        "Location: support.php?ticket=" .
                        $ticket_id .
                        "&status_same=1"
                    );

                    exit;
                }


                /*
                |--------------------------------------------------------------------------
                | UPDATE STATUS
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare(
                    "UPDATE support_tickets
                     SET status = ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "si",
                    $new_status,
                    $ticket_id
                );

                $stmt->execute();

                $stmt->close();


                /*
                |--------------------------------------------------------------------------
                | NOTIFICATION
                |--------------------------------------------------------------------------
                */

                $status_label =
                    ucwords(
                        str_replace(
                            "_",
                            " ",
                            $new_status
                        )
                    );

                $notification_message =
                    "Your support ticket status was changed to " .
                    $status_label .
                    ".";

                $stmt = $conn->prepare(
                    "INSERT INTO notifications
                        (user_id, message)
                     VALUES (?, ?)"
                );

                $stmt->bind_param(
                    "is",
                    $student_user_id,
                    $notification_message
                );

                $stmt->execute();

                $stmt->close();


                /*
                |--------------------------------------------------------------------------
                | ACTIVITY LOG
                |--------------------------------------------------------------------------
                */

                $activity =
                    "Admin changed support ticket #" .
                    $ticket_id .
                    " status from " .
                    $old_status .
                    " to " .
                    $new_status;

                $ip_address =
                    $_SERVER["REMOTE_ADDR"] ?? "";

                $stmt = $conn->prepare(
                    "INSERT INTO activity_logs
                        (user_id, action, ip_address)
                     VALUES (?, ?, ?)"
                );

                $stmt->bind_param(
                    "iss",
                    $admin_id,
                    $activity,
                    $ip_address
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
                | PRG
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: support.php?ticket=" .
                    $ticket_id .
                    "&status_updated=1"
                );

                exit;


            } catch (Throwable $e) {

                if ($transaction_started) {
                    $conn->rollback();
                }

                $error =
                    "Unable to update the ticket status. Please try again.";
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/

if (isset($_GET["sent"])) {

    $message = "Reply sent successfully.";

}

if (isset($_GET["status_updated"])) {

    $message = "Ticket status updated successfully.";

}

if (isset($_GET["status_same"])) {

    $message = "Ticket is already using that status.";

}


/*
|--------------------------------------------------------------------------
| GET SELECTED TICKET
|--------------------------------------------------------------------------
*/

$selected_ticket_id = filter_input(
    INPUT_GET,
    "ticket",
    FILTER_VALIDATE_INT
);

if (
    !$selected_ticket_id ||
    $selected_ticket_id <= 0
) {
    $selected_ticket_id = 0;
}


/*
|--------------------------------------------------------------------------
| GET ALL TICKETS
|--------------------------------------------------------------------------
*/

$tickets = [];

$stmt = $conn->prepare(
    "SELECT
        t.id,
        t.user_id,
        t.subject,
        t.status,
        t.created_at,
        t.updated_at,
        s.first_name,
        s.last_name,
        u.username
     FROM support_tickets t
     INNER JOIN users u
        ON t.user_id = u.id
     LEFT JOIN students s
        ON s.user_id = u.id
     ORDER BY
        CASE
            WHEN t.status = 'open' THEN 1
            WHEN t.status = 'in_progress' THEN 2
            WHEN t.status = 'resolved' THEN 3
            ELSE 4
        END,
        t.updated_at DESC"
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $tickets[] = $row;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| DEFAULT FIRST TICKET
|--------------------------------------------------------------------------
*/

if (
    $selected_ticket_id === 0 &&
    !empty($tickets)
) {
    $selected_ticket_id =
        (int) $tickets[0]["id"];
}


/*
|--------------------------------------------------------------------------
| GET SELECTED TICKET DETAILS
|--------------------------------------------------------------------------
*/

$selected_ticket = null;

if ($selected_ticket_id > 0) {

    $stmt = $conn->prepare(
        "SELECT
            t.id,
            t.user_id,
            t.subject,
            t.status,
            t.created_at,
            t.updated_at,
            s.first_name,
            s.last_name,
            s.student_number,
            u.username,
            u.id AS student_user_id
         FROM support_tickets t
         INNER JOIN users u
            ON t.user_id = u.id
         LEFT JOIN students s
            ON s.user_id = u.id
         WHERE t.id = ?
         LIMIT 1"
    );

    $stmt->bind_param(
        "i",
        $selected_ticket_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $selected_ticket =
        $result->fetch_assoc();

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| GET MESSAGES
|--------------------------------------------------------------------------
*/

$messages = [];

if ($selected_ticket) {

    $stmt = $conn->prepare(
        "SELECT
            m.id,
            m.user_id,
            m.message,
            m.created_at,
            u.username,
            u.role
         FROM support_messages m
         INNER JOIN users u
            ON m.user_id = u.id
         WHERE m.ticket_id = ?
         ORDER BY m.created_at ASC, m.id ASC"
    );

    $stmt->bind_param(
        "i",
        $selected_ticket_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }

    $stmt->close();
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
<title>Student Support | Admin</title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {
            margin: 0;

            font-family:
                Arial,
                sans-serif;

            background: #f1f5f9;

            color: #0f172a;
        }


        .header {
            background: #1d4ed8;

            color: white;

            padding: 18px 25px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;
        }


        .header h1 {
            margin: 0;

            font-size: 22px;
        }


        .back-link {
            color: white;

            text-decoration: none;

            font-weight: bold;
        }


        .container {
            max-width: 1400px;

            margin: 25px auto;

            padding: 0 20px;
        }


        .alert {
            padding: 12px 15px;

            border-radius: 8px;

            margin-bottom: 15px;

            font-weight: bold;
        }


        .success {
            background: #dcfce7;

            color: #166534;
        }


        .error {
            background: #fee2e2;

            color: #991b1b;
        }


        .layout {
            display: grid;

            grid-template-columns:
                350px 1fr;

            gap: 20px;

            min-height: 650px;
        }


        .tickets-panel,
        .chat-panel {
            background: white;

            border-radius: 12px;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.08);

            overflow: hidden;
        }


        .panel-title {
            padding: 18px;

            border-bottom:
                1px solid #e2e8f0;

            font-weight: bold;

            font-size: 18px;
        }


        .ticket-list {
            max-height: 650px;

            overflow-y: auto;
        }


        .ticket {
            display: block;

            padding: 15px;

            border-bottom:
                1px solid #e2e8f0;

            text-decoration: none;

            color: inherit;
        }


        .ticket:hover {
            background: #f8fafc;
        }


        .ticket.active {
            background: #eff6ff;

            border-left:
                4px solid #2563eb;
        }


        .ticket-subject {
            font-weight: bold;

            margin-bottom: 7px;
        }


        .ticket-student {
            color: #64748b;

            font-size: 13px;

            margin-bottom: 8px;
        }


        .status {
            display: inline-block;

            padding: 4px 8px;

            border-radius: 999px;

            font-size: 12px;

            font-weight: bold;
        }


        .status-open {
            background: #fef3c7;

            color: #92400e;
        }


        .status-in_progress {
            background: #dbeafe;

            color: #1e40af;
        }


        .status-resolved {
            background: #dcfce7;

            color: #166534;
        }


        .chat-header {
            padding: 18px;

            border-bottom:
                1px solid #e2e8f0;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            flex-wrap: wrap;
        }


        .chat-header h2 {
            margin: 0 0 5px;

            font-size: 20px;
        }


        .student-info {
            color: #64748b;

            font-size: 14px;
        }


        .status-form {
            display: flex;

            gap: 8px;

            align-items: center;
        }


        .status-form select {
            padding: 8px;

            border:
                1px solid #cbd5e1;

            border-radius: 7px;
        }


        .status-form button {
            padding: 8px 12px;

            border: none;

            border-radius: 7px;

            background: #2563eb;

            color: white;

            font-weight: bold;

            cursor: pointer;
        }


        .messages {
            height: 500px;

            overflow-y: auto;

            padding: 20px;

            background: #f8fafc;
        }


        .message-row {
            display: flex;

            margin-bottom: 15px;
        }


        .message-row.admin {
            justify-content: flex-end;
        }


        .message-bubble {
            max-width: 75%;

            padding: 12px 15px;

            border-radius: 12px;

            background: white;

            box-shadow:
                0 2px 8px
                rgba(0, 0, 0, 0.06);
        }


        .message-row.admin
        .message-bubble {
            background: #dbeafe;
        }


        .message-user {
            font-size: 12px;

            font-weight: bold;

            color: #475569;

            margin-bottom: 5px;
        }


        .message-text {
            line-height: 1.5;

            white-space: normal;

            overflow-wrap: anywhere;
        }


        .message-time {
            font-size: 11px;

            color: #64748b;

            margin-top: 7px;
        }


        .reply-area {
            padding: 15px;

            border-top:
                1px solid #e2e8f0;
        }


        .reply-area textarea {
            width: 100%;

            min-height: 100px;

            resize: vertical;

            padding: 12px;

            border:
                1px solid #cbd5e1;

            border-radius: 8px;

            font-family: inherit;

            font-size: 14px;
        }


        .reply-area textarea:focus {
            outline: 2px solid #93c5fd;

            border-color: #2563eb;
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
            padding: 11px 18px;

            border: none;

            border-radius: 8px;

            background: #2563eb;

            color: white;

            font-weight: bold;

            cursor: pointer;
        }


        .reply-button:hover,
        .status-form button:hover {
            background: #1d4ed8;
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
            padding: 40px;

            text-align: center;

            color: #64748b;
        }


        @media (max-width: 900px) {

            .layout {
                grid-template-columns: 1fr;
            }

            .ticket-list {
                max-height: 300px;
            }

        }


        @media (max-width: 600px) {

            .container {
                padding: 0 10px;
            }

            .header {
                padding: 15px;
            }

            .chat-header {
                align-items: flex-start;
            }

            .status-form {
                width: 100%;
            }

            .status-form select {
                flex: 1;
            }

            .messages {
                height: 450px;
            }

            .message-bubble {
                max-width: 90%;
            }

        }

    </style>

</head>


<body>


<div class="header">

    <h1>
        💬 Student Support
    </h1>

    <a
        href="dashboard.php"
        class="back-link"
    >
        ← Back to Dashboard
    </a>

</div>


<div class="container">


    <?php if ($message !== ""): ?>

        <div class="alert success">

            <?= htmlspecialchars(
                $message,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div class="alert error">

            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <div class="layout">


        <!--
        ============================================================
        TICKET LIST
        ============================================================
        -->

        <div class="tickets-panel">

            <div class="panel-title">
                Support Tickets
            </div>


            <?php if (empty($tickets)): ?>

                <div class="empty">
                    No support tickets yet.
                </div>

            <?php else: ?>

                <div class="ticket-list">


                    <?php foreach ($tickets as $ticket): ?>

                        <a
                            href="support.php?ticket=<?= (int) $ticket["id"] ?>"
                            class="ticket <?= (
                                $selected_ticket_id ==
                                (int) $ticket["id"]
                            ) ? "active" : "" ?>"
                        >

                            <div class="ticket-subject">

                                <?= htmlspecialchars(
                                    $ticket["subject"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </div>


                            <div class="ticket-student">

                                <?= htmlspecialchars(
                                    trim(
                                        ($ticket["first_name"] ?? "") .
                                        " " .
                                        ($ticket["last_name"] ?? "")
                                    ),
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                                <?php if (
                                    !empty($ticket["username"])
                                ): ?>

                                    —
                                    <?= htmlspecialchars(
                                        $ticket["username"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                <?php endif; ?>

                            </div>


                            <span
                                class="status status-<?= htmlspecialchars(
                                    $ticket["status"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                            >

                                <?= htmlspecialchars(
                                    ucwords(
                                        str_replace(
                                            "_",
                                            " ",
                                            $ticket["status"]
                                        )
                                    ),
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </span>

                        </a>

                    <?php endforeach; ?>


                </div>

            <?php endif; ?>


        </div>


        <!--
        ============================================================
        CHAT PANEL
        ============================================================
        -->

        <div class="chat-panel">


            <?php if (!$selected_ticket): ?>

                <div class="empty">

                    Select a support ticket to view the conversation.

                </div>


            <?php else: ?>


                <div class="chat-header">


                    <div>

                        <h2>

                            <?= htmlspecialchars(
                                $selected_ticket["subject"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </h2>


                        <div class="student-info">

                            Student:

                            <?= htmlspecialchars(
                                trim(
                                    ($selected_ticket["first_name"] ?? "") .
                                    " " .
                                    ($selected_ticket["last_name"] ?? "")
                                ),
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>


                            <?php if (
                                !empty(
                                    $selected_ticket["student_number"]
                                )
                            ): ?>

                                |

                                Student No:

                                <?= htmlspecialchars(
                                    $selected_ticket[
                                        "student_number"
                                    ],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            <?php endif; ?>


                        </div>

                    </div>


                    <form
                        method="POST"
                        class="status-form"
                    >

                        <?= csrf_field() ?>


                        <input
                            type="hidden"
                            name="action"
                            value="status"
                        >


                        <input
                            type="hidden"
                            name="ticket_id"
                            value="<?= (int) $selected_ticket["id"] ?>"
                        >


                        <select
                            name="status"
                            aria-label="Ticket status"
                        >

                            <option
                                value="open"
                                <?= $selected_ticket["status"] === "open"
                                    ? "selected"
                                    : "" ?>
                            >
                                Open
                            </option>


                            <option
                                value="in_progress"
                                <?= $selected_ticket["status"] === "in_progress"
                                    ? "selected"
                                    : "" ?>
                            >
                                In Progress
                            </option>


                            <option
                                value="resolved"
                                <?= $selected_ticket["status"] === "resolved"
                                    ? "selected"
                                    : "" ?>
                            >
                                Resolved
                            </option>

                        </select>


                        <button type="submit">
                            Update
                        </button>

                    </form>


                </div>


                <!--
                ====================================================
                MESSAGES
                ====================================================
                -->

                <div
                    class="messages"
                    id="messages"
                >


                    <?php if (empty($messages)): ?>

                        <div class="empty">
                            No messages yet.
                        </div>

                    <?php else: ?>


                        <?php foreach ($messages as $msg): ?>

                            <?php
                            $is_admin =
                                $msg["role"] === "admin";
                            ?>


                            <div
                                class="message-row <?= $is_admin
                                    ? "admin"
                                    : "" ?>"
                            >

                                <div
                                    class="message-bubble"
                                >


                                    <div class="message-user">

                                        <?= $is_admin
                                            ? "Admin"
                                            : "Student" ?>


                                        —

                                        <?= htmlspecialchars(
                                            $msg["username"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </div>


                                    <div class="message-text">

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $msg["message"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            )
                                        ) ?>

                                    </div>


                                    <div class="message-time">

                                        <?= htmlspecialchars(
                                            $msg["created_at"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </div>


                                </div>

                            </div>


                        <?php endforeach; ?>


                    <?php endif; ?>


                </div>


                <!--
                ====================================================
                REPLY
                ====================================================
                -->

                <?php if (
                    $selected_ticket["status"] !== "resolved"
                ): ?>


                    <div class="reply-area">


                        <form
                            method="POST"
                            id="replyForm"
                        >

                            <?= csrf_field() ?>


                            <input
                                type="hidden"
                                name="action"
                                value="reply"
                            >


                            <input
                                type="hidden"
                                name="ticket_id"
                                value="<?= (int) $selected_ticket["id"] ?>"
                            >


                            <textarea
                                name="message"
                                id="replyMessage"
                                maxlength="5000"
                                required
                                placeholder="Type your reply..."
                            ></textarea>


                            <div class="reply-footer">


                                <span class="char-limit">

                                    Maximum:
                                    5,000 characters

                                </span>


                                <button
                                    type="submit"
                                    class="reply-button"
                                >
                                    Send Reply
                                </button>


                            </div>


                        </form>


                    </div>


                <?php else: ?>


                    <div class="resolved-message">

                        🔒 This ticket is resolved.
                        Further replies are disabled.

                    </div>


                <?php endif; ?>


            <?php endif; ?>


        </div>


    </div>


</div>


<script>

(function () {

    const messages =
        document.getElementById("messages");

    const replyForm =
        document.getElementById("replyForm");

    const replyMessage =
        document.getElementById("replyMessage");


    /*
    |--------------------------------------------------------------------------
    | AUTO SCROLL
    |--------------------------------------------------------------------------
    */

    if (messages) {

        messages.scrollTop =
            messages.scrollHeight;

    }


    /*
    |--------------------------------------------------------------------------
    | REPLY SUBMIT PROTECTION
    |--------------------------------------------------------------------------
    */

    if (replyForm) {

        replyForm.addEventListener(
            "submit",
            function (event) {

                const value =
                    replyMessage.value.trim();


                if (value === "") {

                    event.preventDefault();

                    alert(
                        "Please enter a reply."
                    );

                    return;

                }


                if (value.length > 5000) {

                    event.preventDefault();

                    alert(
                        "Reply must not exceed 5,000 characters."
                    );

                    return;

                }


                isTyping = false;

            }
        );

    }

})();

</script>


</body>

</html>
