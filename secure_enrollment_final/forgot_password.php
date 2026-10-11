<?php

session_start();

require_once "config/database.php";

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");

    if ($username === "" || $email === "") {

        $error = "Please enter your username and email.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Find student account
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare(
            "SELECT
                users.id,
                students.email
             FROM users
             INNER JOIN students
                ON users.id = students.user_id
             WHERE users.username = ?
             AND users.role = 'student'
             LIMIT 1"
        );

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $result = $stmt->get_result();


        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();


            /*
            |--------------------------------------------------------------------------
            | Verify email
            |--------------------------------------------------------------------------
            */

            if (
                !empty($user["email"]) &&
                strtolower(trim($user["email"])) === strtolower($email)
            ) {

                $user_id = $user["id"];


                /*
                |--------------------------------------------------------------------------
                | Check existing pending request
                |--------------------------------------------------------------------------
                */

                $check = $conn->prepare(
                    "SELECT id
                     FROM password_reset_requests
                     WHERE user_id = ?
                     AND status = 'pending'
                     LIMIT 1"
                );

                $check->bind_param("i", $user_id);
                $check->execute();

                $existing = $check->get_result();


                if ($existing->num_rows === 0) {

                    /*
                    |--------------------------------------------------------------------------
                    | Create reset request
                    |--------------------------------------------------------------------------
                    */

                    $insert = $conn->prepare(
                        "INSERT INTO password_reset_requests
                        (user_id, status)
                        VALUES (?, 'pending')"
                    );

                    $insert->bind_param("i", $user_id);
                    $insert->execute();


                    /*
                    |--------------------------------------------------------------------------
                    | Notification for student
                    |--------------------------------------------------------------------------
                    */

                    $notification_message =
                        "Your password reset request has been submitted and is waiting for admin approval.";

                    $notification = $conn->prepare(
                        "INSERT INTO notifications
                        (user_id, message)
                        VALUES (?, ?)"
                    );

                    $notification->bind_param(
                        "is",
                        $user_id,
                        $notification_message
                    );

                    $notification->execute();

                }


                /*
                |--------------------------------------------------------------------------
                | Generic success message
                |--------------------------------------------------------------------------
                */

                $message =
                    "If the account information is correct, your password reset request has been submitted for admin review.";

            } else {

                /*
                |--------------------------------------------------------------------------
                | Generic message
                |--------------------------------------------------------------------------
                */

                $message =
                    "If the account information is correct, your password reset request has been submitted for admin review.";

            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | Generic message
            |--------------------------------------------------------------------------
            */

            $message =
                "If the account information is correct, your password reset request has been submitted for admin review.";

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

    <title>Forgot Password - Secure Enrollment</title>

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

        .container {
            max-width: 500px;

            margin: 70px auto;

            padding: 20px;
        }

        .card {
            background: white;

            padding: 30px;

            border-radius: 12px;

            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin-top: 0;

            text-align: center;
        }

        .description {
            color: #777;

            text-align: center;

            line-height: 1.5;

            margin-bottom: 25px;
        }

        .success {
            background: #d1e7dd;

            color: #0f5132;

            padding: 13px 15px;

            border-radius: 8px;

            margin-bottom: 20px;

            line-height: 1.5;
        }

        .error {
            background: #f8d7da;

            color: #842029;

            padding: 13px 15px;

            border-radius: 8px;

            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;

            font-weight: bold;

            margin-bottom: 7px;
        }

        input {
            width: 100%;

            padding: 12px;

            border: 1px solid #ccc;

            border-radius: 7px;

            font-size: 15px;
        }

        input:focus {
            outline: none;

            border-color: #0d6efd;

            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.1);
        }

        .submit-btn {
            width: 100%;

            border: none;

            background: #0d6efd;

            color: white;

            padding: 13px;

            border-radius: 7px;

            font-size: 15px;

            font-weight: bold;

            cursor: pointer;
        }

        .submit-btn:hover {
            background: #0b5ed7;
        }

        .back-login {
            display: block;

            text-align: center;

            margin-top: 20px;

            color: #0d6efd;

            text-decoration: none;

            font-weight: bold;
        }

        .back-login:hover {
            text-decoration: underline;
        }

    </style>

</head>

<body>

    <div class="container">

        <div class="card">

            <h1>🔑 Forgot Password?</h1>

            <p class="description">
                Enter your registered username and email address.
                Your password reset request will be reviewed by an administrator.
            </p>


            <?php if ($message !== ""): ?>

                <div class="success">
                    <?= htmlspecialchars($message) ?>
                </div>

            <?php endif; ?>


            <?php if ($error !== ""): ?>

                <div class="error">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>


            <form method="POST">

                <div class="form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="email">
                        Registered Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="submit-btn"
                >
                    Submit Reset Request
                </button>

            </form>


            <a
                href="login.php"
                class="back-login"
            >
                ← Back to Login
            </a>

        </div>

    </div>

</body>

</html>