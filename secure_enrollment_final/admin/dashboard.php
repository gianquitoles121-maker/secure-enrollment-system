<?php

session_start();

require_once "../config/database.php";
require_once "../config/csrf.php";


/* ==========================================================
   SECURITY HEADERS
   ========================================================== */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");


/* ==========================================================
   ADMIN ACCESS
   ========================================================== */

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}


/* ==========================================================
   SEARCH AND FILTERS
   ========================================================== */

$search = trim($_GET["search"] ?? "");

$status_filter = $_GET["status"] ?? "";

$payment_filter = $_GET["payment"] ?? "";

$course_filter = trim($_GET["course"] ?? "");


/* Limit search length */

if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}


/* ==========================================================
   VALID FILTER VALUES
   ========================================================== */

$allowed_statuses = [
    "",
    "pending",
    "approved",
    "rejected",
    "cancelled"
];

$allowed_payment_statuses = [
    "",
    "paid",
    "unpaid"
];


if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = "";
}


if (!in_array($payment_filter, $allowed_payment_statuses, true)) {
    $payment_filter = "";
}


/* ==========================================================
   VALID COURSE FILTER
   ========================================================== */

if ($course_filter !== "") {

    if (!ctype_digit($course_filter)) {

        $course_filter = "";

    } else {

        $course_id_check = (int) $course_filter;

        if ($course_id_check <= 0) {

            $course_filter = "";

        } else {

            $course_check = $conn->prepare(
                "SELECT id
                 FROM courses
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($course_check) {

                $course_check->bind_param(
                    "i",
                    $course_id_check
                );

                $course_check->execute();

                $course_result =
                    $course_check->get_result();

                if ($course_result->num_rows === 0) {
                    $course_filter = "";
                }

                $course_check->close();

            } else {

                $course_filter = "";
            }
        }
    }
}


/* ==========================================================
   HANDLE ADMIN ACTION
   ========================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verify_csrf_token();


    $enrollment_id =
        $_POST["enrollment_id"] ?? "";

    $action =
        $_POST["action"] ?? "";


    /* ======================================================
       VALIDATE ENROLLMENT ID
       ====================================================== */

    if (
        $enrollment_id === "" ||
        filter_var(
            $enrollment_id,
            FILTER_VALIDATE_INT
        ) === false ||
        (int) $enrollment_id <= 0
    ) {

        $_SESSION["admin_message"] =
            "Invalid enrollment ID.";

        header("Location: dashboard.php");
        exit;
    }


    $enrollment_id =
        (int) $enrollment_id;


    /* ======================================================
       VALID ACTION
       ====================================================== */

    $allowed_actions = [
        "approve_enrollment",
        "reject_enrollment",
        "verify_payment",
        "reject_payment"
    ];


    if (
        !in_array(
            $action,
            $allowed_actions,
            true
        )
    ) {

        $_SESSION["admin_message"] =
            "Invalid administrative action.";

        header("Location: dashboard.php");
        exit;
    }


    /*
     * Used only after a successful transaction.
     * If payment proof is rejected, the database record
     * is cleared first and the physical file is deleted
     * after commit.
     */

    $file_to_delete = null;


    /* ======================================================
       TRANSACTION
       ====================================================== */

    try {

        $conn->begin_transaction();


        /* ==================================================
           LOCK ENROLLMENT ROW
           ================================================== */

        $stmt = $conn->prepare(
            "SELECT
                e.id,
                e.status,
                e.payment_status,
                e.payment_proof,
                e.student_id,
                s.user_id
             FROM enrollments e

             INNER JOIN students s
                ON e.student_id = s.id

             WHERE e.id = ?

             FOR UPDATE"
        );


        if (!$stmt) {
            throw new Exception(
                "Unable to prepare enrollment query."
            );
        }


        $stmt->bind_param(
            "i",
            $enrollment_id
        );


        if (!$stmt->execute()) {

            $stmt->close();

            throw new Exception(
                "Unable to load enrollment."
            );
        }


        $result =
            $stmt->get_result();


        if ($result->num_rows !== 1) {

            $stmt->close();

            throw new Exception(
                "Enrollment not found."
            );
        }


        $enrollment =
            $result->fetch_assoc();


        $stmt->close();


        $current_status =
            $enrollment["status"];

        $payment_status =
            $enrollment["payment_status"];

        $payment_proof =
            $enrollment["payment_proof"];

        $student_user_id =
            (int) $enrollment["user_id"];


        /* ==================================================
           APPROVE ENROLLMENT
           ================================================== */

        if ($action === "approve_enrollment") {


            if ($current_status !== "pending") {

                throw new Exception(
                    "This enrollment is no longer pending."
                );
            }


            $stmt = $conn->prepare(
                "UPDATE enrollments
                 SET status = 'approved'
                 WHERE id = ?
                 AND status = 'pending'"
            );


            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare enrollment approval."
                );
            }


            $stmt->bind_param(
                "i",
                $enrollment_id
            );


            if (!$stmt->execute()) {

                $stmt->close();

                throw new Exception(
                    "Unable to approve enrollment."
                );
            }


            if ($stmt->affected_rows !== 1) {

                $stmt->close();

                throw new Exception(
                    "Enrollment could not be approved."
                );
            }


            $stmt->close();


            /* ==============================================
               NOTIFICATION
               ============================================== */

            $notification_message =
                "Your enrollment application has been approved. Please upload your payment proof to complete your payment verification.";


            $notification = $conn->prepare(
                "INSERT INTO notifications
                 (user_id, message)
                 VALUES (?, ?)"
            );


            if (!$notification) {
                throw new Exception(
                    "Unable to prepare notification."
                );
            }


            $notification->bind_param(
                "is",
                $student_user_id,
                $notification_message
            );


            if (!$notification->execute()) {

                $notification->close();

                throw new Exception(
                    "Unable to notify student."
                );
            }


            $notification->close();


            /* ==============================================
               ACTIVITY LOG
               ============================================== */

            $action_log =
                "Approved enrollment #" .
                $enrollment_id;


            $ip_address =
                $_SERVER["REMOTE_ADDR"] ??
                "UNKNOWN";


            $log = $conn->prepare(
                "INSERT INTO activity_logs
                 (user_id, action, ip_address)
                 VALUES (?, ?, ?)"
            );


            if (!$log) {
                throw new Exception(
                    "Unable to prepare activity log."
                );
            }


            $log->bind_param(
                "iss",
                $_SESSION["user_id"],
                $action_log,
                $ip_address
            );


            if (!$log->execute()) {

                $log->close();

                throw new Exception(
                    "Unable to save activity log."
                );
            }


            $log->close();


            $_SESSION["admin_message"] =
                "Enrollment approved and student notified.";
        }


        /* ==================================================
           REJECT ENROLLMENT
           ================================================== */

        elseif ($action === "reject_enrollment") {


            if ($current_status !== "pending") {

                throw new Exception(
                    "This enrollment is no longer pending."
                );
            }


            $stmt = $conn->prepare(
                "UPDATE enrollments
                 SET status = 'rejected'
                 WHERE id = ?
                 AND status = 'pending'"
            );


            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare enrollment rejection."
                );
            }


            $stmt->bind_param(
                "i",
                $enrollment_id
            );


            if (!$stmt->execute()) {

                $stmt->close();

                throw new Exception(
                    "Unable to reject enrollment."
                );
            }


            if ($stmt->affected_rows !== 1) {

                $stmt->close();

                throw new Exception(
                    "Enrollment could not be rejected."
                );
            }


            $stmt->close();


            /* ==============================================
               NOTIFICATION
               ============================================== */

            $notification_message =
                "Your enrollment application has been rejected. Please review your enrollment details and submit a new application if necessary.";


            $notification = $conn->prepare(
                "INSERT INTO notifications
                 (user_id, message)
                 VALUES (?, ?)"
            );


            if (!$notification) {
                throw new Exception(
                    "Unable to prepare notification."
                );
            }


            $notification->bind_param(
                "is",
                $student_user_id,
                $notification_message
            );


            if (!$notification->execute()) {

                $notification->close();

                throw new Exception(
                    "Unable to notify student."
                );
            }


            $notification->close();


            /* ==============================================
               ACTIVITY LOG
               ============================================== */

            $action_log =
                "Rejected enrollment #" .
                $enrollment_id;


            $ip_address =
                $_SERVER["REMOTE_ADDR"] ??
                "UNKNOWN";


            $log = $conn->prepare(
                "INSERT INTO activity_logs
                 (user_id, action, ip_address)
                 VALUES (?, ?, ?)"
            );


            if (!$log) {
                throw new Exception(
                    "Unable to prepare activity log."
                );
            }


            $log->bind_param(
                "iss",
                $_SESSION["user_id"],
                $action_log,
                $ip_address
            );


            if (!$log->execute()) {

                $log->close();

                throw new Exception(
                    "Unable to save activity log."
                );
            }


            $log->close();


            $_SESSION["admin_message"] =
                "Enrollment rejected and student notified.";
        }


        /* ==================================================
           VERIFY PAYMENT
           ================================================== */

        elseif ($action === "verify_payment") {


            if ($current_status !== "approved") {

                throw new Exception(
                    "Payment cannot be verified because the enrollment is not approved."
                );
            }


            if (empty($payment_proof)) {

                throw new Exception(
                    "Cannot verify payment. Student has not uploaded payment proof."
                );
            }


            if ($payment_status === "paid") {

                throw new Exception(
                    "This payment has already been verified."
                );
            }


            $stmt = $conn->prepare(
                "UPDATE enrollments
                 SET payment_status = 'paid',
                     payment_date = NOW()
                 WHERE id = ?
                 AND status = 'approved'
                 AND payment_status = 'unpaid'
                 AND payment_proof IS NOT NULL
                 AND payment_proof != ''"
            );


            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare payment verification."
                );
            }


            $stmt->bind_param(
                "i",
                $enrollment_id
            );


            if (!$stmt->execute()) {

                $stmt->close();

                throw new Exception(
                    "Unable to verify payment."
                );
            }


            if ($stmt->affected_rows !== 1) {

                $stmt->close();

                throw new Exception(
                    "Payment could not be verified."
                );
            }


            $stmt->close();


            /* ==============================================
               NOTIFICATION
               ============================================== */

            $notification_message =
                "Your payment has been verified successfully. Your enrollment is now complete.";


            $notification = $conn->prepare(
                "INSERT INTO notifications
                 (user_id, message)
                 VALUES (?, ?)"
            );


            if (!$notification) {
                throw new Exception(
                    "Unable to prepare notification."
                );
            }


            $notification->bind_param(
                "is",
                $student_user_id,
                $notification_message
            );


            if (!$notification->execute()) {

                $notification->close();

                throw new Exception(
                    "Unable to notify student."
                );
            }


            $notification->close();


            /* ==============================================
               ACTIVITY LOG
               ============================================== */

            $action_log =
                "Verified payment for enrollment #" .
                $enrollment_id;


            $ip_address =
                $_SERVER["REMOTE_ADDR"] ??
                "UNKNOWN";


            $log = $conn->prepare(
                "INSERT INTO activity_logs
                 (user_id, action, ip_address)
                 VALUES (?, ?, ?)"
            );


            if (!$log) {
                throw new Exception(
                    "Unable to prepare activity log."
                );
            }


            $log->bind_param(
                "iss",
                $_SESSION["user_id"],
                $action_log,
                $ip_address
            );


            if (!$log->execute()) {

                $log->close();

                throw new Exception(
                    "Unable to save activity log."
                );
            }


            $log->close();


            $_SESSION["admin_message"] =
                "Payment verified and student notified.";
        }


        /* ==================================================
           REJECT PAYMENT PROOF
           ================================================== */

        elseif ($action === "reject_payment") {


            if ($current_status !== "approved") {

                throw new Exception(
                    "Payment can only be rejected for an approved enrollment."
                );
            }


            if (empty($payment_proof)) {

                throw new Exception(
                    "There is no payment proof to reject."
                );
            }


            if ($payment_status === "paid") {

                throw new Exception(
                    "This payment has already been verified and cannot be rejected."
                );
            }


            /*
             * Save the filename before clearing the database.
             */

            $file_to_delete =
                basename($payment_proof);


            $stmt = $conn->prepare(
                "UPDATE enrollments
                 SET payment_status = 'unpaid',
                     payment_date = NULL,
                     payment_proof = NULL
                 WHERE id = ?
                 AND status = 'approved'
                 AND payment_status = 'unpaid'
                 AND payment_proof IS NOT NULL
                 AND payment_proof != ''"
            );


            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare payment rejection."
                );
            }


            $stmt->bind_param(
                "i",
                $enrollment_id
            );


            if (!$stmt->execute()) {

                $stmt->close();

                throw new Exception(
                    "Unable to reject payment proof."
                );
            }


            if ($stmt->affected_rows !== 1) {

                $stmt->close();

                throw new Exception(
                    "Payment proof could not be rejected."
                );
            }


            $stmt->close();


            /* ==============================================
               NOTIFICATION
               ============================================== */

            $notification_message =
                "Your payment proof was rejected. Please upload a valid payment proof to complete your enrollment.";


            $notification = $conn->prepare(
                "INSERT INTO notifications
                 (user_id, message)
                 VALUES (?, ?)"
            );


            if (!$notification) {
                throw new Exception(
                    "Unable to prepare notification."
                );
            }


            $notification->bind_param(
                "is",
                $student_user_id,
                $notification_message
            );


            if (!$notification->execute()) {

                $notification->close();

                throw new Exception(
                    "Unable to notify student."
                );
            }


            $notification->close();


            /* ==============================================
               ACTIVITY LOG
               ============================================== */

            $action_log =
                "Rejected payment proof for enrollment #" .
                $enrollment_id;


            $ip_address =
                $_SERVER["REMOTE_ADDR"] ??
                "UNKNOWN";


            $log = $conn->prepare(
                "INSERT INTO activity_logs
                 (user_id, action, ip_address)
                 VALUES (?, ?, ?)"
            );


            if (!$log) {
                throw new Exception(
                    "Unable to prepare activity log."
                );
            }


            $log->bind_param(
                "iss",
                $_SESSION["user_id"],
                $action_log,
                $ip_address
            );


            if (!$log->execute()) {

                $log->close();

                throw new Exception(
                    "Unable to save activity log."
                );
            }


            $log->close();


            $_SESSION["admin_message"] =
                "Payment proof rejected and student notified.";
        }


        /* ==================================================
           COMMIT
           ================================================== */

        $conn->commit();


        /* ==================================================
           DELETE REJECTED PAYMENT PROOF FILE
           AFTER SUCCESSFUL COMMIT
           ================================================== */

        if (
            $action === "reject_payment" &&
            !empty($file_to_delete)
        ) {

            $upload_directory =
                realpath("../uploads");


            if (
                $upload_directory !== false &&
                is_dir($upload_directory)
            ) {

                $file_path =
                    $upload_directory .
                    DIRECTORY_SEPARATOR .
                    $file_to_delete;


                /*
                 * Only delete regular files inside
                 * the uploads directory.
                 */

                if (
                    is_file($file_path) &&
                    is_writable($file_path)
                ) {

                    @unlink($file_path);
                }
            }
        }


    } catch (Throwable $e) {


        /* ==================================================
           ROLLBACK
           ================================================== */

        $conn->rollback();


        error_log(
            "Admin enrollment action error: " .
            $e->getMessage()
        );


        $_SESSION["admin_message"] =
            "Unable to complete the requested action. " .
            $e->getMessage();
    }


    /* ======================================================
       PRG REDIRECT
       ====================================================== */

    header("Location: dashboard.php");
    exit;
}


/* ==========================================================
   MESSAGE
   ========================================================== */

$message = "";

if (isset($_SESSION["admin_message"])) {

    $message =
        $_SESSION["admin_message"];

    unset(
        $_SESSION["admin_message"]
    );
}


/* ==========================================================
   ACTIVE SCHOOL YEAR
   ========================================================== */

$active_school_year_id = 0;

$active_school_year = "";


$active_sy_result = $conn->query(
    "SELECT id, school_year
     FROM school_years
     WHERE status = 'active'
     ORDER BY id DESC
     LIMIT 1"
);


if (
    $active_sy_result &&
    $active_sy_result->num_rows === 1
) {

    $active_sy =
        $active_sy_result->fetch_assoc();

    $active_school_year_id =
        (int) $active_sy["id"];

    $active_school_year =
        $active_sy["school_year"];
}


/* ==========================================================
   DASHBOARD STATISTICS
   ========================================================== */


/* TOTAL STUDENTS */

$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT student_id) AS total
     FROM enrollments
     WHERE school_year_id = ?
     AND status = 'approved'"
);

$stmt->bind_param(
    "i",
    $active_school_year_id
);

$stmt->execute();

$total_students =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* TOTAL ENROLLMENTS */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM enrollments
     WHERE school_year_id = ?"
);

$stmt->bind_param(
    "i",
    $active_school_year_id
);

$stmt->execute();

$total_enrollments =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* PENDING */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM enrollments
     WHERE school_year_id = ?
     AND status = 'pending'"
);

$stmt->bind_param(
    "i",
    $active_school_year_id
);

$stmt->execute();

$pending =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* APPROVED */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM enrollments
     WHERE school_year_id = ?
     AND status = 'approved'"
);

$stmt->bind_param(
    "i",
    $active_school_year_id
);

$stmt->execute();

$approved =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* REJECTED */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM enrollments
     WHERE school_year_id = ?
     AND status = 'rejected'"
);

$stmt->bind_param(
    "i",
    $active_school_year_id
);

$stmt->execute();

$rejected =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* CANCELLED */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM enrollments
     WHERE school_year_id = ?
     AND status = 'cancelled'"
);

$stmt->bind_param(
    "i",
    $active_school_year_id
);

$stmt->execute();

$cancelled =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* PAID */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM enrollments
     WHERE school_year_id = ?
     AND payment_status = 'paid'"
);

$stmt->bind_param(
    "i",
    $active_school_year_id
);

$stmt->execute();

$paid =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* UNPAID */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM enrollments
     WHERE school_year_id = ?
     AND payment_status = 'unpaid'"
);

$stmt->bind_param(
    "i",
    $active_school_year_id
);

$stmt->execute();

$unpaid =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* PENDING PASSWORD RESET */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM password_reset_requests
     WHERE status = 'pending'"
);

$stmt->execute();

$pending_password_resets =
    $stmt->get_result()->fetch_assoc()["total"];

$stmt->close();


/* ==========================================================
   COURSE LIST FOR FILTER
   ========================================================== */

$courses = $conn->query(
    "SELECT
        id,
        course_code,
        course_name
     FROM courses
     ORDER BY course_code ASC"
);


/* ==========================================================
   GET ENROLLMENTS
   ========================================================== */

$sql = "
    SELECT
        e.id,
        e.year_level,
        e.semester,
        e.status,
        e.payment_status,
        e.payment_date,
        e.payment_proof,
        e.enrollment_date,
        e.student_id,

        s.student_number,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.email,

        u.username,

        c.course_code,
        c.course_name

    FROM enrollments e

    INNER JOIN students s
        ON e.student_id = s.id

    INNER JOIN users u
        ON s.user_id = u.id

    INNER JOIN courses c
        ON e.course_id = c.id

    WHERE e.school_year_id = ?
";


$params = [
    $active_school_year_id
];

$types = "i";


/* ==========================================================
   SEARCH
   ========================================================== */

if ($search !== "") {

    $sql .= "
        AND (
            s.student_number LIKE ?
            OR s.first_name LIKE ?
            OR s.middle_name LIKE ?
            OR s.last_name LIKE ?
            OR s.email LIKE ?
            OR u.username LIKE ?
            OR c.course_code LIKE ?
            OR c.course_name LIKE ?
        )
    ";


    $search_value =
        "%" . $search . "%";


    for (
        $i = 0;
        $i < 8;
        $i++
    ) {

        $params[] =
            $search_value;

        $types .= "s";
    }
}


/* ==========================================================
   ENROLLMENT STATUS FILTER
   ========================================================== */

if ($status_filter !== "") {

    $sql .= "
        AND e.status = ?
    ";


    $params[] =
        $status_filter;

    $types .= "s";
}


/* ==========================================================
   PAYMENT STATUS FILTER
   ========================================================== */

if ($payment_filter !== "") {

    $sql .= "
        AND e.payment_status = ?
    ";


    $params[] =
        $payment_filter;

    $types .= "s";
}


/* ==========================================================
   COURSE FILTER
   ========================================================== */

if ($course_filter !== "") {

    $sql .= "
        AND e.course_id = ?
    ";


    $params[] =
        (int) $course_filter;

    $types .= "i";
}


$sql .= "
    ORDER BY e.id DESC
";


$stmt = $conn->prepare($sql);


if (!$stmt) {
    die("Unable to load enrollment records.");
}


/* ==========================================================
   BIND PARAMETERS
   ========================================================== */

$bind_values = [];

$bind_values[] =
    $types;


foreach (
    $params
    as $key => $value
) {

    $bind_values[] =
        &$params[$key];
}


call_user_func_array(
    [$stmt, "bind_param"],
    $bind_values
);


if (!$stmt->execute()) {

    $stmt->close();

    die("Unable to load enrollment records.");
}


$enrollments =
    $stmt->get_result();

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
        Admin Dashboard | Secure Enrollment
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
                #f1f5f9;

            color:
                #1e293b;
        }


        /* NAVBAR */

        .navbar {
            background:
                #1e3a8a;

            color:
                white;

            padding:
                18px 30px;

            display:
                flex;

            justify-content:
                space-between;

            align-items:
                center;
        }

        .navbar h2 {
            margin: 0;
        }

        .logout {
            color:
                white;

            text-decoration:
                none;

            background:
                #dc2626;

            padding:
                9px 15px;

            border-radius:
                7px;
        }

        .logout:hover {
            background:
                #b91c1c;
        }


        /* CONTAINER */

        .container {
            max-width:
                1250px;

            margin:
                35px auto;

            padding:
                0 20px;
        }


        /* WELCOME */

        .welcome {
            margin-bottom:
                25px;
        }

        .welcome h1 {
            margin-bottom:
                5px;
        }

        .welcome p {
            color:
                #64748b;
        }


        /* MESSAGE */

        .message {
            background:
                #dcfce7;

            color:
                #166534;

            padding:
                13px;

            border-radius:
                8px;

            margin-bottom:
                20px;
        }


        /* QUICK ACTIONS */

        .quick-actions {
            display:
                grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(230px, 1fr)
                );

            gap:
                18px;

            margin-bottom:
                30px;
        }

        .quick-card {
            background:
                white;

            padding:
                22px;

            border-radius:
                12px;

            text-decoration:
                none;

            color:
                #1e293b;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.08);

            transition:
                0.2s;
        }

        .quick-card:hover {
            transform:
                translateY(-2px);

            box-shadow:
                0 8px 25px
                rgba(0, 0, 0, 0.12);
        }

        .quick-card h3 {
            margin:
                0 0 8px 0;

            color:
                #1d4ed8;
        }

        .quick-card p {
            margin:
                0;

            color:
                #64748b;

            font-size:
                14px;

            line-height:
                1.5;
        }

        .reset-card {
            border-left:
                5px solid
                #dc2626;
        }

        .reset-card h3 {
            color:
                #dc2626;
        }

        .reset-count {
            display:
                inline-block;

            margin-top:
                12px;

            background:
                #fee2e2;

            color:
                #991b1b;

            padding:
                5px 10px;

            border-radius:
                20px;

            font-size:
                12px;

            font-weight:
                bold;
        }


        /* SUPPORT CARD */

        .support-card {
            border-left:
                5px solid
                #2563eb;
        }

        .support-card h3 {
            color:
                #2563eb;
        }


        /* STATISTICS */

        .stats {
            display:
                grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(200px, 1fr)
                );

            gap:
                18px;

            margin-bottom:
                30px;
        }

        .stat-card {
            background:
                white;

            padding:
                22px;

            border-radius:
                12px;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.08);

            transition:
                0.2s;
        }

        .stat-card:hover {
            transform:
                translateY(-2px);

            box-shadow:
                0 8px 25px
                rgba(0, 0, 0, 0.12);
        }

        .stat-card h3 {
            margin:
                0;

            color:
                #64748b;

            font-size:
                15px;
        }

        .stat-card .number {
            font-size:
                32px;

            font-weight:
                bold;

            margin-top:
                8px;

            color:
                #1d4ed8;
        }


        /* TABLE CARD */

        .table-card {
            background:
                white;

            padding:
                25px;

            border-radius:
                12px;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.08);
        }

        .table-card h2 {
            margin-top:
                0;
        }


        /* SEARCH AND FILTER */

        .search-filter-box {
            display:
                grid;

            grid-template-columns:
                minmax(250px, 1fr)
                180px
                170px
                180px
                auto
                auto;

            gap:
                10px;

            margin-bottom:
                15px;
        }

        .search-input,
        .filter-select {
            padding:
                11px 13px;

            border:
                1px solid
                #cbd5e1;

            border-radius:
                7px;

            font-size:
                14px;

            outline:
                none;

            background:
                white;
        }

        .search-input:focus,
        .filter-select:focus {
            border-color:
                #2563eb;

            box-shadow:
                0 0 0 3px
                rgba(37, 99, 235, 0.10);
        }

        .search-button {
            border:
                none;

            background:
                #2563eb;

            color:
                white;

            padding:
                11px 18px;

            border-radius:
                7px;

            cursor:
                pointer;

            font-size:
                14px;
        }

        .search-button:hover {
            background:
                #1d4ed8;
        }

        .clear-button {
            display:
                inline-flex;

            align-items:
                center;

            justify-content:
                center;

            text-decoration:
                none;

            background:
                #64748b;

            color:
                white;

            padding:
                11px 18px;

            border-radius:
                7px;

            font-size:
                14px;
        }

        .clear-button:hover {
            background:
                #475569;
        }

        .search-result {
            margin-bottom:
                18px;

            color:
                #64748b;

            font-size:
                14px;
        }


        /* TABLE */

        .table-container {
            overflow-x:
                auto;
        }

        table {
            width:
                100%;

            border-collapse:
                collapse;

            min-width:
                1200px;
        }

        th,
        td {
            padding:
                13px;

            border-bottom:
                1px solid
                #e2e8f0;

            text-align:
                left;

            vertical-align:
                middle;
        }

        th {
            background:
                #f8fafc;

            font-size:
                14px;
        }

        td {
            font-size:
                14px;
        }


        /* STATUS */

        .status {
            display:
                inline-block;

            padding:
                5px 10px;

            border-radius:
                20px;

            font-size:
                12px;

            font-weight:
                bold;
        }

        .pending {
            background:
                #fef3c7;

            color:
                #92400e;
        }

        .approved {
            background:
                #dcfce7;

            color:
                #166534;
        }

        .rejected {
            background:
                #fee2e2;

            color:
                #991b1b;
        }

        .paid {
            background:
                #dcfce7;

            color:
                #166534;
        }

        .unpaid {
            background:
                #fee2e2;

            color:
                #991b1b;
        }


        /* PAYMENT PROOF + ACTION BUTTONS */

        .proof-stack,
        .action-stack {
            display:
                flex;

            flex-direction:
                column;

            gap:
                9px;

            align-items:
                stretch;
        }

        .proof-stack {
            min-width:
                155px;
        }

        .action-stack {
            min-width:
                175px;
        }

        .proof-stack .proof-button,
        .proof-stack .student-button,
        .action-stack .approve-button,
        .action-stack .reject-button {
            width:
                100%;

            min-height:
                36px;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            text-align:
                center;

            padding:
                9px 10px;

            border-radius:
                7px;

            font-size:
                12px;

            font-weight:
                600;
        }

        .proof-button {
            background:
                #7c3aed;

            color:
                white;

            text-decoration:
                none;
        }

        .proof-button:hover {
            background:
                #6d28d9;
        }

        .student-button {
            background:
                #2563eb;

            color:
                white;

            text-decoration:
                none;
        }

        .student-button:hover {
            background:
                #1d4ed8;
        }

        .no-proof {
            color:
                #dc2626;

            font-weight:
                bold;

            font-size:
                12px;

            text-align:
                center;

            padding:
                5px 0;
        }

        .action-form {
            display:
                block;

            width:
                100%;

            margin:
                0;
        }

        .action-status {
            display:
                block;

            text-align:
                center;

            padding:
                8px 6px;
        }

        .approve-button,
        .reject-button {
            border:
                none;

            color:
                white;

            padding:
                8px 10px;

            border-radius:
                7px;

            cursor:
                pointer;

            font-size:
                12px;

            font-weight:
                600;
        }

        .approve-button {
            background:
                #16a34a;
        }

        .approve-button:hover {
            background:
                #15803d;
        }

        .reject-button {
            background:
                #dc2626;
        }

        .reject-button:hover {
            background:
                #b91c1c;
        }

        .waiting {
            color:
                #64748b;

            font-size:
                12px;
        }


        /* MOBILE */

        @media (max-width: 1100px) {

            .search-filter-box {
                grid-template-columns:
                    1fr 1fr 1fr;
            }

            .search-input {
                grid-column:
                    span 3;
            }

        }


        @media (max-width: 900px) {

            .search-filter-box {
                grid-template-columns:
                    1fr 1fr;
            }

            .search-input {
                grid-column:
                    span 2;
            }

        }


        @media (max-width: 600px) {

            .navbar {
                padding:
                    15px 20px;
            }

            .navbar h2 {
                font-size:
                    20px;
            }

            .container {
                margin-top:
                    25px;
            }

            .table-card {
                padding:
                    18px;
            }

            .search-filter-box {
                display:
                    flex;

                flex-direction:
                    column;
            }

            .search-input {
                width:
                    100%;
            }

            .filter-select,
            .search-button,
            .clear-button {
                width:
                    100%;
            }

        }

    </style>

</head>


<body>


<div class="navbar">

    <h2>
        🔐 Admin Dashboard
    </h2>


    <a
        href="../logout.php"
        class="logout"
    >
        Logout
    </a>

</div>


<div class="container">


    <!-- WELCOME -->

    <div class="welcome">

        <h1>
            Welcome, Administrator
        </h1>

        <p>
            Manage student enrollment and payment verification.
        </p>

    </div>


    <!-- MESSAGE -->

    <?php if (!empty($message)): ?>

        <div class="message">

            <?= htmlspecialchars(
                $message,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <!-- QUICK ACTIONS -->

    <div class="quick-actions">


        <a
            href="subjects.php"
            class="quick-card"
        >

            <h3>
                📚 Subject Management
            </h3>

            <p>
                Manage subjects, schedules, rooms, and instructors.
            </p>

        </a>


        <a
            href="school_years.php"
            class="quick-card"
        >

            <h3>
                📅 School Year Management
            </h3>

            <p>
                Manage school years and set the active enrollment period.
            </p>

        </a>


        <a
            href="password_reset_requests.php"
            class="quick-card reset-card"
        >

            <h3>
                🔐 Password Reset Requests
            </h3>

            <p>
                Review student requests for password reset.
            </p>


            <?php if (
                $pending_password_resets > 0
            ): ?>

                <span class="reset-count">

                    <?= (int) $pending_password_resets ?>

                    Pending Request
                    <?= $pending_password_resets > 1
                        ? "s"
                        : "" ?>

                </span>

            <?php else: ?>

                <span class="reset-count">
                    No Pending Requests
                </span>

            <?php endif; ?>

        </a>


        <a
            href="enrollment_history.php"
            class="quick-card"
        >

            <h3>
                📜 Enrollment History
            </h3>

            <p>
                View previous enrollment records and their status history.
            </p>

        </a>


        <a
            href="support.php"
            class="quick-card support-card"
        >

            <h3>
                💬 Support Tickets
            </h3>

            <p>
                View and respond to student concerns and support messages.
            </p>

        </a>


    </div>


    <!-- STATISTICS -->

    <div class="stats">


        <div class="stat-card">

            <h3>
                👨‍🎓 Total Students
            </h3>

            <div class="number">
                <?= (int) $total_students ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                📋 Total Enrollments
            </h3>

            <div class="number">
                <?= (int) $total_enrollments ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                ⏳ Pending
            </h3>

            <div class="number">
                <?= (int) $pending ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                ✅ Approved
            </h3>

            <div class="number">
                <?= (int) $approved ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                ❌ Rejected
            </h3>

            <div class="number">
                <?= (int) $rejected ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                🚫 Cancelled
            </h3>

            <div class="number">
                <?= (int) $cancelled ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                💰 Paid
            </h3>

            <div class="number">
                <?= (int) $paid ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                💳 Unpaid
            </h3>

            <div class="number">
                <?= (int) $unpaid ?>
            </div>

        </div>


        <div class="stat-card">

            <h3>
                🔐 Pending Password Resets
            </h3>

            <div class="number">
                <?= (int) $pending_password_resets ?>
            </div>

        </div>


    </div>


    <!-- ENROLLMENTS -->

    <div class="table-card">


        <h2>
            📋 Student Enrollments
        </h2>


        <!-- SEARCH + FILTER FORM -->

        <form
            method="GET"
            class="search-filter-box"
        >


            <input
                type="text"
                name="search"
                class="search-input"
                placeholder="Search student number, name, username, email, or course..."
                maxlength="100"
                value="<?= htmlspecialchars(
                    $search,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
            >


            <select
                name="status"
                class="filter-select"
            >

                <option value="">
                    All Enrollment Status
                </option>

                <option
                    value="pending"
                    <?= $status_filter === "pending"
                        ? "selected"
                        : "" ?>
                >
                    Pending
                </option>

                <option
                    value="approved"
                    <?= $status_filter === "approved"
                        ? "selected"
                        : "" ?>
                >
                    Approved
                </option>

                <option
                    value="rejected"
                    <?= $status_filter === "rejected"
                        ? "selected"
                        : "" ?>
                >
                    Rejected
                </option>

                <option
                    value="cancelled"
                    <?= $status_filter === "cancelled"
                        ? "selected"
                        : "" ?>
                >
                    Cancelled
                </option>

            </select>


            <select
                name="payment"
                class="filter-select"
            >

                <option value="">
                    All Payment Status
                </option>

                <option
                    value="paid"
                    <?= $payment_filter === "paid"
                        ? "selected"
                        : "" ?>
                >
                    Paid
                </option>

                <option
                    value="unpaid"
                    <?= $payment_filter === "unpaid"
                        ? "selected"
                        : "" ?>
                >
                    Unpaid
                </option>

            </select>


            <select
                name="course"
                class="filter-select"
            >

                <option value="">
                    All Courses
                </option>


                <?php while (
                    $course = $courses->fetch_assoc()
                ): ?>

                    <option
                        value="<?= (int) $course["id"] ?>"
                        <?= (string) $course_filter ===
                            (string) $course["id"]
                            ? "selected"
                            : "" ?>
                    >

                        <?= htmlspecialchars(
                            $course["course_code"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </option>

                <?php endwhile; ?>


            </select>


            <button
                type="submit"
                class="search-button"
            >
                🔍 Search / Filter
            </button>


            <?php if (
                $search !== "" ||
                $status_filter !== "" ||
                $payment_filter !== "" ||
                $course_filter !== ""
            ): ?>

                <a
                    href="dashboard.php"
                    class="clear-button"
                >
                    ✕ Clear Filters
                </a>

            <?php endif; ?>


        </form>


        <?php if (
            $search !== "" ||
            $status_filter !== "" ||
            $payment_filter !== "" ||
            $course_filter !== ""
        ): ?>

            <div class="search-result">

                Showing

                <strong>
                    <?= (int) $enrollments->num_rows ?>
                </strong>

                matching enrollment record(s).

            </div>

        <?php endif; ?>


        <div class="table-container">

            <table>

                <thead>

                    <tr>

                        <th>
                            Student
                        </th>

                        <th>
                            Student No.
                        </th>

                        <th>
                            Course
                        </th>

                        <th>
                            Year
                        </th>

                        <th>
                            Semester
                        </th>

                        <th>
                            Enrollment
                        </th>

                        <th>
                            Payment
                        </th>

                        <th>
                            Payment Proof
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (
                    $enrollments->num_rows === 0
                ): ?>

                    <tr>

                        <td
                            colspan="9"
                            style="text-align:center;"
                        >

                            No enrollment records found matching
                            your search/filter.

                        </td>

                    </tr>


                <?php else: ?>


                    <?php while (
                        $row =
                        $enrollments->fetch_assoc()
                    ): ?>


                        <tr>


                            <!-- STUDENT -->

                            <td>

                                <?= htmlspecialchars(
                                    $row["first_name"] .
                                    " " .
                                    $row["last_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <!-- STUDENT NUMBER -->

                            <td>

                                <?= htmlspecialchars(
                                    $row["student_number"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <!-- COURSE -->

                            <td>

                                <?= htmlspecialchars(
                                    $row["course_code"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                                <br>

                                <small>

                                    <?= htmlspecialchars(
                                        $row["course_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </small>

                            </td>


                            <!-- YEAR -->

                            <td>

                                <?= (int) $row["year_level"] ?>

                            </td>


                            <!-- SEMESTER -->

                            <td>

                                <?= (int) $row["semester"] ?>

                            </td>


                            <!-- ENROLLMENT STATUS -->

                            <td>

                                <?php if (
                                    $row["status"] === "approved"
                                ): ?>

                                    <span class="status approved">
                                        Approved
                                    </span>

                                <?php elseif (
                                    $row["status"] === "rejected"
                                ): ?>

                                    <span class="status rejected">
                                        Rejected
                                    </span>

                                <?php elseif (
                                    $row["status"] === "cancelled"
                                ): ?>

                                    <span class="status rejected">
                                        Cancelled
                                    </span>

                                <?php else: ?>

                                    <span class="status pending">
                                        Pending
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- PAYMENT -->

                            <td>

                                <?php if (
                                    $row["payment_status"] === "paid"
                                ): ?>

                                    <span class="status paid">
                                        Paid
                                    </span>

                                <?php else: ?>

                                    <span class="status unpaid">
                                        Unpaid
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- PAYMENT PROOF -->

                            <td>

                                <div class="proof-stack">


                                    <?php if (
                                        !empty(
                                            $row["payment_proof"]
                                        )
                                    ): ?>

                                        <a
                                            href="../uploads/<?= rawurlencode(
                                                basename(
                                                    $row["payment_proof"]
                                                )
                                            ) ?>"
                                            class="proof-button"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            🧾 Open Payment Proof
                                        </a>

                                    <?php else: ?>

                                        <span class="no-proof">
                                            No Payment Proof
                                        </span>

                                    <?php endif; ?>


                                    <a
                                        href="student_details.php?student_id=<?= (int) $row["student_id"] ?>"
                                        class="student-button"
                                    >
                                        👤 View Student
                                    </a>


                                </div>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <div class="action-stack">


                                <?php if (
                                    $row["status"] === "pending"
                                ): ?>


                                    <!-- APPROVE -->

                                    <form
                                        method="POST"
                                        class="action-form"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="enrollment_id"
                                            value="<?= (int) $row["id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="approve_enrollment"
                                        >

                                        <button
                                            type="submit"
                                            class="approve-button"
                                            onclick="return confirm('Approve this enrollment? Payment verification will be done separately after the student uploads payment proof.');"
                                        >
                                            ✓ Approve Enrollment
                                        </button>

                                    </form>


                                    <!-- REJECT -->

                                    <form
                                        method="POST"
                                        class="action-form"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="enrollment_id"
                                            value="<?= (int) $row["id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="reject_enrollment"
                                        >

                                        <button
                                            type="submit"
                                            class="reject-button"
                                            onclick="return confirm('Reject this enrollment?');"
                                        >
                                            ✕ Reject Enrollment
                                        </button>

                                    </form>


                                <?php elseif (
                                    $row["status"] === "approved"
                                ): ?>


                                    <?php if (
                                        $row["payment_status"] === "paid"
                                    ): ?>

                                        <span
                                            class="status paid action-status"
                                        >
                                            ✓ Payment Verified
                                        </span>


                                    <?php elseif (
                                        !empty(
                                            $row["payment_proof"]
                                        )
                                    ): ?>


                                        <!-- VERIFY PAYMENT -->

                                        <form
                                            method="POST"
                                            class="action-form"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="enrollment_id"
                                                value="<?= (int) $row["id"] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="verify_payment"
                                            >

                                            <button
                                                type="submit"
                                                class="approve-button"
                                                onclick="return confirm('Verify this payment proof?');"
                                            >
                                                💰 Verify Payment
                                            </button>

                                        </form>


                                        <!-- REJECT PAYMENT -->

                                        <form
                                            method="POST"
                                            class="action-form"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="enrollment_id"
                                                value="<?= (int) $row["id"] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="reject_payment"
                                            >

                                            <button
                                                type="submit"
                                                class="reject-button"
                                                onclick="return confirm('Reject this payment proof? The student will be asked to upload a new payment proof.');"
                                            >
                                                ✕ Reject Payment
                                            </button>

                                        </form>


                                    <?php else: ?>

                                        <span
                                            class="waiting action-status"
                                        >
                                            Waiting for payment proof
                                        </span>

                                    <?php endif; ?>


                                <?php else: ?>


                                    <span
                                        class="waiting action-status"
                                    >

                                        <?= htmlspecialchars(
                                            ucfirst(
                                                $row["status"]
                                            ),
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </span>


                                <?php endif; ?>


                                </div>

                            </td>


                        </tr>


                    <?php endwhile; ?>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>


</div>


<!-- ==========================================================
     AUTOMATIC DASHBOARD UPDATE
     ========================================================== -->

<script>

(function () {

    const AUTO_REFRESH_INTERVAL = 5000;

    let isUpdating = false;


    async function updateDashboard() {

        if (isUpdating) {
            return;
        }

        isUpdating = true;


        try {

            const response = await fetch(
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


            const currentStats =
                document.querySelector(
                    ".stats"
                );


            const currentTable =
                document.querySelector(
                    ".table-container"
                );


            const newStats =
                newDocument.querySelector(
                    ".stats"
                );


            const newTable =
                newDocument.querySelector(
                    ".table-container"
                );


            if (
                currentStats &&
                newStats &&
                currentStats.innerHTML !==
                    newStats.innerHTML
            ) {

                currentStats.innerHTML =
                    newStats.innerHTML;
            }


            if (
                currentTable &&
                newTable &&
                currentTable.innerHTML !==
                    newTable.innerHTML
            ) {

                currentTable.innerHTML =
                    newTable.innerHTML;
            }


        } catch (error) {

            console.warn(
                "Dashboard auto-update failed:",
                error
            );


        } finally {

            isUpdating = false;
        }

    }


    setInterval(
        updateDashboard,
        AUTO_REFRESH_INTERVAL
    );


})();

</script>


</body>

</html>

<?php

$stmt->close();

?>
