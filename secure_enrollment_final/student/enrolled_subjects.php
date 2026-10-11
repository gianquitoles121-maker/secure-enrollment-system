<?php

session_start();

require_once "../config/database.php";


/* =========================
   STUDENT ACCESS PROTECTION
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


/* =========================
   GET STUDENT
========================= */

$stmt = $conn->prepare("
    SELECT
        id,
        student_number,
        first_name,
        middle_name,
        last_name
    FROM students
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$student_result =
    $stmt->get_result();


if ($student_result->num_rows !== 1) {

    die("Student record not found.");
}


$student =
    $student_result->fetch_assoc();

$student_id =
    $student["id"];


/* =========================
   GET ACTIVE SCHOOL YEAR
========================= */

$stmt = $conn->prepare("
    SELECT
        id,
        school_year
    FROM school_years
    WHERE status = 'active'
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute();

$school_year_result =
    $stmt->get_result();


$active_school_year = null;
$active_school_year_id = null;
$active_school_year_name = null;


if (
    $school_year_result->num_rows === 1
) {

    $active_school_year =
        $school_year_result->fetch_assoc();

    $active_school_year_id =
        $active_school_year["id"];

    $active_school_year_name =
        $active_school_year["school_year"];
}


/* =========================
   GET APPROVED ENROLLMENTS
   FOR ACTIVE SCHOOL YEAR
========================= */

$enrollments = [];


if (
    $active_school_year_id !== null
) {

    /*
     * IMPORTANT:
     *
     * Only approved AND PAID enrollments from the
     * CURRENT active school year are used.
     *
     * Old school year enrollments are ignored
     * here but remain safely stored in the database
     * and can still be viewed through Enrollment History.
     */

    $stmt = $conn->prepare("
        SELECT
            e.id,
            e.course_id,
            e.year_level,
            e.semester,
            e.status,
            c.course_code,
            c.course_name
        FROM enrollments e
        INNER JOIN courses c
            ON e.course_id = c.id
        WHERE e.student_id = ?
          AND e.school_year_id = ?
          AND e.status = 'approved'
          AND e.payment_status = 'paid'
        ORDER BY
            e.semester ASC,
            e.id DESC
    ");

    $stmt->bind_param(
        "ii",
        $student_id,
        $active_school_year_id
    );

    $stmt->execute();

    $enrollment_result =
        $stmt->get_result();


    while (
        $row =
            $enrollment_result->fetch_assoc()
    ) {

        /*
         * One approved enrollment per semester
         * should be displayed.
         *
         * If there happens to be more than one
         * approved record for the same semester,
         * use the latest one.
         */

        $semester =
            (int) $row["semester"];


        if (
            !isset(
                $enrollments[$semester]
            )
        ) {

            $enrollments[$semester] =
                $row;
        }
    }
}


/* =========================
   GET SUBJECTS
========================= */

$subjects_by_semester = [];

$total_units_by_semester = [];


foreach (
    $enrollments
    as $semester => $enrollment
) {

    $subjects = [];
    $total_units = 0;


    $stmt = $conn->prepare("
        SELECT
            id,
            subject_code,
            subject_name,
            units,
            day,
            start_time,
            end_time,
            room,
            instructor
        FROM subjects
        WHERE course_id = ?
          AND year_level = ?
          AND semester = ?
        ORDER BY
            CASE day
                WHEN 'Monday' THEN 1
                WHEN 'Tuesday' THEN 2
                WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4
                WHEN 'Friday' THEN 5
                WHEN 'Saturday' THEN 6
                ELSE 7
            END,
            start_time ASC,
            subject_code ASC
    ");


    $stmt->bind_param(
        "iii",
        $enrollment["course_id"],
        $enrollment["year_level"],
        $enrollment["semester"]
    );


    $stmt->execute();


    $subject_result =
        $stmt->get_result();


    while (
        $row =
            $subject_result->fetch_assoc()
    ) {

        $subjects[] = $row;

        $total_units +=
            (int) $row["units"];
    }


    $subjects_by_semester[$semester] =
        $subjects;

    $total_units_by_semester[$semester] =
        $total_units;
}


/* =========================
   HELPER FOR YEAR LEVEL
========================= */

$year_labels = [
    1 => "1st Year",
    2 => "2nd Year",
    3 => "3rd Year",
    4 => "4th Year"
];

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
        My Enrolled Subjects
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
            width: 95%;
            max-width: 1200px;
            margin: 30px auto;
        }


        .header {
            background: white;
            padding: 25px;
            border-radius: 14px;
            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
        }


        .header h1 {
            margin: 0 0 8px;
            font-size: 28px;
            color: #1e3a8a;
        }


        .header p {
            margin: 5px 0;
            color: #6b7280;
        }


        .school-year-box {
            margin-top: 18px;
            padding: 15px;
            background: #eff6ff;
            color: #1e40af;
            border-radius: 10px;
            font-weight: bold;
        }


        .back-button {
            display: inline-block;
            margin-top: 15px;
            background: #374151;
            color: white;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
        }


        .back-button:hover {
            background: #1f2937;
        }


        .semester-section {
            margin-bottom: 30px;
        }


        .semester-title {
            background: #1e3a8a;
            color: white;
            padding: 16px 20px;
            border-radius: 12px 12px 0 0;
            margin: 0;
        }


        .info-grid {
            display: grid;
            grid-template-columns:
                repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 15px;
            margin-top: 15px;
        }


        .info-card {
            background: white;
            padding: 20px;
            border-radius: 14px;
            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.06);
        }


        .info-card .label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 7px;
        }


        .info-card .value {
            font-size: 17px;
            font-weight: bold;
        }


        .subjects-card {
            background: white;
            padding: 25px;
            border-radius: 0 0 14px 14px;
            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.06);
        }


        .subjects-card h3 {
            margin-top: 0;
            color: #1e3a8a;
        }


        .table-wrapper {
            overflow-x: auto;
        }


        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }


        th,
        td {
            padding: 13px 11px;
            border-bottom:
                1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }


        th {
            background: #f9fafb;
            font-size: 13px;
        }


        td {
            font-size: 14px;
        }


        tr:hover {
            background: #f9fafb;
        }


        .subject-code {
            font-weight: bold;
            color: #2563eb;
        }


        .units {
            font-weight: bold;
            text-align: center;
        }


        .schedule-day {
            font-weight: bold;
        }


        .schedule-time {
            color: #4b5563;
            margin-top: 4px;
        }


        .no-schedule {
            color: #9ca3af;
        }


        .total-row {
            background: #eff6ff;
            font-weight: bold;
        }


        .total-row td {
            border-bottom: none;
        }


        .empty-box {
            background: white;
            padding: 40px 25px;
            text-align: center;
            border-radius: 14px;
            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
        }


        .empty-box h2 {
            margin-top: 0;
            color: #1e3a8a;
        }


        .empty-box p {
            color: #6b7280;
            line-height: 1.6;
        }


        .status-message {
            background: #fff7ed;
            border-left:
                5px solid #f59e0b;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }


        .student-info {
            background: white;
            padding: 20px;
            border-radius: 14px;
            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
        }


        .student-info strong {
            color: #1e3a8a;
        }


        @media (max-width: 900px) {

            .info-grid {
                grid-template-columns:
                    repeat(2, 1fr);
            }

        }


        @media (max-width: 600px) {

            .container {
                width: 94%;
                margin: 15px auto;
            }


            .info-grid {
                grid-template-columns: 1fr;
            }


            .header h1 {
                font-size: 23px;
            }


            .semester-title {
                font-size: 18px;
            }

        }

    </style>

</head>


<body>


<div class="container">


    <div class="header">

        <h1>
            📚 My Enrolled Subjects
        </h1>


        <p>
            View your subjects, units, and class schedule
            for your current school year.
        </p>


        <?php if (
            $active_school_year_id !== null
        ): ?>

            <div class="school-year-box">

                📅 Active School Year:

                <?= htmlspecialchars(
                    $active_school_year_name,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <a
            href="dashboard.php"
            class="back-button"
        >

            ← Back to Dashboard

        </a>

    </div>


    <!-- =========================
         STUDENT INFORMATION
    ========================== -->


    <div class="student-info">

        <strong>
            Student Number:
        </strong>

        <?= htmlspecialchars(
            $student["student_number"],
            ENT_QUOTES,
            "UTF-8"
        ) ?>

        &nbsp;&nbsp; | &nbsp;&nbsp;

        <strong>
            Student:
        </strong>

        <?= htmlspecialchars(
            $student["first_name"]
            . " "
            . $student["last_name"],
            ENT_QUOTES,
            "UTF-8"
        ) ?>

    </div>


    <?php if (
        $active_school_year_id === null
    ): ?>


        <div class="empty-box">

            <h2>
                📅 No Active School Year
            </h2>


            <p>

                There is currently no active
                school year.

            </p>


            <p>

                Your previous enrollment records
                are preserved in the system.

            </p>

        </div>


    <?php elseif (
        empty($enrollments)
    ): ?>


        <div class="empty-box">

            <h2>
                📋 No Approved Enrollment
            </h2>


            <p>

                You do not currently have an
                approved enrollment for

                <strong>

                    <?= htmlspecialchars(
                        $active_school_year_name,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </strong>.

            </p>


            <p>

                Your subjects will appear here
                once your enrollment has been
                approved by the administrator.

            </p>

        </div>


    <?php else: ?>


        <?php

        ksort($enrollments);

        ?>


        <?php foreach (
            $enrollments
            as $semester => $enrollment
        ): ?>


            <?php

            $semester_label =
                ((int) $semester === 1)
                ? "1st Semester"
                : "2nd Semester";

            $semester_subjects =
                $subjects_by_semester[$semester]
                ?? [];

            $semester_units =
                $total_units_by_semester[$semester]
                ?? 0;

            ?>


            <div class="semester-section">


                <h2 class="semester-title">

                    📖
                    <?= htmlspecialchars(
                        $semester_label,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                    —
                    <?= htmlspecialchars(
                        $active_school_year_name,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </h2>


                <!-- ENROLLMENT INFORMATION -->


                <div class="info-grid">


                    <div class="info-card">

                        <div class="label">
                            Course
                        </div>

                        <div class="value">

                            <?= htmlspecialchars(
                                $enrollment["course_code"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </div>

                    </div>


                    <div class="info-card">

                        <div class="label">
                            Year Level
                        </div>

                        <div class="value">

                            <?= htmlspecialchars(
                                $year_labels[
                                    (int)
                                    $enrollment[
                                        "year_level"
                                    ]
                                ]
                                ??
                                $enrollment[
                                    "year_level"
                                ],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </div>

                    </div>


                    <div class="info-card">

                        <div class="label">
                            Semester
                        </div>

                        <div class="value">

                            <?= htmlspecialchars(
                                $semester_label,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </div>

                    </div>


                    <div class="info-card">

                        <div class="label">
                            Enrollment Status
                        </div>

                        <div class="value">

                            ✅ Approved

                        </div>

                    </div>


                </div>


                <?php if (
                    empty($semester_subjects)
                ): ?>


                    <div class="empty-box">

                        <h2>
                            📚 No Subjects Yet
                        </h2>


                        <p>

                            No subjects have been assigned
                            by the administrator for your

                            <?= htmlspecialchars(
                                $enrollment[
                                    "course_code"
                                ],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                            —

                            <?= htmlspecialchars(
                                $year_labels[
                                    (int)
                                    $enrollment[
                                        "year_level"
                                    ]
                                ]
                                ??
                                $enrollment[
                                    "year_level"
                                ],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                            —

                            <?= htmlspecialchars(
                                $semester_label,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                            yet.

                        </p>

                    </div>


                <?php else: ?>


                    <div class="subjects-card">


                        <h3>
                            📖 Subject List
                        </h3>


                        <div class="table-wrapper">


                            <table>


                                <thead>

                                    <tr>

                                        <th>
                                            #
                                        </th>

                                        <th>
                                            Subject Code
                                        </th>

                                        <th>
                                            Subject Name
                                        </th>

                                        <th>
                                            Units
                                        </th>

                                        <th>
                                            Day
                                        </th>

                                        <th>
                                            Time
                                        </th>

                                        <th>
                                            Room
                                        </th>

                                        <th>
                                            Instructor
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>


                                <?php foreach (
                                    $semester_subjects
                                    as $index => $subject
                                ): ?>


                                    <tr>


                                        <td>
                                            <?= $index + 1 ?>
                                        </td>


                                        <td>

                                            <span
                                                class="subject-code"
                                            >

                                                <?= htmlspecialchars(
                                                    $subject[
                                                        "subject_code"
                                                    ],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </span>

                                        </td>


                                        <td>

                                            <?= htmlspecialchars(
                                                $subject[
                                                    "subject_name"
                                                ],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </td>


                                        <td class="units">

                                            <?= (int)
                                                $subject[
                                                    "units"
                                                ] ?>

                                        </td>


                                        <td>

                                            <?php if (
                                                !empty(
                                                    $subject["day"]
                                                )
                                            ): ?>

                                                <span
                                                    class="schedule-day"
                                                >

                                                    📅

                                                    <?= htmlspecialchars(
                                                        $subject[
                                                            "day"
                                                        ],
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>

                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="no-schedule"
                                                >

                                                    —

                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <?php if (
                                                !empty(
                                                    $subject[
                                                        "start_time"
                                                    ]
                                                ) &&
                                                !empty(
                                                    $subject[
                                                        "end_time"
                                                    ]
                                                )
                                            ): ?>

                                                <span
                                                    class="schedule-time"
                                                >

                                                    🕐

                                                    <?= date(
                                                        "g:i A",
                                                        strtotime(
                                                            $subject[
                                                                "start_time"
                                                            ]
                                                        )
                                                    ) ?>

                                                    -

                                                    <?= date(
                                                        "g:i A",
                                                        strtotime(
                                                            $subject[
                                                                "end_time"
                                                            ]
                                                        )
                                                    ) ?>

                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="no-schedule"
                                                >

                                                    —

                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <?php if (
                                                !empty(
                                                    $subject[
                                                        "room"
                                                    ]
                                                )
                                            ): ?>

                                                🏫

                                                <?= htmlspecialchars(
                                                    $subject[
                                                        "room"
                                                    ],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            <?php else: ?>

                                                <span
                                                    class="no-schedule"
                                                >

                                                    —

                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <?php if (
                                                !empty(
                                                    $subject[
                                                        "instructor"
                                                    ]
                                                )
                                            ): ?>

                                                👨‍🏫

                                                <?= htmlspecialchars(
                                                    $subject[
                                                        "instructor"
                                                    ],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            <?php else: ?>

                                                <span
                                                    class="no-schedule"
                                                >

                                                    —

                                                </span>

                                            <?php endif; ?>

                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                                    <tr
                                        class="total-row"
                                    >

                                        <td colspan="3">

                                            TOTAL UNITS

                                        </td>


                                        <td class="units">

                                            <?= $semester_units ?>

                                        </td>


                                        <td colspan="4"></td>

                                    </tr>


                                </tbody>


                            </table>


                        </div>


                    </div>


                <?php endif; ?>


            </div>


        <?php endforeach; ?>


    <?php endif; ?>


</div>


</body>

</html>