<?php

session_start();

require_once "../config/database.php";

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
   GET AND VALIDATE STUDENT ID
   ========================================================== */

$student_id = $_GET["student_id"] ?? "";

if (
    $student_id === "" ||
    filter_var($student_id, FILTER_VALIDATE_INT) === false ||
    (int) $student_id <= 0
) {
    http_response_code(400);
    die("Invalid student ID.");
}

$student_id = (int) $student_id;


/* ==========================================================
   GET STUDENT INFORMATION
   ========================================================== */

$stmt = $conn->prepare(
    "SELECT
        s.id,
        s.student_number,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.birth_date,
        s.gender,
        s.address,
        s.contact_number,
        s.email,
        s.created_at,

        u.username,
        u.created_at AS account_created

     FROM students s

     INNER JOIN users u
        ON s.user_id = u.id

     WHERE s.id = ?

     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    die("Unable to load student information.");
}

$stmt->bind_param("i", $student_id);

if (!$stmt->execute()) {
    $stmt->close();

    http_response_code(500);
    die("Unable to load student information.");
}

$result = $stmt->get_result();

if ($result->num_rows !== 1) {

    $stmt->close();

    http_response_code(404);
    die("Student not found.");
}

$student = $result->fetch_assoc();

$stmt->close();


/* ==========================================================
   GET ENROLLMENT HISTORY
   ========================================================== */

$stmt = $conn->prepare(
    "SELECT
        e.id,
        e.year_level,
        e.semester,
        e.status,
        e.payment_status,
        e.payment_date,
        e.payment_proof,
        e.enrollment_date,

        c.course_code,
        c.course_name

     FROM enrollments e

     INNER JOIN courses c
        ON e.course_id = c.id

     WHERE e.student_id = ?

     ORDER BY e.id DESC"
);

if (!$stmt) {
    http_response_code(500);
    die("Unable to load enrollment history.");
}

$stmt->bind_param("i", $student_id);

if (!$stmt->execute()) {
    $stmt->close();

    http_response_code(500);
    die("Unable to load enrollment history.");
}

$enrollments = $stmt->get_result();

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
        Student Details | Admin
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {
            margin: 0;

            font-family: Arial, sans-serif;

            background: #f1f5f9;

            color: #1e293b;
        }


        /* NAVBAR */

        .navbar {
            background: #1e3a8a;

            color: white;

            padding: 18px 30px;

            display: flex;

            justify-content: space-between;

            align-items: center;
        }


        .navbar h2 {
            margin: 0;
        }


        .back-button {
            color: white;

            text-decoration: none;

            background: #475569;

            padding: 9px 15px;

            border-radius: 7px;
        }


        .back-button:hover {
            background: #334155;
        }


        /* CONTAINER */

        .container {
            max-width: 1100px;

            margin: 35px auto;

            padding: 0 20px;
        }


        /* TITLE */

        .page-title {
            margin-bottom: 25px;
        }


        .page-title h1 {
            margin: 0 0 5px 0;
        }


        .page-title p {
            margin: 0;

            color: #64748b;
        }


        /* CARD */

        .card {
            background: white;

            padding: 25px;

            border-radius: 12px;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.08);

            margin-bottom: 25px;
        }


        .card h2 {
            margin-top: 0;

            color: #1e3a8a;
        }


        /* PROFILE */

        .profile-grid {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 18px;
        }


        .info-box {
            background: #f8fafc;

            padding: 15px;

            border-radius: 8px;

            border: 1px solid #e2e8f0;
        }


        .info-label {
            font-size: 12px;

            color: #64748b;

            margin-bottom: 5px;

            font-weight: bold;

            text-transform: uppercase;
        }


        .info-value {
            font-size: 15px;

            color: #1e293b;

            word-break: break-word;
        }


        /* TABLE */

        .table-container {
            overflow-x: auto;
        }


        table {
            width: 100%;

            border-collapse: collapse;

            min-width: 850px;
        }


        th,
        td {
            padding: 13px;

            border-bottom:
                1px solid #e2e8f0;

            text-align: left;

            vertical-align: middle;
        }


        th {
            background: #f8fafc;

            font-size: 14px;
        }


        td {
            font-size: 14px;
        }


        /* STATUS */

        .status {
            display: inline-block;

            padding: 5px 10px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: bold;
        }


        .pending {
            background: #fef3c7;

            color: #92400e;
        }


        .approved,
        .paid {
            background: #dcfce7;

            color: #166534;
        }


        .rejected,
        .unpaid,
        .cancelled {
            background: #fee2e2;

            color: #991b1b;
        }


        /* PAYMENT PROOF */

        .proof-button {
            display: inline-block;

            padding: 7px 10px;

            background: #7c3aed;

            color: white;

            text-decoration: none;

            border-radius: 6px;

            font-size: 12px;
        }


        .proof-button:hover {
            background: #6d28d9;
        }


        .no-proof {
            color: #64748b;

            font-size: 12px;
        }


        /* EMPTY */

        .empty {
            text-align: center;

            color: #64748b;

            padding: 25px;
        }


        /* MOBILE */

        @media (max-width: 700px) {

            .navbar {
                padding: 15px 20px;
            }


            .navbar h2 {
                font-size: 19px;
            }


            .container {
                margin-top: 25px;
            }


            .profile-grid {
                grid-template-columns: 1fr;
            }


            .card {
                padding: 18px;
            }

        }

    </style>

</head>


<body>


<div class="navbar">

    <h2>
        👨‍🎓 Student Details
    </h2>


    <a
        href="dashboard.php"
        class="back-button"
    >
        ← Back to Dashboard
    </a>

</div>


<div class="container">


    <!-- TITLE -->

    <div class="page-title">

        <h1>
            <?= htmlspecialchars(
                $student["first_name"] . " " .
                (
                    !empty($student["middle_name"])
                        ? $student["middle_name"] . " "
                        : ""
                ) .
                $student["last_name"],
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </h1>


        <p>
            Complete student information and enrollment history.
        </p>

    </div>


    <!-- PERSONAL INFORMATION -->

    <div class="card">

        <h2>
            👤 Personal Information
        </h2>


        <div class="profile-grid">


            <div class="info-box">

                <div class="info-label">
                    Student Number
                </div>


                <div class="info-value">

                    <?= htmlspecialchars(
                        $student["student_number"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Username
                </div>


                <div class="info-value">

                    <?= htmlspecialchars(
                        $student["username"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    First Name
                </div>


                <div class="info-value">

                    <?= htmlspecialchars(
                        $student["first_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Middle Name
                </div>


                <div class="info-value">

                    <?= !empty($student["middle_name"])
                        ? htmlspecialchars(
                            $student["middle_name"],
                            ENT_QUOTES,
                            "UTF-8"
                        )
                        : "Not provided"
                    ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Last Name
                </div>


                <div class="info-value">

                    <?= htmlspecialchars(
                        $student["last_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Birth Date
                </div>


                <div class="info-value">

                    <?= !empty($student["birth_date"])
                        ? htmlspecialchars(
                            $student["birth_date"],
                            ENT_QUOTES,
                            "UTF-8"
                        )
                        : "Not provided"
                    ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Gender
                </div>


                <div class="info-value">

                    <?= !empty($student["gender"])
                        ? htmlspecialchars(
                            $student["gender"],
                            ENT_QUOTES,
                            "UTF-8"
                        )
                        : "Not provided"
                    ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Contact Number
                </div>


                <div class="info-value">

                    <?= !empty($student["contact_number"])
                        ? htmlspecialchars(
                            $student["contact_number"],
                            ENT_QUOTES,
                            "UTF-8"
                        )
                        : "Not provided"
                    ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Email
                </div>


                <div class="info-value">

                    <?= !empty($student["email"])
                        ? htmlspecialchars(
                            $student["email"],
                            ENT_QUOTES,
                            "UTF-8"
                        )
                        : "Not provided"
                    ?>

                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Address
                </div>


                <div class="info-value">

                    <?= !empty($student["address"])
                        ? htmlspecialchars(
                            $student["address"],
                            ENT_QUOTES,
                            "UTF-8"
                        )
                        : "Not provided"
                    ?>

                </div>

            </div>


        </div>

    </div>


    <!-- ENROLLMENT HISTORY -->

    <div class="card">

        <h2>
            📋 Enrollment History
        </h2>


        <div class="table-container">

            <table>

                <thead>

                    <tr>

                        <th>Course</th>

                        <th>Year</th>

                        <th>Semester</th>

                        <th>Enrollment Status</th>

                        <th>Payment</th>

                        <th>Payment Proof</th>

                        <th>Date</th>

                    </tr>

                </thead>


                <tbody>


                <?php if ($enrollments->num_rows === 0): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="empty"
                        >
                            No enrollment history found.
                        </td>

                    </tr>

                <?php else: ?>


                    <?php while (
                        $row = $enrollments->fetch_assoc()
                    ): ?>

                        <tr>


                            <!-- COURSE -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $row["course_code"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </strong>

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

                                <?= htmlspecialchars(
                                    (string) $row["year_level"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <!-- SEMESTER -->

                            <td>

                                <?= htmlspecialchars(
                                    (string) $row["semester"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

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

                                    <span class="status cancelled">
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


                                    <?php if (
                                        !empty($row["payment_date"])
                                    ): ?>

                                        <br>

                                        <small>

                                            <?= htmlspecialchars(
                                                $row["payment_date"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </small>

                                    <?php endif; ?>


                                <?php else: ?>

                                    <span class="status unpaid">
                                        Unpaid
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- PAYMENT PROOF -->

                            <td>

                                <?php if (
                                    !empty($row["payment_proof"])
                                ): ?>

                                    <?php
                                    $proof_file = basename(
                                        $row["payment_proof"]
                                    );
                                    ?>


                                    <a
                                        href="../uploads/<?= htmlspecialchars(
                                            $proof_file,
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="proof-button"
                                    >
                                        👁 View Proof
                                    </a>

                                <?php else: ?>

                                    <span class="no-proof">
                                        No Proof
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ENROLLMENT DATE -->

                            <td>

                                <?= htmlspecialchars(
                                    $row["enrollment_date"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                        </tr>

                    <?php endwhile; ?>

                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>


</div>


</body>

</html>

<?php

$stmt->close();

?>
