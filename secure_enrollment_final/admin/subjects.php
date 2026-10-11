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
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: same-origin");


/* ==========================================================
   ADMIN ACCESS PROTECTION
   ========================================================== */

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}


$message = "";
$message_type = "";


/* ==========================================================
   HELPER FUNCTIONS
   ========================================================== */

function redirect_subjects($message = "", $type = "")
{
    if ($message !== "") {
        $_SESSION["subjects_message"] = $message;
        $_SESSION["subjects_message_type"] = $type;
    }

    header("Location: subjects.php");
    exit;
}


function get_post_string($name, $max_length = 255)
{
    $value = trim($_POST[$name] ?? "");

    if (mb_strlen($value) > $max_length) {
        return null;
    }

    return $value;
}


function valid_time_or_empty($value)
{
    if ($value === "") {
        return true;
    }

    return preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
        $value
    ) === 1;
}


function valid_day_or_empty($day)
{
    if ($day === "") {
        return true;
    }

    $allowed_days = [
        "Monday",
        "Tuesday",
        "Wednesday",
        "Thursday",
        "Friday",
        "Saturday"
    ];

    return in_array($day, $allowed_days, true);
}


function course_exists($conn, $course_id)
{
    $stmt = $conn->prepare(
        "SELECT id
         FROM courses
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $course_id);

    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();
    $exists = $result->num_rows === 1;

    $stmt->close();

    return $exists;
}


/* ==========================================================
   DISPLAY SESSION MESSAGE
   ========================================================== */

if (isset($_SESSION["subjects_message"])) {

    $message = $_SESSION["subjects_message"];

    $message_type =
        $_SESSION["subjects_message_type"] ?? "success";

    unset($_SESSION["subjects_message"]);
    unset($_SESSION["subjects_message_type"]);
}


/* ==========================================================
   POST ACTIONS
   ========================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* ------------------------------------------------------
       CSRF
       ------------------------------------------------------ */

    verify_csrf_token();


    /* ------------------------------------------------------
       DELETE SUBJECT
       ------------------------------------------------------ */

    if (isset($_POST["delete_subject"])) {

        $subject_id = filter_var(
            $_POST["subject_id"] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($subject_id === false || $subject_id <= 0) {
            redirect_subjects(
                "Invalid subject ID.",
                "error"
            );
        }

        try {

            $conn->begin_transaction();

            /*
             * Lock the subject first.
             */
            $stmt = $conn->prepare(
                "SELECT id, subject_code
                 FROM subjects
                 WHERE id = ?
                 FOR UPDATE"
            );

            if (!$stmt) {
                throw new Exception("Database error.");
            }

            $stmt->bind_param("i", $subject_id);

            if (!$stmt->execute()) {
                throw new Exception("Database error.");
            }

            $result = $stmt->get_result();

            if ($result->num_rows !== 1) {
                $stmt->close();
                $conn->rollback();

                redirect_subjects(
                    "Subject not found.",
                    "error"
                );
            }

            $subject = $result->fetch_assoc();
            $stmt->close();


            /*
             * Delete subject.
             */
            $stmt = $conn->prepare(
                "DELETE FROM subjects
                 WHERE id = ?"
            );

            if (!$stmt) {
                throw new Exception("Database error.");
            }

            $stmt->bind_param("i", $subject_id);

            if (!$stmt->execute()) {
                throw new Exception("Unable to delete subject.");
            }

            $stmt->close();


            /*
             * Activity log.
             */
            $action =
                "Deleted subject #" .
                $subject_id .
                " (" .
                $subject["subject_code"] .
                ")";

            $ip_address =
                $_SERVER["REMOTE_ADDR"] ?? "UNKNOWN";

            $log = $conn->prepare(
                "INSERT INTO activity_logs
                 (user_id, action, ip_address)
                 VALUES (?, ?, ?)"
            );

            if (!$log) {
                throw new Exception("Database error.");
            }

            $log->bind_param(
                "iss",
                $_SESSION["user_id"],
                $action,
                $ip_address
            );

            if (!$log->execute()) {
                throw new Exception("Unable to record activity.");
            }

            $log->close();

            $conn->commit();

            redirect_subjects(
                "Subject deleted successfully.",
                "success"
            );

        } catch (Throwable $e) {

            if ($conn->in_transaction) {
                $conn->rollback();
            }

            error_log(
                "Subject deletion error: " .
                $e->getMessage()
            );

            redirect_subjects(
                "Unable to delete subject.",
                "error"
            );
        }
    }


    /* ------------------------------------------------------
       ADD SUBJECT
       ------------------------------------------------------ */

    if (isset($_POST["add_subject"])) {

        $course_id = filter_var(
            $_POST["course_id"] ?? null,
            FILTER_VALIDATE_INT
        );

        $subject_code =
            get_post_string("subject_code", 30);

        $subject_name =
            get_post_string("subject_name", 150);

        $units = filter_var(
            $_POST["units"] ?? null,
            FILTER_VALIDATE_INT
        );

        $year_level = filter_var(
            $_POST["year_level"] ?? null,
            FILTER_VALIDATE_INT
        );

        $semester = filter_var(
            $_POST["semester"] ?? null,
            FILTER_VALIDATE_INT
        );

        $day =
            get_post_string("day", 20);

        $start_time =
            get_post_string("start_time", 5);

        $end_time =
            get_post_string("end_time", 5);

        $room =
            get_post_string("room", 100);

        $instructor =
            get_post_string("instructor", 150);


        /*
         * Basic validation.
         */
        if (
            $course_id === false ||
            $course_id <= 0 ||
            $subject_code === null ||
            $subject_name === null ||
            $units === false ||
            $units < 1 ||
            $units > 10 ||
            $year_level === false ||
            $year_level < 1 ||
            $year_level > 4 ||
            $semester === false ||
            $semester < 1 ||
            $semester > 2 ||
            $day === null ||
            $start_time === null ||
            $end_time === null ||
            $room === null ||
            $instructor === null
        ) {
            redirect_subjects(
                "Please check the subject information and field lengths.",
                "error"
            );
        }


        /*
         * Validate course.
         */
        if (!course_exists($conn, $course_id)) {
            redirect_subjects(
                "Selected course does not exist.",
                "error"
            );
        }


        /*
         * Validate day/time.
         */
        if (!valid_day_or_empty($day)) {
            redirect_subjects(
                "Invalid day selected.",
                "error"
            );
        }

        if (
            !valid_time_or_empty($start_time) ||
            !valid_time_or_empty($end_time)
        ) {
            redirect_subjects(
                "Invalid schedule time.",
                "error"
            );
        }


        if (
            $start_time !== "" &&
            $end_time !== "" &&
            $start_time >= $end_time
        ) {
            redirect_subjects(
                "End time must be later than start time.",
                "error"
            );
        }


        try {

            /*
             * Prevent duplicate subject code
             * within the same course.
             */
            $stmt = $conn->prepare(
                "SELECT id
                 FROM subjects
                 WHERE course_id = ?
                   AND subject_code = ?
                 LIMIT 1"
            );

            if (!$stmt) {
                throw new Exception("Database error.");
            }

            $stmt->bind_param(
                "is",
                $course_id,
                $subject_code
            );

            if (!$stmt->execute()) {
                throw new Exception("Database error.");
            }

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $stmt->close();

                redirect_subjects(
                    "That subject code already exists for this course.",
                    "error"
                );
            }

            $stmt->close();


            $conn->begin_transaction();


            $stmt = $conn->prepare(
                "INSERT INTO subjects
                (
                    course_id,
                    subject_code,
                    subject_name,
                    units,
                    year_level,
                    semester,
                    day,
                    start_time,
                    end_time,
                    room,
                    instructor
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception("Database error.");
            }

            $stmt->bind_param(
                "issiiisssss",
                $course_id,
                $subject_code,
                $subject_name,
                $units,
                $year_level,
                $semester,
                $day,
                $start_time,
                $end_time,
                $room,
                $instructor
            );

            if (!$stmt->execute()) {
                throw new Exception("Unable to add subject.");
            }

            $new_subject_id =
                $stmt->insert_id;

            $stmt->close();


            /*
             * Activity log.
             */
            $action =
                "Added subject #" .
                $new_subject_id .
                " (" .
                $subject_code .
                ")";

            $ip_address =
                $_SERVER["REMOTE_ADDR"] ?? "UNKNOWN";

            $log = $conn->prepare(
                "INSERT INTO activity_logs
                 (user_id, action, ip_address)
                 VALUES (?, ?, ?)"
            );

            if (!$log) {
                throw new Exception("Database error.");
            }

            $log->bind_param(
                "iss",
                $_SESSION["user_id"],
                $action,
                $ip_address
            );

            if (!$log->execute()) {
                throw new Exception("Unable to record activity.");
            }

            $log->close();

            $conn->commit();

            redirect_subjects(
                "Subject added successfully.",
                "success"
            );

        } catch (Throwable $e) {

            if ($conn->in_transaction) {
                $conn->rollback();
            }

            error_log(
                "Subject addition error: " .
                $e->getMessage()
            );

            redirect_subjects(
                "Unable to add subject.",
                "error"
            );
        }
    }


    /* ------------------------------------------------------
       EDIT SUBJECT
       ------------------------------------------------------ */

    if (isset($_POST["edit_subject"])) {

        $subject_id = filter_var(
            $_POST["subject_id"] ?? null,
            FILTER_VALIDATE_INT
        );

        $course_id = filter_var(
            $_POST["course_id"] ?? null,
            FILTER_VALIDATE_INT
        );

        $subject_code =
            get_post_string("subject_code", 30);

        $subject_name =
            get_post_string("subject_name", 150);

        $units = filter_var(
            $_POST["units"] ?? null,
            FILTER_VALIDATE_INT
        );

        $year_level = filter_var(
            $_POST["year_level"] ?? null,
            FILTER_VALIDATE_INT
        );

        $semester = filter_var(
            $_POST["semester"] ?? null,
            FILTER_VALIDATE_INT
        );

        $day =
            get_post_string("day", 20);

        $start_time =
            get_post_string("start_time", 5);

        $end_time =
            get_post_string("end_time", 5);

        $room =
            get_post_string("room", 100);

        $instructor =
            get_post_string("instructor", 150);


        if (
            $subject_id === false ||
            $subject_id <= 0 ||
            $course_id === false ||
            $course_id <= 0 ||
            $subject_code === null ||
            $subject_name === null ||
            $units === false ||
            $units < 1 ||
            $units > 10 ||
            $year_level === false ||
            $year_level < 1 ||
            $year_level > 4 ||
            $semester === false ||
            $semester < 1 ||
            $semester > 2 ||
            $day === null ||
            $start_time === null ||
            $end_time === null ||
            $room === null ||
            $instructor === null
        ) {
            redirect_subjects(
                "Please check the subject information and field lengths.",
                "error"
            );
        }


        if (!course_exists($conn, $course_id)) {
            redirect_subjects(
                "Selected course does not exist.",
                "error"
            );
        }


        if (!valid_day_or_empty($day)) {
            redirect_subjects(
                "Invalid day selected.",
                "error"
            );
        }

        if (
            !valid_time_or_empty($start_time) ||
            !valid_time_or_empty($end_time)
        ) {
            redirect_subjects(
                "Invalid schedule time.",
                "error"
            );
        }


        if (
            $start_time !== "" &&
            $end_time !== "" &&
            $start_time >= $end_time
        ) {
            redirect_subjects(
                "End time must be later than start time.",
                "error"
            );
        }


        try {

            $conn->begin_transaction();


            /*
             * Lock existing subject.
             */
            $stmt = $conn->prepare(
                "SELECT id, subject_code
                 FROM subjects
                 WHERE id = ?
                 FOR UPDATE"
            );

            if (!$stmt) {
                throw new Exception("Database error.");
            }

            $stmt->bind_param(
                "i",
                $subject_id
            );

            if (!$stmt->execute()) {
                throw new Exception("Database error.");
            }

            $result = $stmt->get_result();

            if ($result->num_rows !== 1) {
                $stmt->close();

                throw new Exception(
                    "Subject not found."
                );
            }

            $old_subject =
                $result->fetch_assoc();

            $stmt->close();


            /*
             * Prevent duplicate subject code
             * except for the current subject.
             */
            $stmt = $conn->prepare(
                "SELECT id
                 FROM subjects
                 WHERE course_id = ?
                   AND subject_code = ?
                   AND id <> ?
                 LIMIT 1"
            );

            if (!$stmt) {
                throw new Exception("Database error.");
            }

            $stmt->bind_param(
                "isi",
                $course_id,
                $subject_code,
                $subject_id
            );

            if (!$stmt->execute()) {
                throw new Exception("Database error.");
            }

            $duplicate_result =
                $stmt->get_result();

            if ($duplicate_result->num_rows > 0) {
                $stmt->close();

                throw new Exception(
                    "That subject code already exists for this course."
                );
            }

            $stmt->close();


            /*
             * Update subject.
             */
            $stmt = $conn->prepare(
                "UPDATE subjects
                 SET
                    course_id = ?,
                    subject_code = ?,
                    subject_name = ?,
                    units = ?,
                    year_level = ?,
                    semester = ?,
                    day = ?,
                    start_time = ?,
                    end_time = ?,
                    room = ?,
                    instructor = ?
                 WHERE id = ?"
            );

            if (!$stmt) {
                throw new Exception("Database error.");
            }

            $stmt->bind_param(
                "issiiisssssi",
                $course_id,
                $subject_code,
                $subject_name,
                $units,
                $year_level,
                $semester,
                $day,
                $start_time,
                $end_time,
                $room,
                $instructor,
                $subject_id
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    "Unable to update subject."
                );
            }

            $stmt->close();


            /*
             * Activity log.
             */
            $action =
                "Updated subject #" .
                $subject_id .
                " (" .
                $subject_code .
                ")";

            $ip_address =
                $_SERVER["REMOTE_ADDR"] ?? "UNKNOWN";

            $log = $conn->prepare(
                "INSERT INTO activity_logs
                 (user_id, action, ip_address)
                 VALUES (?, ?, ?)"
            );

            if (!$log) {
                throw new Exception("Database error.");
            }

            $log->bind_param(
                "iss",
                $_SESSION["user_id"],
                $action,
                $ip_address
            );

            if (!$log->execute()) {
                throw new Exception(
                    "Unable to record activity."
                );
            }

            $log->close();

            $conn->commit();

            redirect_subjects(
                "Subject updated successfully.",
                "success"
            );

        } catch (Throwable $e) {

            if ($conn->in_transaction) {
                $conn->rollback();
            }

            error_log(
                "Subject update error: " .
                $e->getMessage()
            );

            $safe_message =
                $e->getMessage() ===
                "That subject code already exists for this course."
                    ? $e->getMessage()
                    : (
                        $e->getMessage() ===
                        "Subject not found."
                            ? $e->getMessage()
                            : "Unable to update subject."
                    );

            redirect_subjects(
                $safe_message,
                "error"
            );
        }
    }
}


/* ==========================================================
   COURSES
   ========================================================== */

$courses = [];

$course_result = $conn->query(
    "SELECT id, course_code, course_name
     FROM courses
     ORDER BY course_code ASC"
);

if ($course_result) {

    while ($course = $course_result->fetch_assoc()) {
        $courses[] = $course;
    }
}


/* ==========================================================
   SEARCH AND FILTER
   ========================================================== */

$search =
    trim($_GET["search"] ?? "");

if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}


$filter_course = filter_var(
    $_GET["course_id"] ?? 0,
    FILTER_VALIDATE_INT
);

if ($filter_course === false || $filter_course < 0) {
    $filter_course = 0;
}


$filter_year = filter_var(
    $_GET["year_level"] ?? 0,
    FILTER_VALIDATE_INT
);

if (
    $filter_year === false ||
    $filter_year < 0 ||
    $filter_year > 4
) {
    $filter_year = 0;
}


$filter_semester = filter_var(
    $_GET["semester"] ?? 0,
    FILTER_VALIDATE_INT
);

if (
    $filter_semester === false ||
    $filter_semester < 0 ||
    $filter_semester > 2
) {
    $filter_semester = 0;
}


$sql = "
    SELECT
        s.id,
        s.course_id,
        s.subject_code,
        s.subject_name,
        s.units,
        s.year_level,
        s.semester,
        s.day,
        s.start_time,
        s.end_time,
        s.room,
        s.instructor,
        c.course_code,
        c.course_name
    FROM subjects s
    INNER JOIN courses c ON s.course_id = c.id
    WHERE 1=1
";


$params = [];
$types = "";


/* SEARCH */

if ($search !== "") {

    $sql .= "
        AND (
            s.subject_code LIKE ?
            OR s.subject_name LIKE ?
            OR c.course_code LIKE ?
            OR c.course_name LIKE ?
            OR s.room LIKE ?
            OR s.instructor LIKE ?
        )
    ";

    $search_value =
        "%" . $search . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "ssssss";
}


/* COURSE FILTER */

if ($filter_course > 0) {

    $sql .=
        " AND s.course_id = ? ";

    $params[] = $filter_course;
    $types .= "i";
}


/* YEAR FILTER */

if (
    $filter_year >= 1 &&
    $filter_year <= 4
) {

    $sql .=
        " AND s.year_level = ? ";

    $params[] = $filter_year;
    $types .= "i";
}


/* SEMESTER FILTER */

if (
    $filter_semester >= 1 &&
    $filter_semester <= 2
) {

    $sql .=
        " AND s.semester = ? ";

    $params[] = $filter_semester;
    $types .= "i";
}


$sql .= "
    ORDER BY
        c.course_code ASC,
        s.year_level ASC,
        s.semester ASC,
        s.subject_code ASC
";


$stmt = $conn->prepare($sql);

if (!$stmt) {

    error_log(
        "Subject listing prepare error: " .
        $conn->error
    );

    die("Unable to load subjects.");
}


if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}


if (!$stmt->execute()) {

    error_log(
        "Subject listing execute error: " .
        $stmt->error
    );

    $stmt->close();

    die("Unable to load subjects.");
}


$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Subject Management</title>

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
            width: 95%;
            max-width: 1400px;
            margin: 30px auto;
        }

        .header {
            background: #ffffff;
            padding: 25px;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }

        .header h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .header p {
            margin: 0;
            color: #6b7280;
        }

        .back-button {
            display: inline-block;
            margin-top: 15px;
            text-decoration: none;
            background: #374151;
            color: white;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
        }

        .back-button:hover {
            background: #1f2937;
        }

        .message {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }

        .card h2 {
            margin-top: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-weight: bold;
            margin-bottom: 6px;
            font-size: 14px;
        }

        input,
        select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #2563eb;
        }

        .add-button {
            margin-top: 18px;
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
        }

        .add-button:hover {
            background: #1d4ed8;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 10px;
            align-items: end;
        }

        .filter-button {
            background: #111827;
            color: white;
            border: none;
            padding: 11px 18px;
            border-radius: 8px;
            cursor: pointer;
        }

        .clear-button {
            display: inline-block;
            background: #e5e7eb;
            color: #111827;
            padding: 11px 18px;
            border-radius: 8px;
            text-decoration: none;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1250px;
        }

        th,
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }

        th {
            background: #f9fafb;
            font-weight: bold;
        }

        tr:hover {
            background: #f9fafb;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: bold;
        }

        .schedule {
            line-height: 1.5;
        }

        .schedule strong {
            display: block;
        }

        .edit-button {
            background: #f59e0b;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 7px;
            cursor: pointer;
            margin-bottom: 6px;
        }

        .edit-button:hover {
            background: #d97706;
        }

        .delete-button {
            background: #dc2626;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 7px;
            cursor: pointer;
        }

        .delete-button:hover {
            background: #b91c1c;
        }

        .edit-form {
            background: #fff7ed;
            padding: 20px;
            border-radius: 10px;
            margin-top: 10px;
            min-width: 450px;
        }

        .edit-form .form-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .update-button {
            margin-top: 15px;
            background: #16a34a;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 7px;
            cursor: pointer;
            font-weight: bold;
        }

        .update-button:hover {
            background: #15803d;
        }

        .cancel-edit {
            display: inline-block;
            margin-top: 15px;
            margin-left: 5px;
            background: #6b7280;
            color: white;
            padding: 10px 16px;
            border-radius: 7px;
            text-decoration: none;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #6b7280;
        }

        @media (max-width: 900px) {

            .form-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }

        }

        @media (max-width: 600px) {

            .container {
                width: 94%;
                margin: 15px auto;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .header h1 {
                font-size: 23px;
            }

        }

    </style>

</head>


<body>

<div class="container">


    <div class="header">

        <h1>
            📚 Subject Management
        </h1>

        <p>
            Manage subjects, course assignments, year level, semester, and class schedules.
        </p>

        <a
            href="dashboard.php"
            class="back-button"
        >
            ← Back to Admin Dashboard
        </a>

    </div>


    <?php if ($message !== ""): ?>

        <div class="message <?= htmlspecialchars($message_type, ENT_QUOTES, "UTF-8") ?>">

            <?= htmlspecialchars(
                $message,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <!-- ADD SUBJECT -->

    <div class="card">

        <h2>
            ➕ Add New Subject
        </h2>

        <form method="POST">

            <?= csrf_field() ?>

            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Course *
                    </label>

                    <select
                        name="course_id"
                        required
                    >

                        <option value="">
                            Select Course
                        </option>

                        <?php foreach ($courses as $course): ?>

                            <option
                                value="<?= (int) $course["id"] ?>"
                            >

                                <?= htmlspecialchars(
                                    $course["course_code"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                                -

                                <?= htmlspecialchars(
                                    $course["course_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Subject Code *
                    </label>

                    <input
                        type="text"
                        name="subject_code"
                        maxlength="30"
                        placeholder="e.g. CPE101"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Subject Name *
                    </label>

                    <input
                        type="text"
                        name="subject_name"
                        maxlength="150"
                        placeholder="e.g. Computer Engineering Fundamentals"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Units *
                    </label>

                    <input
                        type="number"
                        name="units"
                        min="1"
                        max="10"
                        value="3"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Year Level *
                    </label>

                    <select
                        name="year_level"
                        required
                    >

                        <option value="">
                            Select Year
                        </option>

                        <option value="1">
                            1st Year
                        </option>

                        <option value="2">
                            2nd Year
                        </option>

                        <option value="3">
                            3rd Year
                        </option>

                        <option value="4">
                            4th Year
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Semester *
                    </label>

                    <select
                        name="semester"
                        required
                    >

                        <option value="">
                            Select Semester
                        </option>

                        <option value="1">
                            1st Semester
                        </option>

                        <option value="2">
                            2nd Semester
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Day
                    </label>

                    <select name="day">

                        <option value="">
                            Select Day
                        </option>

                        <option value="Monday">
                            Monday
                        </option>

                        <option value="Tuesday">
                            Tuesday
                        </option>

                        <option value="Wednesday">
                            Wednesday
                        </option>

                        <option value="Thursday">
                            Thursday
                        </option>

                        <option value="Friday">
                            Friday
                        </option>

                        <option value="Saturday">
                            Saturday
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Start Time
                    </label>

                    <input
                        type="time"
                        name="start_time"
                    >

                </div>


                <div class="form-group">

                    <label>
                        End Time
                    </label>

                    <input
                        type="time"
                        name="end_time"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Room
                    </label>

                    <input
                        type="text"
                        name="room"
                        maxlength="100"
                        placeholder="e.g. Room 201"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Instructor
                    </label>

                    <input
                        type="text"
                        name="instructor"
                        maxlength="150"
                        placeholder="e.g. Engr. Juan Dela Cruz"
                    >

                </div>

            </div>


            <button
                type="submit"
                name="add_subject"
                class="add-button"
            >
                ➕ Add Subject
            </button>

        </form>

    </div>


    <!-- SEARCH / FILTER -->

    <div class="card">

        <h2>
            🔎 Search & Filter Subjects
        </h2>

        <form method="GET">

            <div class="filter-grid">


                <div class="form-group">

                    <label>
                        Search
                    </label>

                    <input
                        type="text"
                        name="search"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $search,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        placeholder="Subject code, name, course, room, instructor..."
                    >

                </div>


                <div class="form-group">

                    <label>
                        Course
                    </label>

                    <select name="course_id">

                        <option value="0">
                            All Courses
                        </option>

                        <?php foreach ($courses as $course): ?>

                            <option
                                value="<?= (int) $course["id"] ?>"
                                <?= $filter_course == $course["id"]
                                    ? "selected"
                                    : "" ?>
                            >

                                <?= htmlspecialchars(
                                    $course["course_code"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Year
                    </label>

                    <select name="year_level">

                        <option value="0">
                            All Years
                        </option>

                        <option
                            value="1"
                            <?= $filter_year === 1 ? "selected" : "" ?>
                        >
                            1st Year
                        </option>

                        <option
                            value="2"
                            <?= $filter_year === 2 ? "selected" : "" ?>
                        >
                            2nd Year
                        </option>

                        <option
                            value="3"
                            <?= $filter_year === 3 ? "selected" : "" ?>
                        >
                            3rd Year
                        </option>

                        <option
                            value="4"
                            <?= $filter_year === 4 ? "selected" : "" ?>
                        >
                            4th Year
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Semester
                    </label>

                    <select name="semester">

                        <option value="0">
                            All Semesters
                        </option>

                        <option
                            value="1"
                            <?= $filter_semester === 1 ? "selected" : "" ?>
                        >
                            1st Semester
                        </option>

                        <option
                            value="2"
                            <?= $filter_semester === 2 ? "selected" : "" ?>
                        >
                            2nd Semester
                        </option>

                    </select>

                </div>


                <div>

                    <button
                        type="submit"
                        class="filter-button"
                    >
                        Filter
                    </button>

                    <a
                        href="subjects.php"
                        class="clear-button"
                    >
                        Clear
                    </a>

                </div>

            </div>

        </form>

    </div>


    <!-- SUBJECT LIST -->

    <div class="card">

        <h2>
            📋 Subject List
        </h2>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>Subject</th>
                        <th>Course</th>
                        <th>Year</th>
                        <th>Semester</th>
                        <th>Units</th>
                        <th>Schedule</th>
                        <th>Room</th>
                        <th>Instructor</th>
                        <th>Actions</th>

                    </tr>

                </thead>


                <tbody>


                <?php if ($result->num_rows > 0): ?>


                    <?php while ($row = $result->fetch_assoc()): ?>


                        <tr>


                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $row["subject_code"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </strong>

                                <br>

                                <?= htmlspecialchars(
                                    $row["subject_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <td>

                                <span class="badge">

                                    <?= htmlspecialchars(
                                        $row["course_code"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?php

                                $year_labels = [
                                    1 => "1st",
                                    2 => "2nd",
                                    3 => "3rd",
                                    4 => "4th"
                                ];

                                echo $year_labels[
                                    (int) $row["year_level"]
                                ] ?? "Unknown";

                                ?>

                                Year

                            </td>


                            <td>

                                <?= (int) $row["semester"] === 1
                                    ? "1st"
                                    : "2nd" ?>

                                Semester

                            </td>


                            <td>

                                <?= (int) $row["units"] ?>

                            </td>


                            <td class="schedule">


                                <?php if (!empty($row["day"])): ?>

                                    <strong>

                                        📅

                                        <?= htmlspecialchars(
                                            $row["day"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </strong>

                                <?php endif; ?>


                                <?php if (
                                    !empty($row["start_time"]) &&
                                    !empty($row["end_time"])
                                ): ?>

                                    🕐

                                    <?= date(
                                        "g:i A",
                                        strtotime(
                                            $row["start_time"]
                                        )
                                    ) ?>

                                    -

                                    <?= date(
                                        "g:i A",
                                        strtotime(
                                            $row["end_time"]
                                        )
                                    ) ?>

                                <?php endif; ?>


                                <?php if (
                                    empty($row["day"]) &&
                                    empty($row["start_time"]) &&
                                    empty($row["end_time"])
                                ): ?>

                                    <span style="color:#9ca3af;">

                                        No schedule

                                    </span>

                                <?php endif; ?>


                            </td>


                            <td>

                                <?= !empty($row["room"])
                                    ? htmlspecialchars(
                                        $row["room"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    )
                                    : "—"
                                ?>

                            </td>


                            <td>

                                <?= !empty($row["instructor"])
                                    ? htmlspecialchars(
                                        $row["instructor"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    )
                                    : "—"
                                ?>

                            </td>


                            <td>


                                <button
                                    type="button"
                                    class="edit-button"
                                    onclick="toggleEdit(<?= (int) $row["id"] ?>)"
                                >
                                    ✏️ Edit
                                </button>


                                <form
                                    method="POST"
                                    style="display:inline;"
                                >

                                    <?= csrf_field() ?>

                                    <input
                                        type="hidden"
                                        name="subject_id"
                                        value="<?= (int) $row["id"] ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="delete_subject"
                                        class="delete-button"
                                        onclick="return confirm('Are you sure you want to delete this subject?');"
                                    >
                                        🗑️ Delete
                                    </button>

                                </form>


                                <div
                                    id="edit-<?= (int) $row["id"] ?>"
                                    class="edit-form"
                                    style="display:none;"
                                >


                                    <form method="POST">

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="subject_id"
                                            value="<?= (int) $row["id"] ?>"
                                        >


                                        <div class="form-grid">


                                            <div class="form-group">

                                                <label>
                                                    Course
                                                </label>

                                                <select
                                                    name="course_id"
                                                    required
                                                >

                                                    <?php foreach ($courses as $course): ?>

                                                        <option
                                                            value="<?= (int) $course["id"] ?>"
                                                            <?= $row["course_id"] == $course["id"]
                                                                ? "selected"
                                                                : "" ?>
                                                        >

                                                            <?= htmlspecialchars(
                                                                $course["course_code"],
                                                                ENT_QUOTES,
                                                                "UTF-8"
                                                            ) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Subject Code
                                                </label>

                                                <input
                                                    type="text"
                                                    name="subject_code"
                                                    maxlength="30"
                                                    value="<?= htmlspecialchars(
                                                        $row["subject_code"],
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>"
                                                    required
                                                >

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Subject Name
                                                </label>

                                                <input
                                                    type="text"
                                                    name="subject_name"
                                                    maxlength="150"
                                                    value="<?= htmlspecialchars(
                                                        $row["subject_name"],
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>"
                                                    required
                                                >

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Units
                                                </label>

                                                <input
                                                    type="number"
                                                    name="units"
                                                    min="1"
                                                    max="10"
                                                    value="<?= (int) $row["units"] ?>"
                                                    required
                                                >

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Year Level
                                                </label>

                                                <select
                                                    name="year_level"
                                                    required
                                                >

                                                    <option
                                                        value="1"
                                                        <?= $row["year_level"] == 1
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        1st Year
                                                    </option>

                                                    <option
                                                        value="2"
                                                        <?= $row["year_level"] == 2
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        2nd Year
                                                    </option>

                                                    <option
                                                        value="3"
                                                        <?= $row["year_level"] == 3
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        3rd Year
                                                    </option>

                                                    <option
                                                        value="4"
                                                        <?= $row["year_level"] == 4
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        4th Year
                                                    </option>

                                                </select>

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Semester
                                                </label>

                                                <select
                                                    name="semester"
                                                    required
                                                >

                                                    <option
                                                        value="1"
                                                        <?= $row["semester"] == 1
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        1st Semester
                                                    </option>

                                                    <option
                                                        value="2"
                                                        <?= $row["semester"] == 2
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        2nd Semester
                                                    </option>

                                                </select>

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Day
                                                </label>

                                                <select name="day">

                                                    <option value="">
                                                        Select Day
                                                    </option>

                                                    <?php

                                                    $days = [
                                                        "Monday",
                                                        "Tuesday",
                                                        "Wednesday",
                                                        "Thursday",
                                                        "Friday",
                                                        "Saturday"
                                                    ];

                                                    ?>

                                                    <?php foreach (
                                                        $days
                                                        as $day_option
                                                    ): ?>

                                                        <option
                                                            value="<?= htmlspecialchars(
                                                                $day_option,
                                                                ENT_QUOTES,
                                                                "UTF-8"
                                                            ) ?>"
                                                            <?= $row["day"] === $day_option
                                                                ? "selected"
                                                                : "" ?>
                                                        >

                                                            <?= htmlspecialchars(
                                                                $day_option,
                                                                ENT_QUOTES,
                                                                "UTF-8"
                                                            ) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Start Time
                                                </label>

                                                <input
                                                    type="time"
                                                    name="start_time"
                                                    value="<?= htmlspecialchars(
                                                        $row["start_time"] ?? "",
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>"
                                                >

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    End Time
                                                </label>

                                                <input
                                                    type="time"
                                                    name="end_time"
                                                    value="<?= htmlspecialchars(
                                                        $row["end_time"] ?? "",
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>"
                                                >

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Room
                                                </label>

                                                <input
                                                    type="text"
                                                    name="room"
                                                    maxlength="100"
                                                    value="<?= htmlspecialchars(
                                                        $row["room"] ?? "",
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>"
                                                >

                                            </div>


                                            <div class="form-group">

                                                <label>
                                                    Instructor
                                                </label>

                                                <input
                                                    type="text"
                                                    name="instructor"
                                                    maxlength="150"
                                                    value="<?= htmlspecialchars(
                                                        $row["instructor"] ?? "",
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>"
                                                >

                                            </div>


                                        </div>


                                        <button
                                            type="submit"
                                            name="edit_subject"
                                            class="update-button"
                                        >
                                            💾 Save Changes
                                        </button>


                                        <a
                                            href="#"
                                            onclick="toggleEdit(<?= (int) $row["id"] ?>); return false;"
                                            class="cancel-edit"
                                        >
                                            Cancel
                                        </a>


                                    </form>


                                </div>


                            </td>


                        </tr>


                    <?php endwhile; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="9"
                            class="empty"
                        >
                            No subjects found.
                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>


</div>


<script>

function toggleEdit(id) {

    const element =
        document.getElementById("edit-" + id);

    if (!element) {
        return;
    }

    if (
        element.style.display === "none" ||
        element.style.display === ""
    ) {

        element.style.display = "block";

    } else {

        element.style.display = "none";

    }
}

</script>


</body>

</html>
