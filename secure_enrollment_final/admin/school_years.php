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
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}

$message = "";
$message_type = "";

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

    $action = $_POST["action"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | CLOSE SCHOOL YEAR
    |--------------------------------------------------------------------------
    */

    if ($action === "close") {

        $school_year_id = filter_var(
            $_POST["school_year_id"] ?? "",
            FILTER_VALIDATE_INT
        );

        if ($school_year_id === false || $school_year_id <= 0) {

            $message = "Invalid school year.";
            $message_type = "error";

        } else {

            try {

                $conn->begin_transaction();

                /*
                |--------------------------------------------------------------------------
                | LOCK ACTIVE SCHOOL YEAR
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare(
                    "SELECT id, school_year
                     FROM school_years
                     WHERE id = ?
                     AND status = 'active'
                     LIMIT 1
                     FOR UPDATE"
                );

                if (!$stmt) {
                    throw new Exception("Database query failed.");
                }

                $stmt->bind_param(
                    "i",
                    $school_year_id
                );

                if (!$stmt->execute()) {
                    throw new Exception("Database query failed.");
                }

                $result = $stmt->get_result();

                if ($result->num_rows !== 1) {

                    $stmt->close();
                    $conn->rollback();

                    $message =
                        "School year not found or it is already closed.";

                    $message_type = "error";

                } else {

                    $school_year = $result->fetch_assoc();

                    $stmt->close();

                    /*
                    |--------------------------------------------------------------------------
                    | CLOSE SCHOOL YEAR
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $conn->prepare(
                        "UPDATE school_years
                         SET status = 'closed',
                             closed_at = NOW()
                         WHERE id = ?
                         AND status = 'active'"
                    );

                    if (!$stmt) {
                        throw new Exception("Database query failed.");
                    }

                    $stmt->bind_param(
                        "i",
                        $school_year_id
                    );

                    if (!$stmt->execute()) {
                        throw new Exception("Unable to close school year.");
                    }

                    if ($stmt->affected_rows !== 1) {
                        $stmt->close();

                        throw new Exception(
                            "School year could not be closed."
                        );
                    }

                    $stmt->close();

                    /*
                    |--------------------------------------------------------------------------
                    | ACTIVITY LOG
                    |--------------------------------------------------------------------------
                    */

                    $admin_id = (int) $_SESSION["user_id"];

                    $action_text =
                        "Closed school year " .
                        $school_year["school_year"];

                    $ip_address =
                        $_SERVER["REMOTE_ADDR"] ?? "UNKNOWN";

                    $log_stmt = $conn->prepare(
                        "INSERT INTO activity_logs
                        (
                            user_id,
                            action,
                            ip_address
                        )
                        VALUES (?, ?, ?)"
                    );

                    if (!$log_stmt) {
                        throw new Exception("Unable to write activity log.");
                    }

                    $log_stmt->bind_param(
                        "iss",
                        $admin_id,
                        $action_text,
                        $ip_address
                    );

                    if (!$log_stmt->execute()) {
                        $log_stmt->close();

                        throw new Exception("Unable to write activity log.");
                    }

                    $log_stmt->close();

                    $conn->commit();

                    $message =
                        "School year " .
                        $school_year["school_year"] .
                        " has been closed successfully.";

                    $message_type = "success";
                }

            } catch (Throwable $e) {

                if ($conn->in_transaction) {
                    $conn->rollback();
                }

                error_log(
                    "School year close error: " .
                    $e->getMessage()
                );

                $message =
                    "Unable to close school year.";

                $message_type = "error";
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE NEW SCHOOL YEAR
    |--------------------------------------------------------------------------
    */

    elseif ($action === "create") {

        $new_school_year =
            trim($_POST["school_year"] ?? "");

        /*
        |--------------------------------------------------------------------------
        | VALIDATE INPUT
        |--------------------------------------------------------------------------
        */

        if ($new_school_year === "") {

            $message =
                "Please enter a school year.";

            $message_type = "error";

        } elseif (
            !preg_match(
                '/^[0-9]{4}-[0-9]{4}$/',
                $new_school_year
            )
        ) {

            $message =
                "Invalid format. Please use YYYY-YYYY, example: 2026-2027.";

            $message_type = "error";

        } else {

            $parts = explode(
                "-",
                $new_school_year
            );

            $start_year = (int) $parts[0];
            $end_year = (int) $parts[1];

            /*
            |--------------------------------------------------------------------------
            | END YEAR MUST BE EXACTLY ONE YEAR AFTER START YEAR
            |--------------------------------------------------------------------------
            */

            if ($end_year !== ($start_year + 1)) {

                $message =
                    "Invalid school year. " .
                    "The ending year must be exactly one year " .
                    "after the starting year. " .
                    "Example: 2026-2027.";

                $message_type = "error";

            } else {

                try {

                    $conn->begin_transaction();

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK IF SCHOOL YEAR ALREADY EXISTS
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $conn->prepare(
                        "SELECT id, status
                         FROM school_years
                         WHERE school_year = ?
                         LIMIT 1
                         FOR UPDATE"
                    );

                    if (!$stmt) {
                        throw new Exception("Database query failed.");
                    }

                    $stmt->bind_param(
                        "s",
                        $new_school_year
                    );

                    if (!$stmt->execute()) {
                        throw new Exception("Database query failed.");
                    }

                    $result = $stmt->get_result();

                    if ($result->num_rows === 1) {

                        $existing = $result->fetch_assoc();

                        $stmt->close();
                        $conn->rollback();

                        if ($existing["status"] === "active") {

                            $message =
                                "School year " .
                                $new_school_year .
                                " is already active.";

                        } else {

                            $message =
                                "School year " .
                                $new_school_year .
                                " already exists and is closed.";
                        }

                        $message_type = "error";

                    } else {

                        $stmt->close();

                        /*
                        |--------------------------------------------------------------------------
                        | CHECK CURRENT ACTIVE SCHOOL YEAR
                        |--------------------------------------------------------------------------
                        */

                        $active_stmt = $conn->prepare(
                            "SELECT id, school_year
                             FROM school_years
                             WHERE status = 'active'
                             ORDER BY id DESC
                             LIMIT 1
                             FOR UPDATE"
                        );

                        if (!$active_stmt) {
                            throw new Exception(
                                "Database query failed."
                            );
                        }

                        if (!$active_stmt->execute()) {
                            throw new Exception(
                                "Database query failed."
                            );
                        }

                        $active_result =
                            $active_stmt->get_result();

                        if ($active_result->num_rows > 0) {

                            $active_row =
                                $active_result->fetch_assoc();

                            $active_stmt->close();
                            $conn->rollback();

                            $message =
                                "There is still an active school year (" .
                                $active_row["school_year"] .
                                "). Please close the current school year first.";

                            $message_type = "error";

                        } else {

                            $active_stmt->close();

                            /*
                            |--------------------------------------------------------------------------
                            | GET PREVIOUS SCHOOL YEAR
                            |--------------------------------------------------------------------------
                            */

                            $previous_stmt = $conn->prepare(
                                "SELECT id, school_year
                                 FROM school_years
                                 ORDER BY CAST(
                                     SUBSTRING_INDEX(
                                         school_year,
                                         '-',
                                         1
                                     ) AS UNSIGNED
                                 ) DESC
                                 LIMIT 1
                                 FOR UPDATE"
                            );

                            if (!$previous_stmt) {
                                throw new Exception(
                                    "Database query failed."
                                );
                            }

                            if (!$previous_stmt->execute()) {
                                throw new Exception(
                                    "Database query failed."
                                );
                            }

                            $previous_result =
                                $previous_stmt->get_result();

                            $valid_sequence = true;

                            if ($previous_result->num_rows === 1) {

                                $previous_row =
                                    $previous_result->fetch_assoc();

                                $previous_school_year =
                                    $previous_row["school_year"];

                                if (
                                    !preg_match(
                                        '/^[0-9]{4}-[0-9]{4}$/',
                                        $previous_school_year
                                    )
                                ) {

                                    $valid_sequence = false;

                                    $message =
                                        "The previous school year has an invalid format.";

                                    $message_type = "error";

                                } else {

                                    $previous_parts =
                                        explode(
                                            "-",
                                            $previous_school_year
                                        );

                                    $previous_start =
                                        (int) $previous_parts[0];

                                    $previous_end =
                                        (int) $previous_parts[1];

                                    /*
                                    |--------------------------------------------------------------------------
                                    | NEW YEAR MUST START WHERE PREVIOUS YEAR ENDED
                                    |--------------------------------------------------------------------------
                                    */

                                    if (
                                        $start_year !==
                                        $previous_end
                                    ) {

                                        $valid_sequence = false;

                                        $message =
                                            "Invalid school year sequence. " .
                                            "The previous school year is " .
                                            $previous_school_year .
                                            ". The next school year must start at " .
                                            $previous_end .
                                            ". Example: " .
                                            $previous_end .
                                            "-" .
                                            ($previous_end + 1) .
                                            ".";

                                        $message_type = "error";
                                    }
                                }
                            }

                            $previous_stmt->close();

                            /*
                            |--------------------------------------------------------------------------
                            | CREATE NEW SCHOOL YEAR
                            |--------------------------------------------------------------------------
                            */

                            if ($valid_sequence) {

                                $stmt = $conn->prepare(
                                    "INSERT INTO school_years
                                    (
                                        school_year,
                                        status
                                    )
                                    VALUES (?, 'active')"
                                );

                                if (!$stmt) {
                                    throw new Exception(
                                        "Database query failed."
                                    );
                                }

                                $stmt->bind_param(
                                    "s",
                                    $new_school_year
                                );

                                if (!$stmt->execute()) {

                                    /*
                                    |----------------------------------------------------------------------
                                    | Handle duplicate/race condition without exposing DB details
                                    |----------------------------------------------------------------------
                                    */

                                    $stmt->close();

                                    throw new Exception(
                                        "Unable to create school year."
                                    );
                                }

                                $stmt->close();

                                /*
                                |--------------------------------------------------------------------------
                                | ACTIVITY LOG
                                |--------------------------------------------------------------------------
                                */

                                $admin_id =
                                    (int) $_SESSION["user_id"];

                                $action_text =
                                    "Created new school year " .
                                    $new_school_year;

                                $ip_address =
                                    $_SERVER["REMOTE_ADDR"] ?? "UNKNOWN";

                                $log_stmt = $conn->prepare(
                                    "INSERT INTO activity_logs
                                    (
                                        user_id,
                                        action,
                                        ip_address
                                    )
                                    VALUES (?, ?, ?)"
                                );

                                if (!$log_stmt) {
                                    throw new Exception(
                                        "Unable to write activity log."
                                    );
                                }

                                $log_stmt->bind_param(
                                    "iss",
                                    $admin_id,
                                    $action_text,
                                    $ip_address
                                );

                                if (!$log_stmt->execute()) {
                                    $log_stmt->close();

                                    throw new Exception(
                                        "Unable to write activity log."
                                    );
                                }

                                $log_stmt->close();

                                $conn->commit();

                                $message =
                                    "School year " .
                                    $new_school_year .
                                    " created successfully.";

                                $message_type = "success";
                            } else {

                                $conn->rollback();
                            }
                        }
                    }

                } catch (Throwable $e) {

                    if ($conn->in_transaction) {
                        $conn->rollback();
                    }

                    error_log(
                        "School year creation error: " .
                        $e->getMessage()
                    );

                    $message =
                        "Unable to create school year.";

                    $message_type = "error";
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INVALID ACTION
    |--------------------------------------------------------------------------
    */

    else {

        $message = "Invalid request.";
        $message_type = "error";
    }
}

/*
|--------------------------------------------------------------------------
| GET CURRENT ACTIVE SCHOOL YEAR
|--------------------------------------------------------------------------
*/

$active_school_year = null;

$result = $conn->query(
    "SELECT
        id,
        school_year,
        created_at
     FROM school_years
     WHERE status = 'active'
     ORDER BY id DESC
     LIMIT 1"
);

if (
    $result &&
    $result->num_rows === 1
) {
    $active_school_year =
        $result->fetch_assoc();
}

/*
|--------------------------------------------------------------------------
| GET SCHOOL YEAR HISTORY
|--------------------------------------------------------------------------
*/

$history_result = $conn->query(
    "SELECT
        id,
        school_year,
        status,
        created_at,
        closed_at
     FROM school_years
     ORDER BY id DESC"
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>School Year Management</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1f2937;
        }

        .container {
            width: 92%;
            max-width: 1100px;
            margin: 40px auto;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }

        .top-bar h1 {
            margin: 0;
            font-size: 28px;
        }

        .back-button {
            text-decoration: none;
            background: #374151;
            color: white;
            padding: 11px 18px;
            border-radius: 8px;
            font-size: 14px;
        }

        .back-button:hover {
            background: #1f2937;
        }

        .message {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 25px;
            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.07);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 18px;
            font-size: 20px;
        }

        .current-year {
            text-align: center;
            padding: 25px 10px;
        }

        .current-year .label {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .current-year .year {
            font-size: 34px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .active-badge {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
        }

        .close-form {
            margin-top: 20px;
        }

        .close-button {
            width: 100%;
            border: none;
            background: #dc2626;
            color: white;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
        }

        .close-button:hover {
            background: #b91c1c;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2563eb;
        }

        .create-button {
            width: 100%;
            border: none;
            background: #2563eb;
            color: white;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
        }

        .create-button:hover {
            background: #1d4ed8;
        }

        .help-text {
            margin-top: 10px;
            font-size: 13px;
            color: #6b7280;
        }

        .history-card {
            background: white;
            border-radius: 14px;
            padding: 25px;
            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.07);
        }

        .history-card h2 {
            margin-top: 0;
            margin-bottom: 20px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th,
        td {
            padding: 13px 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f9fafb;
            font-weight: bold;
        }

        tr:hover td {
            background: #f9fafb;
        }

        .status {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status.active {
            background: #dcfce7;
            color: #166534;
        }

        .status.closed {
            background: #e5e7eb;
            color: #4b5563;
        }

        .no-year {
            text-align: center;
            padding: 30px;
            color: #6b7280;
        }

        @media (max-width: 750px) {

            .container {
                width: 94%;
                margin: 25px auto;
            }

            .top-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .top-bar h1 {
                font-size: 24px;
            }

            .back-button {
                text-align: center;
            }

            .grid {
                grid-template-columns: 1fr;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <div class="top-bar">

        <h1>
            📅 School Year Management
        </h1>

        <a
            href="dashboard.php"
            class="back-button"
        >
            ← Back to Dashboard
        </a>

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

    <div class="grid">

        <!-- CURRENT SCHOOL YEAR -->

        <div class="card">

            <h2>
                Current School Year
            </h2>

            <?php if ($active_school_year): ?>

                <div class="current-year">

                    <div class="label">
                        Active School Year
                    </div>

                    <div class="year">

                        <?= htmlspecialchars(
                            $active_school_year["school_year"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </div>

                    <span class="active-badge">
                        ACTIVE
                    </span>

                    <form
                        method="POST"
                        class="close-form"
                        onsubmit="return confirm(
                            'Are you sure you want to close this school year?'
                        );"
                    >

                        <?= csrf_field() ?>

                        <input
                            type="hidden"
                            name="action"
                            value="close"
                        >

                        <input
                            type="hidden"
                            name="school_year_id"
                            value="<?= (int) $active_school_year["id"] ?>"
                        >

                        <button
                            type="submit"
                            class="close-button"
                        >
                            🔒 Close School Year
                        </button>

                    </form>

                </div>

            <?php else: ?>

                <div class="no-year">

                    <p>
                        No active school year.
                    </p>

                    <p>
                        Create a new school year using
                        the form beside this card.
                    </p>

                </div>

            <?php endif; ?>

        </div>

        <!-- CREATE NEW SCHOOL YEAR -->

        <div class="card">

            <h2>
                Start New School Year
            </h2>

            <form method="POST">

                <?= csrf_field() ?>

                <input
                    type="hidden"
                    name="action"
                    value="create"
                >

                <div class="form-group">

                    <label for="school_year">
                        School Year
                    </label>

                    <input
                        type="text"
                        id="school_year"
                        name="school_year"
                        placeholder="Example: 2026-2027"
                        pattern="[0-9]{4}-[0-9]{4}"
                        maxlength="9"
                        inputmode="numeric"
                        required
                    >

                </div>

                <button
                    type="submit"
                    class="create-button"
                >
                    ➕ Start New School Year
                </button>

                <div class="help-text">

                    School years must follow the correct
                    sequence.

                    Example:
                    2025-2026 → 2026-2027 → 2027-2028.

                </div>

            </form>

        </div>

    </div>

    <!-- SCHOOL YEAR HISTORY -->

    <div class="history-card">

        <h2>
            📜 School Year History
        </h2>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>School Year</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Closed</th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (
                        $history_result &&
                        $history_result->num_rows > 0
                    ): ?>

                        <?php while (
                            $row =
                            $history_result->fetch_assoc()
                        ): ?>

                            <tr>

                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $row["school_year"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </strong>

                                </td>

                                <td>

                                    <span
                                        class="status <?= htmlspecialchars(
                                            $row["status"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>"
                                    >

                                        <?= htmlspecialchars(
                                            strtoupper(
                                                $row["status"]
                                            ),
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        $row["created_at"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </td>

                                <td>

                                    <?php if (
                                        !empty($row["closed_at"])
                                    ): ?>

                                        <?= htmlspecialchars(
                                            $row["closed_at"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="4"
                                class="no-year"
                            >
                                No school year records found.
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>

</html>
