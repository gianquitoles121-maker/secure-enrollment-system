<?php

/*
 * Session security
 *
 * Secure = false because the current XAMPP setup
 * normally uses HTTP locally.
 *
 * When deployed with HTTPS, change secure to true.
 */

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

require_once "config/database.php";
require_once "config/csrf.php";

$message = "";


/*
 * Handle login
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
     * CSRF protection
     */
    verify_csrf_token();


    /*
     * Get and validate input
     */
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";


    if ($username === "" || $password === "") {

        $message = "Invalid username or password.";

    } else {

        /*
         * Start transaction.
         *
         * This helps prevent race conditions when
         * multiple login attempts happen at the same time.
         */
        $conn->begin_transaction();

        try {

            /*
             * Lock the user row while processing
             * the login attempt.
             */
            $stmt = $conn->prepare(
                "SELECT
                    id,
                    username,
                    password,
                    role,
                    must_change_password,
                    failed_login_attempts,
                    locked_until
                 FROM users
                 WHERE username = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->bind_param(
                "s",
                $username
            );

            $stmt->execute();

            $result = $stmt->get_result();


            /*
             * Username does not exist.
             *
             * Do not reveal whether the account exists.
             */
            if ($result->num_rows !== 1) {

                $conn->rollback();

                $message =
                    "Invalid username or password.";

            } else {

                $user = $result->fetch_assoc();


                /*
                 * Check if account is currently locked.
                 */
                if (!empty($user["locked_until"])) {

                    $lockCheck = $conn->prepare(
                        "SELECT
                            TIMESTAMPDIFF(
                                SECOND,
                                NOW(),
                                locked_until
                            ) AS seconds_remaining
                         FROM users
                         WHERE id = ?
                         LIMIT 1"
                    );

                    $lockCheck->bind_param(
                        "i",
                        $user["id"]
                    );

                    $lockCheck->execute();

                    $lockResult =
                        $lockCheck->get_result();

                    $lockData =
                        $lockResult->fetch_assoc();


                    /*
                     * Lock is still active.
                     */
                    if (
                        $lockData &&
                        (int)$lockData["seconds_remaining"] > 0
                    ) {

                        $conn->rollback();

                        $message =
                            "Too many failed login attempts. Please try again in 5 minutes.";

                    } else {

                        /*
                         * Lock expired.
                         * Reset failed attempts.
                         */
                        $reset = $conn->prepare(
                            "UPDATE users
                             SET failed_login_attempts = 0,
                                 locked_until = NULL
                             WHERE id = ?"
                        );

                        $reset->bind_param(
                            "i",
                            $user["id"]
                        );

                        $reset->execute();


                        /*
                         * Update local values.
                         */
                        $user["failed_login_attempts"] = 0;
                        $user["locked_until"] = null;


                        /*
                         * Check password.
                         */
                        if (
                            password_verify(
                                $password,
                                $user["password"]
                            )
                        ) {

                            /*
                             * Successful login.
                             */
                            $reset = $conn->prepare(
                                "UPDATE users
                                 SET failed_login_attempts = 0,
                                     locked_until = NULL
                                 WHERE id = ?"
                            );

                            $reset->bind_param(
                                "i",
                                $user["id"]
                            );

                            $reset->execute();


                            /*
                             * Commit database changes.
                             */
                            $conn->commit();


                            /*
                             * Regenerate session ID
                             * to prevent session fixation.
                             */
                            session_regenerate_id(true);


                            /*
                             * Store session information.
                             */
                            $_SESSION["user_id"] =
                                (int)$user["id"];

                            $_SESSION["username"] =
                                $user["username"];

                            $_SESSION["role"] =
                                $user["role"];


                            /*
                             * Redirect based on role.
                             */
                            if (
                                $user["role"] === "admin"
                            ) {

                                header(
                                    "Location: admin/dashboard.php"
                                );

                                exit;

                            }


                            if (
                                $user["role"] === "student"
                            ) {

                                /*
                                 * Student must change password
                                 * if admin reset the password.
                                 */
                                if (
                                    (int)$user["must_change_password"] === 1
                                ) {

                                    header(
                                        "Location: student/change_password.php?required=1"
                                    );

                                    exit;
                                }


                                header(
                                    "Location: student/dashboard.php"
                                );

                                exit;
                            }


                            /*
                             * Unexpected role.
                             */
                            session_unset();
                            session_destroy();

                            $message =
                                "Invalid username or password.";

                        } else {

                            /*
                             * Wrong password.
                             *
                             * Increment failed attempts
                             * atomically.
                             */
                            $failed_attempts =
                                (int)$user["failed_login_attempts"] + 1;


                            /*
                             * Lock after 15 failed attempts.
                             */
                            if ($failed_attempts >= 15) {

                                $lock = $conn->prepare(
                                    "UPDATE users
                                     SET failed_login_attempts =
                                             failed_login_attempts + 1,
                                         locked_until =
                                             DATE_ADD(
                                                 NOW(),
                                                 INTERVAL 5 MINUTE
                                             )
                                     WHERE id = ?"
                                );

                                $lock->bind_param(
                                    "i",
                                    $user["id"]
                                );

                                $lock->execute();


                                $conn->commit();


                                $message =
                                    "Too many failed login attempts. Your account has been locked for 5 minutes.";

                            } else {

                                /*
                                 * Increment failed attempts.
                                 */
                                $update = $conn->prepare(
                                    "UPDATE users
                                     SET failed_login_attempts =
                                             failed_login_attempts + 1
                                     WHERE id = ?"
                                );

                                $update->bind_param(
                                    "i",
                                    $user["id"]
                                );

                                $update->execute();


                                $conn->commit();


                                $message =
                                    "Invalid username or password.";
                            }
                        }
                    }

                } else {

                    /*
                     * Account is not locked.
                     * Check password.
                     */
                    if (
                        password_verify(
                            $password,
                            $user["password"]
                        )
                    ) {

                        /*
                         * Successful login.
                         */
                        $reset = $conn->prepare(
                            "UPDATE users
                             SET failed_login_attempts = 0,
                                 locked_until = NULL
                             WHERE id = ?"
                        );

                        $reset->bind_param(
                            "i",
                            $user["id"]
                        );

                        $reset->execute();


                        /*
                         * Commit database changes.
                         */
                        $conn->commit();


                        /*
                         * Regenerate session ID.
                         */
                        session_regenerate_id(true);


                        /*
                         * Store session information.
                         */
                        $_SESSION["user_id"] =
                            (int)$user["id"];

                        $_SESSION["username"] =
                            $user["username"];

                        $_SESSION["role"] =
                            $user["role"];


                        /*
                         * Redirect based on role.
                         */
                        if (
                            $user["role"] === "admin"
                        ) {

                            header(
                                "Location: admin/dashboard.php"
                            );

                            exit;
                        }


                        if (
                            $user["role"] === "student"
                        ) {

                            /*
                             * Student must change password
                             * if admin reset the password.
                             */
                            if (
                                (int)$user["must_change_password"] === 1
                            ) {

                                header(
                                    "Location: student/change_password.php?required=1"
                                );

                                exit;
                            }


                            header(
                                "Location: student/dashboard.php"
                            );

                            exit;
                        }


                        /*
                         * Unexpected role.
                         */
                        session_unset();
                        session_destroy();

                        $message =
                            "Invalid username or password.";

                    } else {

                        /*
                         * Wrong password.
                         *
                         * Lock after 15 failed attempts.
                         */
                        $failed_attempts =
                            (int)$user["failed_login_attempts"] + 1;


                        if ($failed_attempts >= 15) {

                            /*
                             * Set 5-minute lock using
                             * MySQL server time.
                             */
                            $lock = $conn->prepare(
                                "UPDATE users
                                 SET failed_login_attempts =
                                         failed_login_attempts + 1,
                                     locked_until =
                                         DATE_ADD(
                                             NOW(),
                                             INTERVAL 5 MINUTE
                                         )
                                 WHERE id = ?"
                            );

                            $lock->bind_param(
                                "i",
                                $user["id"]
                            );

                            $lock->execute();


                            $conn->commit();


                            $message =
                                "Too many failed login attempts. Your account has been locked for 5 minutes.";

                        } else {

                            /*
                             * Increment failed attempts.
                             */
                            $update = $conn->prepare(
                                "UPDATE users
                                 SET failed_login_attempts =
                                         failed_login_attempts + 1
                                 WHERE id = ?"
                            );

                            $update->bind_param(
                                "i",
                                $user["id"]
                            );

                            $update->execute();


                            $conn->commit();


                            $message =
                                "Invalid username or password.";
                        }
                    }
                }
            }

        } catch (Throwable $e) {

            /*
             * Rollback any database changes
             * if an unexpected error occurs.
             */
            if ($conn->in_transaction) {
                $conn->rollback();
            }


            /*
             * Do not expose database errors
             * to the user.
             */
            $message =
                "Something went wrong. Please try again later.";
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

    <title>Login | Secure Enrollment</title>


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


        .login-container {
            background: white;

            width: 100%;

            max-width: 420px;

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


        .message {
            padding: 12px;

            margin-bottom: 15px;

            border-radius: 8px;

            background: #fee2e2;

            color: #991b1b;

            text-align: center;
        }


        .forgot-password {
            text-align: right;

            margin-top: 10px;
        }


        .forgot-password a {
            color: #2563eb;

            text-decoration: none;

            font-size: 14px;

            font-weight: bold;
        }


        .forgot-password a:hover {
            text-decoration: underline;
        }


        .register-link {
            text-align: center;

            margin-top: 20px;

            color: #64748b;
        }


        a {
            color: #2563eb;

            text-decoration: none;

            font-weight: bold;
        }

    </style>

</head>


<body>


    <div class="login-container">


        <h1>
            Secure Enrollment
        </h1>


        <p class="subtitle">
            Login to your account
        </p>


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

            <?= csrf_field() ?>


            <label for="username">
                Username
            </label>


            <input
                type="text"
                id="username"
                name="username"
                required
                maxlength="50"
                autocomplete="username"
            >


            <label for="password">
                Password
            </label>


            <input
                type="password"
                id="password"
                name="password"
                required
                maxlength="255"
                autocomplete="current-password"
            >


            <div class="forgot-password">

                <a href="forgot_password.php">
                    🔑 Forgot Password?
                </a>

            </div>


            <button type="submit">
                Login
            </button>


        </form>


        <p class="register-link">

            Don't have an account?

            <a href="student/register.php">
                Register here
            </a>

        </p>


    </div>


</body>

</html> 