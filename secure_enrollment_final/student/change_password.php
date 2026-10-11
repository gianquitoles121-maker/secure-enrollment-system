<?php

session_start();

require_once "../config/database.php";


// ==============================
// CHECK LOGIN
// ==============================

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "student") {

    header("Location: ../login.php");
    exit;
}


$user_id = $_SESSION["user_id"];

$message = "";
$success = "";


// ==============================
// CHECK IF PASSWORD CHANGE IS REQUIRED
// ==============================

$required_change = isset($_GET["required"]) && $_GET["required"] == "1";


// ==============================
// PROCESS PASSWORD CHANGE
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $current_password = $_POST["current_password"];
    $new_password = $_POST["new_password"];
    $confirm_password = $_POST["confirm_password"];


    // ==============================
    // GET CURRENT PASSWORD
    // ==============================

    $stmt = $conn->prepare(
        "SELECT password, must_change_password
         FROM users
         WHERE id = ?"
    );

    $stmt->bind_param("i", $user_id);

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {

        $message = "Account not found.";

    } else {

        $user = $result->fetch_assoc();


        // ==============================
        // VERIFY CURRENT PASSWORD
        // ==============================

        if (!password_verify(
            $current_password,
            $user["password"]
        )) {

            $message = "Current password is incorrect.";

        }

        // ==============================
        // CHECK NEW PASSWORD LENGTH
        // ==============================

        elseif (strlen($new_password) < 8) {

            $message = "New password must be at least 8 characters.";

        }

        // ==============================
        // CHECK PASSWORD CONFIRMATION
        // ==============================

        elseif ($new_password !== $confirm_password) {

            $message = "New passwords do not match.";

        }

        // ==============================
        // PREVENT SAME PASSWORD
        // ==============================

        elseif (password_verify(
            $new_password,
            $user["password"]
        )) {

            $message = "New password must be different from your current password.";

        }

        else {

            // ==============================
            // HASH NEW PASSWORD
            // ==============================

            $hashed_password = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );


            // ==============================
            // UPDATE PASSWORD
            // ALSO REMOVE REQUIRED FLAG
            // ==============================

            $update = $conn->prepare(
                "UPDATE users
                 SET password = ?,
                     must_change_password = 0
                 WHERE id = ?"
            );

            $update->bind_param(
                "si",
                $hashed_password,
                $user_id
            );


            if ($update->execute()) {

                // ==============================
                // ACTIVITY LOG
                // ==============================

                $action = "Student changed account password";

                $ip_address = $_SERVER["REMOTE_ADDR"] ?? "Unknown";

                $log = $conn->prepare(
                    "INSERT INTO activity_logs
                     (user_id, action, ip_address)
                     VALUES (?, ?, ?)"
                );

                $log->bind_param(
                    "iss",
                    $user_id,
                    $action,
                    $ip_address
                );

                $log->execute();


                // ==============================
                // CHECK IF THIS WAS REQUIRED
                // ==============================

                if ($required_change) {

                    header(
                        "Location: dashboard.php?password_changed=1"
                    );

                    exit;

                } else {

                    header(
                        "Location: profile.php?password_changed=1"
                    );

                    exit;
                }

            } else {

                $message = "Something went wrong. Please try again.";

            }
        }
    }
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

    <title>Change Password | Secure Enrollment</title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {
            margin: 0;

            font-family: Arial, sans-serif;

            background: linear-gradient(
                135deg,
                #0f172a,
                #1d4ed8
            );

            min-height: 100vh;

            display: flex;

            justify-content: center;

            align-items: center;

            padding: 25px;
        }


        .container {
            background: white;

            width: 100%;

            max-width: 450px;

            padding: 35px;

            border-radius: 16px;

            box-shadow:
                0 15px 40px
                rgba(0, 0, 0, 0.25);
        }


        h1 {
            text-align: center;

            color: #1d4ed8;

            margin-bottom: 8px;
        }


        .subtitle {
            text-align: center;

            color: #64748b;

            margin-bottom: 25px;
        }


        .required-message {
            background: #fef3c7;

            color: #92400e;

            padding: 13px;

            border-radius: 8px;

            margin-bottom: 20px;

            text-align: center;

            font-size: 14px;

            line-height: 1.5;
        }


        .message {
            background: #fee2e2;

            color: #991b1b;

            padding: 12px;

            border-radius: 8px;

            margin-bottom: 15px;

            text-align: center;
        }


        label {
            display: block;

            margin: 15px 0 6px;

            font-weight: bold;

            color: #334155;
        }


        input {
            width: 100%;

            padding: 12px;

            border: 1px solid #cbd5e1;

            border-radius: 8px;

            font-size: 15px;
        }


        input:focus {
            outline: 2px solid #93c5fd;

            border-color: #2563eb;
        }


        button {
            width: 100%;

            padding: 13px;

            margin-top: 22px;

            background: #2563eb;

            color: white;

            border: none;

            border-radius: 8px;

            font-size: 16px;

            font-weight: bold;

            cursor: pointer;
        }


        button:hover {
            background: #1d4ed8;
        }


        .back {
            display: block;

            text-align: center;

            margin-top: 20px;

            color: #2563eb;

            text-decoration: none;

            font-weight: bold;
        }


        .back:hover {
            text-decoration: underline;
        }

    </style>

</head>


<body>


<div class="container">


    <h1>
        🔐 Change Password
    </h1>


    <p class="subtitle">
        Create a new password for your account
    </p>


    <?php if ($required_change): ?>

        <div class="required-message">

            <strong>Password Change Required</strong>
            <br>

            You are using a temporary password.
            Please create your own password before continuing.

        </div>

    <?php endif; ?>


    <?php if ($message !== ""): ?>

        <div class="message">

            <?= htmlspecialchars(
                $message,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <form method="POST" action="">


        <label for="current_password">
            Current Password
        </label>

        <input
            type="password"
            id="current_password"
            name="current_password"
            required
            autocomplete="current-password"
        >


        <label for="new_password">
            New Password
        </label>

        <input
            type="password"
            id="new_password"
            name="new_password"
            required
            minlength="8"
            autocomplete="new-password"
        >


        <label for="confirm_password">
            Confirm New Password
        </label>

        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            required
            minlength="8"
            autocomplete="new-password"
        >


        <button type="submit">
            Change Password
        </button>


    </form>


    <?php if (!$required_change): ?>

        <a
            href="profile.php"
            class="back"
        >
            ← Back to Profile
        </a>

    <?php endif; ?>


</div>


</body>

</html>
