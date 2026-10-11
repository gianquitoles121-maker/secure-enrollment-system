<?php

session_start();

require_once "../config/database.php";


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
$username = $_SESSION["username"];


/*
|--------------------------------------------------------------------------
| GET ACTIVE SCHOOL YEAR
|--------------------------------------------------------------------------
*/

$activeSchoolYear = null;
$activeSchoolYearId = null;


$stmt = $conn->prepare(
    "SELECT
        id,
        school_year
     FROM school_years
     WHERE status = 'active'
     ORDER BY id DESC
     LIMIT 1"
);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows === 1) {

    $activeSchoolYear = $result->fetch_assoc();

    $activeSchoolYearId =
        (int) $activeSchoolYear["id"];
}


$stmt->close();


/*
|--------------------------------------------------------------------------
| GET CURRENT SCHOOL YEAR ENROLLMENTS
|--------------------------------------------------------------------------
*/

$enrollments = [];


if ($activeSchoolYearId !== null) {

    $stmt = $conn->prepare(
        "SELECT
            e.id,
            c.course_code,
            c.course_name,
            e.year_level,
            e.semester,
            e.status,
            e.payment_status,
            e.payment_date,
            e.payment_proof,
            e.enrollment_date

         FROM enrollments e

         INNER JOIN courses c
            ON e.course_id = c.id

         INNER JOIN students s
            ON e.student_id = s.id

         WHERE s.user_id = ?
         AND e.school_year_id = ?

         AND e.id = (
             SELECT MAX(e2.id)
             FROM enrollments e2
             WHERE e2.student_id = e.student_id
             AND e2.school_year_id = e.school_year_id
             AND e2.semester = e.semester
         )

         ORDER BY e.semester ASC"
    );


    $stmt->bind_param(
        "ii",
        $user_id,
        $activeSchoolYearId
    );


    $stmt->execute();

    $result = $stmt->get_result();


    while ($row = $result->fetch_assoc()) {

        $enrollments[] = $row;
    }


    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function getStatusClass($status)
{
    $status = strtolower($status);

    if (
        in_array(
            $status,
            [
                "pending",
                "approved",
                "rejected",
                "cancelled"
            ],
            true
        )
    ) {
        return $status;
    }

    return "";
}


function getPaymentClass($paymentStatus)
{
    return $paymentStatus === "paid"
        ? "paid"
        : "unpaid";
}


function getSemesterName($semester)
{
    return ((int) $semester === 1)
        ? "1st Semester"
        : "2nd Semester";
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
        Enrollment Status
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


        .navbar {

            background: #1d4ed8;

            color: white;

            padding: 18px 30px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            box-shadow:
                0 4px 12px rgba(0,0,0,0.12);

        }


        .navbar h2 {

            margin: 0;

            font-size: 22px;

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

            width: 92%;

            max-width: 900px;

            margin: 35px auto;

        }


        .header-card {

            background: white;

            padding: 25px;

            border-radius: 14px;

            box-shadow:
                0 5px 20px
                rgba(0,0,0,0.06);

            margin-bottom: 20px;

        }


        .header-card h1 {

            margin-top: 0;

            color: #1e3a8a;

        }


        .header-card p {

            color: #64748b;

        }


        .school-year {

            display: inline-block;

            margin-top: 8px;

            padding: 8px 14px;

            border-radius: 20px;

            background: #dbeafe;

            color: #1d4ed8;

            font-size: 13px;

            font-weight: bold;

        }


        .no-active-year {

            display: inline-block;

            margin-top: 8px;

            padding: 8px 14px;

            border-radius: 20px;

            background: #fee2e2;

            color: #991b1b;

            font-size: 13px;

            font-weight: bold;

        }


        .enrollment-card {

            background: white;

            padding: 25px;

            border-radius: 14px;

            box-shadow:
                0 5px 20px
                rgba(0,0,0,0.06);

            margin-bottom: 20px;

        }


        .enrollment-card h2 {

            margin-top: 0;

            color: #1e3a8a;

        }


        .semester-title {

            display: inline-block;

            margin-bottom: 15px;

            padding: 7px 12px;

            background: #eff6ff;

            color: #1d4ed8;

            border-radius: 7px;

            font-size: 13px;

            font-weight: bold;

        }


        .info {

            margin: 10px 0;

            line-height: 1.5;

        }


        .label {

            font-weight: bold;

        }


        .status {

            display: inline-block;

            padding: 6px 12px;

            border-radius: 20px;

            font-size: 13px;

            font-weight: bold;

        }


        .pending {

            background: #fef3c7;

            color: #92400e;

        }


        .approved {

            background: #dcfce7;

            color: #166534;

        }


        .rejected {

            background: #fee2e2;

            color: #991b1b;

        }


        .cancelled {

            background: #e2e8f0;

            color: #475569;

        }


        .paid {

            background: #dcfce7;

            color: #166534;

        }


        .unpaid {

            background: #fee2e2;

            color: #991b1b;

        }


        .proof {

            margin-top: 18px;

        }


        .proof img {

            max-width: 300px;

            max-height: 300px;

            border-radius: 8px;

            border: 1px solid #ddd;

            margin-top: 8px;

        }


        /*
        |--------------------------------------------------------------------------
        | PAYMENT BUTTON
        |--------------------------------------------------------------------------
        */

        .payment-button {

            display: inline-block;

            margin-top: 20px;

            padding: 11px 18px;

            background: #16a34a;

            color: white;

            border: none;

            text-decoration: none;

            border-radius: 8px;

            font-weight: bold;

            cursor: pointer;

            font-size: 14px;

        }


        .payment-button:hover {

            background: #15803d;

        }


        .paid-message {

            margin-top: 20px;

            padding: 11px 15px;

            background: #dcfce7;

            color: #166534;

            border-radius: 8px;

            font-weight: bold;

            display: inline-block;

        }


        .no-record {

            background: white;

            padding: 35px;

            border-radius: 14px;

            text-align: center;

            color: #64748b;

            box-shadow:
                0 5px 20px
                rgba(0,0,0,0.06);

        }


        .no-record h3 {

            color: #334155;

        }


        .back {

            display: inline-block;

            margin-top: 10px;

            padding: 11px 18px;

            background: #2563eb;

            color: white;

            text-decoration: none;

            border-radius: 8px;

            font-weight: bold;

        }


        .back:hover {

            background: #1d4ed8;

        }


        @media (max-width: 600px) {

            .navbar {

                padding: 15px 18px;

            }


            .navbar h2 {

                font-size: 18px;

            }


            .proof img {

                max-width: 100%;

            }


            .container {

                width: 94%;

                margin: 25px auto;

            }


            .enrollment-card {

                padding: 20px;

            }

        }

    </style>

</head>


<body>


<div class="navbar">

    <h2>
        Enrollment Status
    </h2>


    <a
        href="../logout.php"
        class="logout"
    >

        Logout

    </a>

</div>


<div class="container">


    <div class="header-card">


        <h1>
            Enrollment Status
        </h1>


        <p>

            Student:

            <strong>

                <?= htmlspecialchars(
                    $username,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </strong>

        </p>


        <?php if ($activeSchoolYear !== null): ?>

            <span class="school-year">

                Current School Year:

                <?= htmlspecialchars(
                    $activeSchoolYear["school_year"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </span>

        <?php else: ?>

            <span class="no-active-year">

                No Active School Year

            </span>

        <?php endif; ?>


    </div>


    <?php if ($activeSchoolYearId === null): ?>


        <div class="no-record">

            <h3>
                No Active School Year
            </h3>


            <p>

                There is currently no active
                school year for enrollment.

            </p>

        </div>


    <?php elseif (empty($enrollments)): ?>


        <div class="no-record">

            <h3>
                No Current Enrollment
            </h3>


            <p>

                You do not have an enrollment
                record for the current school year.

            </p>


            <p>

                You may submit a new enrollment
                through the Enrollment page.

            </p>

        </div>


    <?php else: ?>


        <?php foreach ($enrollments as $enrollment): ?>


            <div class="enrollment-card">


                <div class="semester-title">

                    <?= htmlspecialchars(
                        getSemesterName(
                            $enrollment["semester"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>


                <h2>

                    <?= htmlspecialchars(
                        $enrollment["course_code"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                    -

                    <?= htmlspecialchars(
                        $enrollment["course_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </h2>


                <div class="info">

                    <span class="label">
                        School Year:
                    </span>

                    <?= htmlspecialchars(
                        $activeSchoolYear["school_year"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>


                <div class="info">

                    <span class="label">
                        Year Level:
                    </span>

                    <?= htmlspecialchars(
                        $enrollment["year_level"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>


                <div class="info">

                    <span class="label">
                        Semester:
                    </span>

                    <?= htmlspecialchars(
                        getSemesterName(
                            $enrollment["semester"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>


                <div class="info">

                    <span class="label">
                        Enrollment Date:
                    </span>

                    <?= date(
                        "M d, Y h:i A",
                        strtotime(
                            $enrollment["enrollment_date"]
                        )
                    ) ?>

                </div>


                <div class="info">

                    <span class="label">
                        Enrollment Status:
                    </span>


                    <span
                        class="status <?= htmlspecialchars(
                            getStatusClass(
                                $enrollment["status"]
                            ),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                        <?= htmlspecialchars(
                            ucfirst(
                                $enrollment["status"]
                            ),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </span>

                </div>


                <div class="info">

                    <span class="label">
                        Payment Status:
                    </span>


                    <span
                        class="status <?= getPaymentClass(
                            $enrollment["payment_status"]
                        ) ?>"
                    >

                        <?= htmlspecialchars(
                            ucfirst(
                                $enrollment["payment_status"]
                            ),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </span>

                </div>


                <?php if (!empty($enrollment["payment_date"])): ?>

                    <div class="info">

                        <span class="label">
                            Payment Date:
                        </span>

                        <?= date(
                            "M d, Y h:i A",
                            strtotime(
                                $enrollment["payment_date"]
                            )
                        ) ?>

                    </div>

                <?php endif; ?>


                <?php if (!empty($enrollment["payment_proof"])): ?>

                    <div class="proof">

                        <div class="label">
                            Payment Proof:
                        </div>


                        <img
                            src="../uploads/<?= htmlspecialchars(
                                basename(
                                    $enrollment["payment_proof"]
                                ),
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            alt="Payment Proof"
                        >

                    </div>

                <?php endif; ?>


                <?php if (
                    $enrollment["payment_status"] !== "paid" && $enrollment["status"] === "approved"
                ): ?>


                    <a
                        href="payment_proof.php?enrollment_id=<?= (int) $enrollment["id"] ?>"
                        class="payment-button"
                    >

                        💳
                        <?= !empty($enrollment["payment_proof"])
                            ? "Update Payment Proof"
                            : "Upload Payment Proof" ?>

                    </a>


                <?php elseif (
                    $enrollment["payment_status"] === "paid"
                ): ?>


                    <div class="paid-message">

                        ✓ Payment Verified

                    </div>


                <?php endif; ?>


            </div>


        <?php endforeach; ?>


    <?php endif; ?>


    <a
        href="./dashboard.php"
        class="back"
    >

        ← Back to Dashboard

    </a>


</div>


</body>

</html>