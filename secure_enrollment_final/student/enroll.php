<?php

session_start();

require_once "../config/database.php";
require_once "../config/csrf.php";


/* =========================
   STUDENT SESSION CHECK
========================= */

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "student"
) {

    header("Location: ../login.php");
    exit;
}


$user_id = $_SESSION["user_id"];

$message = "";
$message_type = "";


/* =========================
   FORM VALUES
========================= */

$form_course_id = "";
$form_year_level = "";
$form_semester = "";


/* =========================
   GET STUDENT INFORMATION
========================= */

$stmt = $conn->prepare(
    "SELECT
        id,
        first_name,
        last_name
     FROM students
     WHERE user_id = ?"
);

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows !== 1) {

    die("Student record not found.");
}


$student = $result->fetch_assoc();

$student_id = $student["id"];


/* =========================
   GET ACTIVE SCHOOL YEAR
========================= */

$schoolYearStmt = $conn->prepare(
    "SELECT
        id,
        school_year
     FROM school_years
     WHERE status = 'active'
     ORDER BY id DESC
     LIMIT 1"
);

$schoolYearStmt->execute();

$schoolYearResult =
    $schoolYearStmt->get_result();


$active_school_year_id = null;
$active_school_year_name = null;


if ($schoolYearResult->num_rows === 1) {

    $active_school_year =
        $schoolYearResult->fetch_assoc();

    $active_school_year_id =
        $active_school_year["id"];

    $active_school_year_name =
        $active_school_year["school_year"];
}


/* =========================
   VERIFY CSRF FOR ALL POST
========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verify_csrf_token();
}


/* =========================
   SUBMIT NEW ENROLLMENT
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["submit_enrollment"])
) {

    $course_id =
        isset($_POST["course_id"])
        ? intval($_POST["course_id"])
        : 0;

    $year_level =
        isset($_POST["year_level"])
        ? intval($_POST["year_level"])
        : 0;

    $semester =
        isset($_POST["semester"])
        ? intval($_POST["semester"])
        : 0;


    /*
     * Keep form values if validation fails.
     */

    $form_course_id = $course_id;
    $form_year_level = $year_level;
    $form_semester = $semester;


    /* =========================
       CHECK ACTIVE SCHOOL YEAR
    ========================= */

    if ($active_school_year_id === null) {

        $message =
            "Enrollment is currently closed because there is no active school year.";

        $message_type = "error";

    }


    /* =========================
       VALIDATE YEAR LEVEL
    ========================= */

    elseif (
        $year_level < 1 ||
        $year_level > 4
    ) {

        $message =
            "Invalid year level.";

        $message_type = "error";

    }


    /* =========================
       VALIDATE SEMESTER
    ========================= */

    elseif (
        $semester < 1 ||
        $semester > 2
    ) {

        $message =
            "Invalid semester.";

        $message_type = "error";

    } else {


        /* =========================
           VALIDATE COURSE
        ========================= */

        $course_check = $conn->prepare(
            "SELECT id
             FROM courses
             WHERE id = ?
             LIMIT 1"
        );

        $course_check->bind_param(
            "i",
            $course_id
        );

        $course_check->execute();

        $course_result =
            $course_check->get_result();


        if ($course_result->num_rows !== 1) {

            $message =
                "Invalid course selected.";

            $message_type = "error";

        } else {


            /* =========================
               CHECK SAME SY + SEMESTER
            ========================= */

            $check = $conn->prepare(
                "SELECT id
                 FROM enrollments
                 WHERE student_id = ?
                 AND school_year_id = ?
                 AND semester = ?
                 AND status IN (
                    'pending',
                    'approved'
                 )
                 LIMIT 1"
            );

            $check->bind_param(
                "iii",
                $student_id,
                $active_school_year_id,
                $semester
            );

            $check->execute();

            $check_result =
                $check->get_result();


            if ($check_result->num_rows > 0) {

                $semester_name =
                    $semester === 1
                    ? "1st Semester"
                    : "2nd Semester";


                $message =
                    "You already have a pending or approved enrollment for "
                    . $semester_name
                    . " of School Year "
                    . $active_school_year_name
                    . ".";

                $message_type = "error";

            } else {


                /* =========================
                   INSERT ENROLLMENT
                ========================= */

                $insert = $conn->prepare(
                    "INSERT INTO enrollments
                    (
                        student_id,
                        course_id,
                        school_year_id,
                        year_level,
                        semester,
                        status,
                        payment_status,
                        payment_proof
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?,
                        'pending',
                        'unpaid',
                        NULL
                    )"
                );

                $insert->bind_param(
                    "iiiii",
                    $student_id,
                    $course_id,
                    $active_school_year_id,
                    $year_level,
                    $semester
                );


                if ($insert->execute()) {


                    /* =========================
                       NOTIFICATION
                    ========================= */

                    $semester_name =
                        $semester === 1
                        ? "1st Semester"
                        : "2nd Semester";


                    $notificationMessage =
                        "Your enrollment application for "
                        . $semester_name
                        . " of School Year "
                        . $active_school_year_name
                        . " has been submitted and is waiting for admin approval.";


                    $notification =
                        $conn->prepare(
                            "INSERT INTO notifications
                            (
                                user_id,
                                message
                            )
                            VALUES (?, ?)"
                        );

                    $notification->bind_param(
                        "is",
                        $user_id,
                        $notificationMessage
                    );

                    $notification->execute();


                    $message =
                        "Enrollment application for "
                        . $semester_name
                        . " of School Year "
                        . $active_school_year_name
                        . " submitted successfully.";

                    $message_type = "success";


                    /*
                     * Clear form after successful
                     * submission.
                     */

                    $form_course_id = "";
                    $form_year_level = "";
                    $form_semester = "";

                } else {

                    $message =
                        "Unable to submit enrollment application.";

                    $message_type = "error";
                }
            }
        }
    }
}


/* =========================
   CANCEL ENROLLMENT
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["cancel_enrollment"])
) {

    $enrollment_id =
        isset($_POST["enrollment_id"])
        ? intval($_POST["enrollment_id"])
        : 0;


    if ($active_school_year_id === null) {

        $message =
            "There is currently no active school year.";

        $message_type = "error";

    } else {


        /*
         * Student can only access their own
         * enrollment from the current
         * active school year.
         */

        $stmt = $conn->prepare(
            "SELECT
                id,
                payment_proof,
                status,
                semester
             FROM enrollments
             WHERE id = ?
             AND student_id = ?
             AND school_year_id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            "iii",
            $enrollment_id,
            $student_id,
            $active_school_year_id
        );

        $stmt->execute();

        $result = $stmt->get_result();


        if ($result->num_rows === 1) {

            $enrollment =
                $result->fetch_assoc();


            /*
             * Student can cancel ONLY when:
             *
             * 1. Enrollment is pending
             * 2. Payment proof has NOT been uploaded
             */

            if (
                $enrollment["status"] === "pending" &&
                empty($enrollment["payment_proof"])
            ) {

                $update = $conn->prepare(
                    "UPDATE enrollments
                     SET status = 'cancelled'
                     WHERE id = ?
                     AND student_id = ?
                     AND school_year_id = ?
                     AND status = 'pending'"
                );

                $update->bind_param(
                    "iii",
                    $enrollment_id,
                    $student_id,
                    $active_school_year_id
                );

                $update->execute();


                if ($update->affected_rows > 0) {

                    $semester_name =
                        intval($enrollment["semester"]) === 1
                        ? "1st Semester"
                        : "2nd Semester";


                    $notificationMessage =
                        "Your "
                        . $semester_name
                        . " enrollment application for School Year "
                        . $active_school_year_name
                        . " has been cancelled.";


                    $notification =
                        $conn->prepare(
                            "INSERT INTO notifications
                            (
                                user_id,
                                message
                            )
                            VALUES (?, ?)"
                        );

                    $notification->bind_param(
                        "is",
                        $user_id,
                        $notificationMessage
                    );

                    $notification->execute();


                    $message =
                        "Enrollment cancelled successfully.";

                    $message_type =
                        "success";

                } else {

                    $message =
                        "Unable to cancel enrollment.";

                    $message_type =
                        "error";
                }

            } else {

                $message =
                    "This enrollment can no longer be cancelled.";

                $message_type =
                    "error";
            }

        } else {

            $message =
                "Enrollment not found.";

            $message_type =
                "error";
        }
    }
}


/* =========================
   GET ACTIVE ENROLLMENTS
   PER SEMESTER
========================= */

$active_semesters = [];


if ($active_school_year_id !== null) {

    $activeCheck = $conn->prepare(
        "SELECT
            id,
            semester,
            status
         FROM enrollments
         WHERE student_id = ?
         AND school_year_id = ?
         AND status IN (
            'pending',
            'approved'
         )"
    );

    $activeCheck->bind_param(
        "ii",
        $student_id,
        $active_school_year_id
    );

    $activeCheck->execute();

    $activeResult =
        $activeCheck->get_result();


    while (
        $activeRow =
            $activeResult->fetch_assoc()
    ) {

        $semester_number =
            intval($activeRow["semester"]);

        $active_semesters[$semester_number] = true;
    }
}


/* =========================
   GET LATEST ENROLLMENT
   FOR EACH SEMESTER
========================= */

$current_enrollments = [];


if ($active_school_year_id !== null) {

    $stmt = $conn->prepare(
        "SELECT
            e.id,
            e.course_id,
            e.year_level,
            e.semester,
            e.status,
            e.payment_status,
            e.payment_proof,
            e.enrollment_date,
            c.course_code,
            c.course_name
         FROM enrollments e
         INNER JOIN courses c
            ON e.course_id = c.id
         WHERE e.student_id = ?
         AND e.school_year_id = ?
         ORDER BY
            e.semester ASC,
            e.enrollment_date DESC,
            e.id DESC"
    );

    $stmt->bind_param(
        "ii",
        $student_id,
        $active_school_year_id
    );

    $stmt->execute();

    $existing_result =
        $stmt->get_result();


    while (
        $row =
            $existing_result->fetch_assoc()
    ) {

        $semester_number =
            intval($row["semester"]);


        if (
            !isset(
                $current_enrollments[
                    $semester_number
                ]
            )
        ) {

            $current_enrollments[
                $semester_number
            ] = $row;
        }
    }
}


/* =========================
   AVAILABLE SEMESTERS
========================= */

$semester_1_available =
    !isset($active_semesters[1]);

$semester_2_available =
    !isset($active_semesters[2]);

$has_available_semester =
    $semester_1_available ||
    $semester_2_available;


/* =========================
   GET COURSES
========================= */

$courses = $conn->query(
    "SELECT
        id,
        course_code,
        course_name
     FROM courses
     ORDER BY course_code ASC"
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

    <title>
        Enrollment Application
    </title>


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
            width: 90%;
            max-width: 950px;
            margin: 40px auto;
        }


        .card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.08);
        }


        h1 {
            margin-top: 0;
            color: #1e3a8a;
        }


        h2 {
            color: #1e3a8a;
            margin-top: 30px;
        }


        h3 {
            margin-top: 0;
            color: #1e3a8a;
        }


        .message {
            padding: 14px;
            border-radius: 8px;
            margin: 15px 0;
        }


        .success {
            background: #dcfce7;
            color: #166534;
        }


        .error {
            background: #fee2e2;
            color: #991b1b;
        }


        .info {
            background: #dbeafe;
            color: #1e40af;
        }


        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 6px;
            font-weight: bold;
        }


        select {
            width: 100%;
            padding: 12px;
            border:
                1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            background: white;
        }


        select:focus {
            outline:
                2px solid #bfdbfe;
            border-color: #2563eb;
        }


        button,
        .btn {
            display: inline-block;
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 15px;
            margin-top: 15px;
        }


        .submit-btn {
            background: #2563eb;
            color: white;
        }


        .submit-btn:hover {
            background: #1d4ed8;
        }


        .cancel-btn {
            background: #dc2626;
            color: white;
        }


        .cancel-btn:hover {
            background: #b91c1c;
        }


        .back-btn {
            background: #6b7280;
            color: white;
        }


        .back-btn:hover {
            background: #4b5563;
        }


        .payment-btn {
            background: #16a34a;
            color: white;
        }


        .payment-btn:hover {
            background: #15803d;
        }


        .school-year {
            padding: 15px;
            background: #eff6ff;
            color: #1e40af;
            border-radius: 8px;
            margin: 20px 0;
            font-weight: bold;
        }


        .semester-grid {
            display: grid;
            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(280px, 1fr)
                );
            gap: 20px;
            margin-top: 20px;
        }


        .semester-card {
            border:
                1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            background: #f8fafc;
        }


        .semester-card p {
            line-height: 1.5;
        }


        .status {
            font-weight: bold;
        }


        .status-pending {
            color: #ca8a04;
        }


        .status-approved {
            color: #16a34a;
        }


        .status-rejected,
        .status-cancelled {
            color: #dc2626;
        }


        .payment-unpaid {
            color: #dc2626;
        }


        .payment-paid {
            color: #16a34a;
        }


        .warning {
            margin-top: 15px;
            padding: 15px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 8px;
        }


        .action-area {
            margin-top: 15px;
        }


        .action-area form {
            display: inline-block;
            margin-left: 5px;
        }


        .form-box {
            margin-top: 20px;
            padding: 20px;
            border:
                1px solid #e5e7eb;
            border-radius: 12px;
            background: #f8fafc;
        }


        .semester-note {
            margin-top: 8px;
            color: #64748b;
            font-size: 14px;
        }


        .unavailable {
            color: #991b1b;
        }


        @media (
            max-width: 600px
        ) {

            .container {
                width: 94%;
                margin: 20px auto;
            }


            .card {
                padding: 20px;
            }


            .action-area form {
                display: block;
                margin-left: 0;
            }


            .btn,
            button {
                width: 100%;
                text-align: center;
            }
        }

    </style>

</head>


<body>


<div class="container">

    <div class="card">


        <h1>
            Enrollment Application
        </h1>


        <p>

            Welcome,

            <strong>

                <?php

                echo htmlspecialchars(
                    $student["first_name"]
                    . " "
                    . $student["last_name"],
                    ENT_QUOTES,
                    "UTF-8"
                );

                ?>

            </strong>

        </p>


        <?php if (
            !empty($message)
        ): ?>

            <div
                class="message <?php echo htmlspecialchars(
                    $message_type,
                    ENT_QUOTES,
                    "UTF-8"
                ); ?>"
            >

                <?php

                echo htmlspecialchars(
                    $message,
                    ENT_QUOTES,
                    "UTF-8"
                );

                ?>

            </div>

        <?php endif; ?>


        <?php if (
            $active_school_year_id !== null
        ): ?>

            <div class="school-year">

                📅 Current School Year:

                <?php

                echo htmlspecialchars(
                    $active_school_year_name,
                    ENT_QUOTES,
                    "UTF-8"
                );

                ?>

            </div>

        <?php else: ?>

            <div class="message error">

                Enrollment is currently closed.

                <br><br>

                There is no active School Year.

            </div>

        <?php endif; ?>


        <?php if (
            $active_school_year_id !== null &&
            !empty($current_enrollments)
        ): ?>


            <h2>
                Your Current School Year Enrollments
            </h2>


            <div class="semester-grid">


                <?php

                foreach (
                    $current_enrollments
                    as $semester_number => $enrollment
                ):

                    $semester_label =
                        $semester_number === 1
                        ? "1st Semester"
                        : "2nd Semester";

                ?>


                    <div class="semester-card">


                        <h3>

                            <?php

                            echo htmlspecialchars(
                                $semester_label,
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            ?>

                        </h3>


                        <p>

                            <strong>
                                School Year:
                            </strong>

                            <br>

                            <?php

                            echo htmlspecialchars(
                                $active_school_year_name,
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            ?>

                        </p>


                        <p>

                            <strong>
                                Course:
                            </strong>

                            <br>

                            <?php

                            echo htmlspecialchars(
                                $enrollment["course_code"],
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            ?>

                            -

                            <?php

                            echo htmlspecialchars(
                                $enrollment["course_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            ?>

                        </p>


                        <p>

                            <strong>
                                Year Level:
                            </strong>

                            <?php

                            echo intval(
                                $enrollment["year_level"]
                            );

                            ?>

                        </p>


                        <p>

                            <strong>
                                Semester:
                            </strong>

                            <?php

                            echo htmlspecialchars(
                                $semester_label,
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            ?>

                        </p>


                        <p>

                            <strong>
                                Enrollment Status:
                            </strong>

                            <span
                                class="status status-<?php
                                echo htmlspecialchars(
                                    $enrollment["status"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                );
                                ?>"
                            >

                                <?php

                                echo strtoupper(
                                    htmlspecialchars(
                                        $enrollment["status"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    )
                                );

                                ?>

                            </span>

                        </p>


                        <p>

                            <strong>
                                Payment Status:
                            </strong>

                            <span
                                class="status payment-<?php
                                echo htmlspecialchars(
                                    $enrollment["payment_status"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                );
                                ?>"
                            >

                                <?php

                                echo strtoupper(
                                    htmlspecialchars(
                                        $enrollment["payment_status"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    )
                                );

                                ?>

                            </span>

                        </p>


                        <?php if (
                            $enrollment["status"] === "pending"
                        ): ?>


                            <div class="warning">

                                Your enrollment is pending.

                                <br><br>

                                Please wait for the administrator
                                to approve your enrollment before
                                uploading payment proof.

                            </div>


                            <div class="action-area">


                                <form
                                    method="POST"
                                    onsubmit="return confirm('Are you sure you want to cancel this enrollment?');"
                                >

                                    <?= csrf_field() ?>

                                    <input
                                        type="hidden"
                                        name="enrollment_id"
                                        value="<?php
                                        echo intval(
                                            $enrollment["id"]
                                        );
                                        ?>"
                                    >


                                    <button
                                        type="submit"
                                        name="cancel_enrollment"
                                        class="cancel-btn"
                                    >

                                        Cancel Enrollment

                                    </button>

                                </form>


                            </div>


                        <?php elseif (
                            $enrollment["status"] === "approved"
                        ): ?>


                            <div class="message success">

                                Your enrollment for this
                                semester has been approved.

                            </div>


                            <?php if (
                                $enrollment["payment_status"] !== "paid" &&
                                empty($enrollment["payment_proof"])
                            ): ?>

                                <div class="action-area">

                                    <a
                                        href="payment_proof.php?enrollment_id=<?= intval($enrollment["id"]) ?>"
                                        class="btn payment-btn"
                                    >
                                        Upload Payment Proof
                                    </a>

                                </div>

                            <?php elseif (
                                !empty($enrollment["payment_proof"]) &&
                                $enrollment["payment_status"] !== "paid"
                            ): ?>

                                <div class="message info">

                                    Payment proof has been uploaded.

                                    <br><br>

                                    Your payment is waiting for
                                    administrator verification.

                                </div>

                            <?php elseif (
                                $enrollment["payment_status"] === "paid"
                            ): ?>

                                <div class="message success">

                                    Payment has been verified.
                                    Your enrollment is complete.

                                </div>

                            <?php endif; ?>


                        <?php elseif (
                            $enrollment["status"] === "rejected"
                        ): ?>


                            <div class="message error">

                                Your latest enrollment application
                                for this semester was rejected.

                                <br><br>

                                You may submit a new application
                                for this semester.

                            </div>


                        <?php elseif (
                            $enrollment["status"] === "cancelled"
                        ): ?>


                            <div class="message info">

                                Your latest enrollment application
                                for this semester was cancelled.

                                <br><br>

                                You may submit a new application
                                for this semester.

                            </div>


                        <?php endif; ?>


                    </div>


                <?php endforeach; ?>


            </div>


        <?php endif; ?>


        <?php if (
            $active_school_year_id !== null
        ): ?>


            <h2>
                Apply for Enrollment
            </h2>


            <?php if (
                $has_available_semester
            ): ?>


                <div class="message info">

                    You may submit an enrollment
                    application for an available
                    semester of

                    <strong>

                        <?php

                        echo htmlspecialchars(
                            $active_school_year_name,
                            ENT_QUOTES,
                            "UTF-8"
                        );

                        ?>

                    </strong>.

                    <br><br>

                    A semester that already has
                    a pending or approved enrollment
                    cannot be selected again.

                </div>


                <div class="form-box">


                    <form method="POST">

                        <?= csrf_field() ?>


                        <label for="course_id">

                            Course

                        </label>


                        <select
                            name="course_id"
                            id="course_id"
                            required
                        >

                            <option value="">

                                -- Select Course --

                            </option>


                            <?php while (
                                $course =
                                    $courses->fetch_assoc()
                            ): ?>


                                <option
                                    value="<?php
                                    echo intval(
                                        $course["id"]
                                    );
                                    ?>"
                                    <?php

                                    if (
                                        intval(
                                            $form_course_id
                                        ) ===
                                        intval(
                                            $course["id"]
                                        )
                                    ) {

                                        echo "selected";
                                    }

                                    ?>
                                >

                                    <?php

                                    echo htmlspecialchars(
                                        $course["course_code"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>

                                    -

                                    <?php

                                    echo htmlspecialchars(
                                        $course["course_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>

                                </option>


                            <?php endwhile; ?>


                        </select>


                        <label for="year_level">

                            Year Level

                        </label>


                        <select
                            name="year_level"
                            id="year_level"
                            required
                        >

                            <option value="">

                                -- Select Year Level --

                            </option>


                            <option
                                value="1"
                                <?php
                                if (
                                    intval(
                                        $form_year_level
                                    ) === 1
                                ) {
                                    echo "selected";
                                }
                                ?>
                            >

                                1st Year

                            </option>


                            <option
                                value="2"
                                <?php
                                if (
                                    intval(
                                        $form_year_level
                                    ) === 2
                                ) {
                                    echo "selected";
                                }
                                ?>
                            >

                                2nd Year

                            </option>


                            <option
                                value="3"
                                <?php
                                if (
                                    intval(
                                        $form_year_level
                                    ) === 3
                                ) {
                                    echo "selected";
                                }
                                ?>
                            >

                                3rd Year

                            </option>


                            <option
                                value="4"
                                <?php
                                if (
                                    intval(
                                        $form_year_level
                                    ) === 4
                                ) {
                                    echo "selected";
                                }
                                ?>
                            >

                                4th Year

                            </option>


                        </select>


                        <label for="semester">

                            Semester

                        </label>


                        <select
                            name="semester"
                            id="semester"
                            required
                        >

                            <option value="">

                                -- Select Semester --

                            </option>


                            <?php if (
                                $semester_1_available
                            ): ?>

                                <option
                                    value="1"
                                    <?php
                                    if (
                                        intval(
                                            $form_semester
                                        ) === 1
                                    ) {
                                        echo "selected";
                                    }
                                    ?>
                                >

                                    1st Semester

                                </option>

                            <?php endif; ?>


                            <?php if (
                                $semester_2_available
                            ): ?>

                                <option
                                    value="2"
                                    <?php
                                    if (
                                        intval(
                                            $form_semester
                                        ) === 2
                                    ) {
                                        echo "selected";
                                    }
                                    ?>
                                >

                                    2nd Semester

                                </option>

                            <?php endif; ?>


                        </select>


                        <?php if (
                            !$semester_1_available
                        ): ?>

                            <p
                                class="semester-note unavailable"
                            >

                                1st Semester already has
                                a pending or approved enrollment.

                            </p>

                        <?php endif; ?>


                        <?php if (
                            !$semester_2_available
                        ): ?>

                            <p
                                class="semester-note unavailable"
                            >

                                2nd Semester already has
                                a pending or approved enrollment.

                            </p>

                        <?php endif; ?>


                        <button
                            type="submit"
                            name="submit_enrollment"
                            class="submit-btn"
                        >

                            Submit Enrollment

                        </button>


                    </form>


                </div>


            <?php else: ?>


                <div class="message success">

                    You already have a pending or
                    approved enrollment for both
                    semesters of School Year

                    <strong>

                        <?php

                        echo htmlspecialchars(
                            $active_school_year_name,
                            ENT_QUOTES,
                            "UTF-8"
                        );

                        ?>

                    </strong>.

                </div>


            <?php endif; ?>


        <?php endif; ?>


        <a
            href="dashboard.php"
            class="btn back-btn"
        >

            ← Back to Dashboard

        </a>


    </div>

</div>


</body>

</html>
