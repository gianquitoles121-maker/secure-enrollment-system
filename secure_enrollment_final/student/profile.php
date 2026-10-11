<?php

session_start();

require_once "../config/database.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "student") {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION["user_id"];

$updated = isset($_GET["updated"]) && $_GET["updated"] == "1";

$stmt = $conn->prepare(
    "SELECT
        users.username,
        students.student_number,
        students.first_name,
        students.middle_name,
        students.last_name,
        students.birth_date,
        students.gender,
        students.address,
        students.contact_number,
        students.email
     FROM users
     INNER JOIN students ON users.id = students.user_id
     WHERE users.id = ?"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Student profile not found.");
}

$student = $result->fetch_assoc();

$full_name = $student["first_name"];

if (!empty($student["middle_name"])) {
    $full_name .= " " . $student["middle_name"];
}

$full_name .= " " . $student["last_name"];

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile - Secure Enrollment</title>

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
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .profile-card {
            background: white;
            border-radius: 12px;
            padding: 30px;

            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        .profile-header {
            display: flex;
            justify-content: space-between;
            align-items: center;

            margin-bottom: 25px;
        }

        .profile-header h1 {
            margin: 0;
            color: #222;
        }

        /* BUTTONS */

        .profile-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .edit-btn,
        .password-btn {
            color: white;
            text-decoration: none;

            padding: 11px 18px;

            border-radius: 8px;

            font-weight: bold;

            display: inline-block;
        }

        .edit-btn {
            background: #0d6efd;
        }

        .edit-btn:hover {
            background: #0b5ed7;
        }

        .password-btn {
            background: #6c757d;
        }

        .password-btn:hover {
            background: #5c636a;
        }

        /* SUCCESS MESSAGE */

        .success-message {
            background: #d1e7dd;
            color: #0f5132;

            padding: 13px 15px;

            border-radius: 8px;

            margin-bottom: 20px;

            font-weight: 500;
        }

        /* PROFILE INFORMATION */

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }

        .info-box.full {
            grid-column: span 2;
        }

        .label {
            font-size: 13px;
            color: #777;
            margin-bottom: 6px;
        }

        .value {
            font-size: 16px;
            font-weight: 500;
            word-break: break-word;
        }

        /* BACK BUTTON */

        .back {
            display: inline-block;
            margin-top: 25px;

            text-decoration: none;
            color: #0d6efd;
            font-weight: bold;
        }

        .back:hover {
            text-decoration: underline;
        }

        /* MOBILE */

        @media (max-width: 700px) {

            .navbar {
                padding: 15px 20px;
            }

            .profile-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .profile-actions {
                width: 100%;
                flex-direction: column;
            }

            .edit-btn,
            .password-btn {
                width: 100%;
                text-align: center;
            }

            .profile-grid {
                grid-template-columns: 1fr;
            }

            .info-box.full {
                grid-column: span 1;
            }

        }

    </style>

</head>

<body>

    <div class="navbar">

        <h2>Secure Enrollment</h2>

        <a href="../logout.php" class="logout">
            Logout
        </a>

    </div>


    <div class="container">

        <div class="profile-card">


            <!-- PROFILE HEADER -->

            <div class="profile-header">

                <h1>My Profile</h1>


                <div class="profile-actions">

                    <a
                        href="edit_profile.php"
                        class="edit-btn"
                    >
                        ✏️ Edit Profile
                    </a>


                    <a
                        href="change_password.php"
                        class="password-btn"
                    >
                        🔐 Change Password
                    </a>

                </div>

            </div>


            <!-- SUCCESS MESSAGE -->

            <?php if ($updated): ?>

                <div class="success-message">

                    ✅ Your profile has been successfully updated.

                </div>

            <?php endif; ?>


            <!-- PROFILE INFORMATION -->

            <div class="profile-grid">


                <!-- STUDENT NUMBER -->

                <div class="info-box">

                    <div class="label">
                        Student Number
                    </div>

                    <div class="value">
                        <?= htmlspecialchars($student["student_number"]) ?>
                    </div>

                </div>


                <!-- USERNAME -->

                <div class="info-box">

                    <div class="label">
                        Username
                    </div>

                    <div class="value">
                        <?= htmlspecialchars($student["username"]) ?>
                    </div>

                </div>


                <!-- FULL NAME -->

                <div class="info-box full">

                    <div class="label">
                        Full Name
                    </div>

                    <div class="value">
                        <?= htmlspecialchars($full_name) ?>
                    </div>

                </div>


                <!-- BIRTH DATE -->

                <div class="info-box">

                    <div class="label">
                        Birth Date
                    </div>

                    <div class="value">

                        <?= !empty($student["birth_date"])
                            ? htmlspecialchars($student["birth_date"])
                            : "Not provided" ?>

                    </div>

                </div>


                <!-- GENDER -->

                <div class="info-box">

                    <div class="label">
                        Gender
                    </div>

                    <div class="value">

                        <?= !empty($student["gender"])
                            ? htmlspecialchars($student["gender"])
                            : "Not provided" ?>

                    </div>

                </div>


                <!-- ADDRESS -->

                <div class="info-box full">

                    <div class="label">
                        Address
                    </div>

                    <div class="value">

                        <?= !empty($student["address"])
                            ? nl2br(htmlspecialchars($student["address"]))
                            : "Not provided" ?>

                    </div>

                </div>


                <!-- CONTACT NUMBER -->

                <div class="info-box">

                    <div class="label">
                        Contact Number
                    </div>

                    <div class="value">

                        <?= !empty($student["contact_number"])
                            ? htmlspecialchars($student["contact_number"])
                            : "Not provided" ?>

                    </div>

                </div>


                <!-- EMAIL -->

                <div class="info-box">

                    <div class="label">
                        Email
                    </div>

                    <div class="value">

                        <?= !empty($student["email"])
                            ? htmlspecialchars($student["email"])
                            : "Not provided" ?>

                    </div>

                </div>


            </div>


            <!-- BACK TO DASHBOARD -->

            <a
                href="dashboard.php"
                class="back"
            >
                ← Back to Dashboard
            </a>


        </div>

    </div>

</body>

</html>