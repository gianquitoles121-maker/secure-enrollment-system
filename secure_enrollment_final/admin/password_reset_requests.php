<?php

session_start();

require_once "../config/database.php";
require_once "../config/csrf.php";

/*
|--------------------------------------------------------------------------
| SECURITY HEADERS
|--------------------------------------------------------------------------
*/

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");

/*
|--------------------------------------------------------------------------
| ADMIN ACCESS PROTECTION
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

$message = "";
$error = "";

/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF PROTECTION
    |--------------------------------------------------------------------------
    */

    verify_csrf_token();

    $request_id = filter_var(
        $_POST["request_id"] ?? "",
        FILTER_VALIDATE_INT
    );

    $action = $_POST["action"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | VALIDATE REQUEST
    |--------------------------------------------------------------------------
    */

    if (
        $request_id === false ||
        $request_id <= 0
    ) {

        $error = "Invalid password reset request.";

    } elseif (
        !in_array(
            $action,
            ["approve", "reject"],
            true
        )
    ) {

        $error = "Invalid action.";

    } else {

        try {

            $conn->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | GET AND LOCK PENDING REQUEST
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare(
                "SELECT
                    password_reset_requests.id,
                    password_reset_requests.user_id,
                    users.username,
                    students.first_name,
                    students.last_name,
                    students.email
                 FROM password_reset_requests

                 INNER JOIN users
                    ON password_reset_requests.user_id = users.id

                 INNER JOIN students
                    ON users.id = students.user_id

                 WHERE password_reset_requests.id = ?
                 AND password_reset_requests.status = 'pending'

                 LIMIT 1

                 FOR UPDATE"
            );

            if (!$stmt) {
                throw new Exception(
                    "Failed to prepare request query."
                );
            }

            $stmt->bind_param(
                "i",
                $request_id
            );

            if (!$stmt->execute()) {
                $stmt->close();

                throw new Exception(
                    "Failed to retrieve password reset request."
                );
            }

            $result = $stmt->get_result();

            if ($result->num_rows !== 1) {

                $stmt->close();
                $conn->rollback();

                $error =
                    "This password reset request is no longer pending.";

            } else {

                $request = $result->fetch_assoc();

                $stmt->close();

                $user_id =
                    (int) $request["user_id"];

                $admin_id =
                    (int) $_SESSION["user_id"];

                $ip_address =
                    $_SERVER["REMOTE_ADDR"] ?? "UNKNOWN";

                /*
                |--------------------------------------------------------------------------
                | APPROVE REQUEST
                |--------------------------------------------------------------------------
                */

                if ($action === "approve") {

                    /*
                    |--------------------------------------------------------------------------
                    | GENERATE TEMPORARY PASSWORD
                    |--------------------------------------------------------------------------
                    */

                    $temporary_password =
                        "Reset" . random_int(
                            100000,
                            999999
                        );

                    $hashed_password = password_hash(
                        $temporary_password,
                        PASSWORD_DEFAULT
                    );

                    if ($hashed_password === false) {

                        throw new Exception(
                            "Failed to hash temporary password."
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE USER PASSWORD
                    |--------------------------------------------------------------------------
                    |
                    | Also clear brute-force lock.
                    |
                    */

                    $update = $conn->prepare(
                        "UPDATE users
                         SET
                            password = ?,
                            must_change_password = 1,
                            failed_login_attempts = 0,
                            locked_until = NULL
                         WHERE id = ?"
                    );

                    if (!$update) {
                        throw new Exception(
                            "Failed to prepare password update."
                        );
                    }

                    $update->bind_param(
                        "si",
                        $hashed_password,
                        $user_id
                    );

                    if (!$update->execute()) {

                        $update->close();

                        throw new Exception(
                            "Failed to update password."
                        );
                    }

                    if ($update->affected_rows !== 1) {

                        $update->close();

                        throw new Exception(
                            "Password was not updated."
                        );
                    }

                    $update->close();

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE RESET REQUEST
                    |--------------------------------------------------------------------------
                    */

                    $update_request = $conn->prepare(
                        "UPDATE password_reset_requests
                         SET
                            status = 'approved',
                            processed_at = NOW(),
                            processed_by = ?
                         WHERE id = ?
                         AND status = 'pending'"
                    );

                    if (!$update_request) {
                        throw new Exception(
                            "Failed to prepare request update."
                        );
                    }

                    $update_request->bind_param(
                        "ii",
                        $admin_id,
                        $request_id
                    );

                    if (!$update_request->execute()) {

                        $update_request->close();

                        throw new Exception(
                            "Failed to update reset request."
                        );
                    }

                    if ($update_request->affected_rows !== 1) {

                        $update_request->close();

                        throw new Exception(
                            "Reset request could not be approved."
                        );
                    }

                    $update_request->close();

                    /*
                    |--------------------------------------------------------------------------
                    | ACTIVITY LOG
                    |--------------------------------------------------------------------------
                    */

                    $action_log =
                        "Admin approved password reset request for username: "
                        . $request["username"];

                    $log = $conn->prepare(
                        "INSERT INTO activity_logs
                        (
                            user_id,
                            action,
                            ip_address
                        )
                        VALUES (?, ?, ?)"
                    );

                    if (!$log) {
                        throw new Exception(
                            "Failed to prepare activity log."
                        );
                    }

                    $log->bind_param(
                        "iss",
                        $admin_id,
                        $action_log,
                        $ip_address
                    );

                    if (!$log->execute()) {

                        $log->close();

                        throw new Exception(
                            "Failed to save activity log."
                        );
                    }

                    $log->close();

                    /*
                    |--------------------------------------------------------------------------
                    | STUDENT NOTIFICATION
                    |--------------------------------------------------------------------------
                    */

                    $notification_message =
                        "Your password reset request was approved. "
                        . "Please use the temporary password provided "
                        . "by the administrator.";

                    $notification = $conn->prepare(
                        "INSERT INTO notifications
                        (
                            user_id,
                            message
                        )
                        VALUES (?, ?)"
                    );

                    if (!$notification) {
                        throw new Exception(
                            "Failed to prepare notification."
                        );
                    }

                    $notification->bind_param(
                        "is",
                        $user_id,
                        $notification_message
                    );

                    if (!$notification->execute()) {

                        $notification->close();

                        throw new Exception(
                            "Failed to save notification."
                        );
                    }

                    $notification->close();

                    /*
                    |--------------------------------------------------------------------------
                    | COMMIT
                    |--------------------------------------------------------------------------
                    */

                    $conn->commit();

                    /*
                    |--------------------------------------------------------------------------
                    | SHOW TEMPORARY PASSWORD
                    |--------------------------------------------------------------------------
                    */

                    $message =
                        "Password reset approved for "
                        . $request["username"]
                        . ". Temporary password: "
                        . $temporary_password;

                }

                /*
                |--------------------------------------------------------------------------
                | REJECT REQUEST
                |--------------------------------------------------------------------------
                */

                elseif ($action === "reject") {

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE REQUEST STATUS
                    |--------------------------------------------------------------------------
                    */

                    $update_request = $conn->prepare(
                        "UPDATE password_reset_requests
                         SET
                            status = 'rejected',
                            processed_at = NOW(),
                            processed_by = ?
                         WHERE id = ?
                         AND status = 'pending'"
                    );

                    if (!$update_request) {
                        throw new Exception(
                            "Failed to prepare request update."
                        );
                    }

                    $update_request->bind_param(
                        "ii",
                        $admin_id,
                        $request_id
                    );

                    if (!$update_request->execute()) {

                        $update_request->close();

                        throw new Exception(
                            "Failed to reject reset request."
                        );
                    }

                    if ($update_request->affected_rows !== 1) {

                        $update_request->close();

                        throw new Exception(
                            "This password reset request is no longer pending."
                        );
                    }

                    $update_request->close();

                    /*
                    |--------------------------------------------------------------------------
                    | ACTIVITY LOG
                    |--------------------------------------------------------------------------
                    */

                    $action_log =
                        "Admin rejected password reset request for username: "
                        . $request["username"];

                    $log = $conn->prepare(
                        "INSERT INTO activity_logs
                        (
                            user_id,
                            action,
                            ip_address
                        )
                        VALUES (?, ?, ?)"
                    );

                    if (!$log) {
                        throw new Exception(
                            "Failed to prepare activity log."
                        );
                    }

                    $log->bind_param(
                        "iss",
                        $admin_id,
                        $action_log,
                        $ip_address
                    );

                    if (!$log->execute()) {

                        $log->close();

                        throw new Exception(
                            "Failed to save activity log."
                        );
                    }

                    $log->close();

                    /*
                    |--------------------------------------------------------------------------
                    | COMMIT
                    |--------------------------------------------------------------------------
                    */

                    $conn->commit();

                    $message =
                        "Password reset request for "
                        . $request["username"]
                        . " has been rejected.";
                }
            }

        } catch (Throwable $e) {

            if ($conn->in_transaction) {
                $conn->rollback();
            }

            error_log(
                "Password reset request error: "
                . $e->getMessage()
            );

            $error =
                "Unable to process the password reset request.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| GET PENDING REQUESTS
|--------------------------------------------------------------------------
*/

$requests = false;

$stmt = $conn->prepare(
    "SELECT
        password_reset_requests.id,
        password_reset_requests.user_id,
        password_reset_requests.requested_at,
        users.username,
        students.first_name,
        students.last_name,
        students.email

     FROM password_reset_requests

     INNER JOIN users
        ON password_reset_requests.user_id = users.id

     INNER JOIN students
        ON users.id = students.user_id

     WHERE password_reset_requests.status = 'pending'

     ORDER BY password_reset_requests.requested_at ASC"
);

if ($stmt) {

    if ($stmt->execute()) {

        $requests = $stmt->get_result();

    } else {

        error_log(
            "Password reset pending request query failed: "
            . $stmt->error
        );
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

    <title>Password Reset Requests | Secure Enrollment</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;

            font-family: Arial, sans-serif;

            background: #f4f7fb;

            color: #333;
        }

        .navbar {
            background: #0d6efd;

            color: white;

            padding: 18px 30px;

            display: flex;

            justify-content: space-between;

            align-items: center;
        }

        .navbar h2 {
            margin: 0;
        }

        .logout {
            color: white;

            text-decoration: none;

            font-weight: bold;
        }

        .logout:hover {
            text-decoration: underline;
        }

        .container {
            max-width: 1100px;

            margin: 40px auto;

            padding: 0 20px;
        }

        .card {
            background: white;

            border-radius: 12px;

            padding: 30px;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin-top: 0;

            margin-bottom: 10px;
        }

        .description {
            color: #777;

            margin-bottom: 25px;
        }

        .message {
            background: #d1e7dd;

            color: #0f5132;

            padding: 15px;

            border-radius: 8px;

            margin-bottom: 20px;

            font-weight: bold;

            word-break: break-word;
        }

        .error {
            background: #f8d7da;

            color: #842029;

            padding: 15px;

            border-radius: 8px;

            margin-bottom: 20px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;

            border-collapse: collapse;

            min-width: 850px;
        }

        th {
            background: #f1f5f9;

            text-align: left;

            padding: 13px;

            border-bottom: 2px solid #ddd;
        }

        td {
            padding: 13px;

            border-bottom: 1px solid #eee;

            vertical-align: middle;
        }

        tr:hover {
            background: #f8fafc;
        }

        .actions {
            display: flex;

            gap: 8px;

            flex-wrap: wrap;
        }

        .approve-btn {
            background: #198754;

            color: white;

            border: none;

            padding: 9px 14px;

            border-radius: 6px;

            font-weight: bold;

            cursor: pointer;
        }

        .approve-btn:hover {
            background: #157347;
        }

        .reject-btn {
            background: #dc3545;

            color: white;

            border: none;

            padding: 9px 14px;

            border-radius: 6px;

            font-weight: bold;

            cursor: pointer;
        }

        .reject-btn:hover {
            background: #bb2d3b;
        }

        .empty {
            text-align: center;

            padding: 40px 20px;

            color: #777;
        }

        .back {
            display: inline-block;

            margin-top: 25px;

            color: #0d6efd;

            text-decoration: none;

            font-weight: bold;
        }

        .back:hover {
            text-decoration: underline;
        }

        @media (max-width: 700px) {

            .navbar {
                padding: 15px 20px;
            }

            .navbar h2 {
                font-size: 18px;
            }

            .container {
                margin-top: 25px;
            }

            .card {
                padding: 20px;
            }

            .actions {
                flex-direction: column;
            }

            .approve-btn,
            .reject-btn {
                width: 100%;
            }

        }

    </style>

</head>

<body>

<div class="navbar">

    <h2>
        Secure Enrollment - Admin
    </h2>

    <a
        href="../logout.php"
        class="logout"
    >
        Logout
    </a>

</div>

<div class="container">

    <div class="card">

        <h1>
            🔐 Password Reset Requests
        </h1>

        <p class="description">
            Review and process student password reset requests.
        </p>

        <?php if ($message !== ""): ?>

            <div class="message">

                <?= htmlspecialchars(
                    $message,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

                <?php if (
                    strpos(
                        $message,
                        "approved"
                    ) !== false
                ): ?>

                    <br><br>

                    <strong>
                        Give this temporary password to the student.
                    </strong>

                <?php endif; ?>

            </div>

        <?php endif; ?>

        <?php if ($error !== ""): ?>

            <div class="error">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>

        <?php if (
            $requests &&
            $requests->num_rows > 0
        ): ?>

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Username
                            </th>

                            <th>
                                Student Name
                            </th>

                            <th>
                                Email
                            </th>

                            <th>
                                Requested
                            </th>

                            <th>
                                Action
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php while (
                            $row =
                            $requests->fetch_assoc()
                        ): ?>

                            <tr>

                                <td>

                                    <?= htmlspecialchars(
                                        $row["username"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        $row["first_name"]
                                        . " "
                                        . $row["last_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        $row["email"] ?? "Not provided",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        $row["requested_at"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </td>

                                <td>

                                    <div class="actions">

                                        <!-- APPROVE -->

                                        <form
                                            method="POST"
                                            onsubmit="return confirm('Approve this password reset request? A temporary password will be generated.');"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int) $row["id"] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve"
                                            >

                                            <button
                                                type="submit"
                                                class="approve-btn"
                                            >
                                                Approve & Reset
                                            </button>

                                        </form>

                                        <!-- REJECT -->

                                        <form
                                            method="POST"
                                            onsubmit="return confirm('Reject this password reset request? No password will be changed.');"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int) $row["id"] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="reject"
                                            >

                                            <button
                                                type="submit"
                                                class="reject-btn"
                                            >
                                                Reject Request
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty">

                ✅ No pending password reset requests.

            </div>

        <?php endif; ?>

        <a
            href="dashboard.php"
            class="back"
        >
            ← Back to Admin Dashboard
        </a>

    </div>

</div>

</body>

</html>
