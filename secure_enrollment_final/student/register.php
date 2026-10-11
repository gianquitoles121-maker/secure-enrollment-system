<?php

session_start();

require_once "../config/database.php";
require_once "../config/csrf.php";

$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF PROTECTION
    |--------------------------------------------------------------------------
    */

    verify_csrf_token();


    /*
    |--------------------------------------------------------------------------
    | GET FORM INPUT
    |--------------------------------------------------------------------------
    */

    $first_name =
        trim($_POST["first_name"] ?? "");

    $last_name =
        trim($_POST["last_name"] ?? "");

    $email =
        trim($_POST["email"] ?? "");

    $password =
        $_POST["password"] ?? "";

    $confirm_password =
        $_POST["confirm_password"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | SERVER-SIDE INPUT VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        $first_name === "" ||
        $last_name === "" ||
        $email === "" ||
        $password === "" ||
        $confirm_password === ""
    ) {

        $message =
            "Please complete all required fields.";

        $messageType = "error";

    } elseif (
        strlen($first_name) > 100 ||
        strlen($last_name) > 100
    ) {

        $message =
            "First name and last name must not exceed 100 characters.";

        $messageType = "error";

    } elseif (
        !preg_match(
            "/^[a-zA-ZÀ-ÿ .'-]+$/",
            $first_name
        )
    ) {

        $message =
            "First name contains invalid characters.";

        $messageType = "error";

    } elseif (
        !preg_match(
            "/^[a-zA-ZÀ-ÿ .'-]+$/",
            $last_name
        )
    ) {

        $message =
            "Last name contains invalid characters.";

        $messageType = "error";

    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $message =
            "Please enter a valid email address.";

        $messageType = "error";

    } elseif (strlen($email) > 100) {

        $message =
            "Email address must not exceed 100 characters.";

        $messageType = "error";

    } elseif (strlen($password) < 8) {

        $message =
            "Password must be at least 8 characters.";

        $messageType = "error";

    } elseif ($password !== $confirm_password) {

        $message =
            "Passwords do not match.";

        $messageType = "error";

    } else {

        /*
        |--------------------------------------------------------------------------
        | GET ACTIVE SCHOOL YEAR
        |--------------------------------------------------------------------------
        */

        $school_year_result = $conn->query(
            "SELECT id, school_year
             FROM school_years
             WHERE status = 'active'
             ORDER BY id DESC
             LIMIT 1"
        );

        if (
            !$school_year_result ||
            $school_year_result->num_rows !== 1
        ) {

            $message =
                "Registration is currently unavailable because there is no active school year.";

            $messageType = "error";

        } else {

            $school_year_data =
                $school_year_result->fetch_assoc();

            $school_year =
                $school_year_data["school_year"];


            /*
            |--------------------------------------------------------------------------
            | START TRANSACTION
            |--------------------------------------------------------------------------
            */

            $conn->begin_transaction();

            try {

                /*
                |--------------------------------------------------------------------------
                | GENERATE STUDENT NUMBER
                |--------------------------------------------------------------------------
                */

                $prefix =
                    $school_year . "-";

                $number_stmt = $conn->prepare(
                    "SELECT student_number
                     FROM students
                     WHERE student_number LIKE ?
                     ORDER BY student_number DESC
                     LIMIT 1
                     FOR UPDATE"
                );

                $like_prefix =
                    $prefix . "%";

                $number_stmt->bind_param(
                    "s",
                    $like_prefix
                );

                $number_stmt->execute();

                $number_result =
                    $number_stmt->get_result();

                $next_number = 1;

                if ($number_result->num_rows === 1) {

                    $latest =
                        $number_result->fetch_assoc();

                    $latest_number =
                        $latest["student_number"];

                    $latest_sequence =
                        (int) substr(
                            $latest_number,
                            strlen($prefix)
                        );

                    $next_number =
                        $latest_sequence + 1;
                }

                $number_stmt->close();


                /*
                |--------------------------------------------------------------------------
                | CREATE STUDENT NUMBER
                |--------------------------------------------------------------------------
                */

                $student_number =
                    $prefix .
                    str_pad(
                        $next_number,
                        4,
                        "0",
                        STR_PAD_LEFT
                    );


                /*
                |--------------------------------------------------------------------------
                | USE STUDENT NUMBER AS USERNAME
                |--------------------------------------------------------------------------
                */

                $username =
                    $student_number;


                /*
                |--------------------------------------------------------------------------
                | HASH PASSWORD
                |--------------------------------------------------------------------------
                */

                $hashed_password =
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );


                if ($hashed_password === false) {

                    throw new Exception(
                        "Password hashing failed."
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | CREATE USER ACCOUNT
                |--------------------------------------------------------------------------
                */

                $user = $conn->prepare(
                    "INSERT INTO users
                    (
                        username,
                        password,
                        role
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        'student'
                    )"
                );

                $user->bind_param(
                    "ss",
                    $username,
                    $hashed_password
                );

                $user->execute();

                $user_id =
                    $conn->insert_id;

                $user->close();


                /*
                |--------------------------------------------------------------------------
                | CREATE STUDENT RECORD
                |--------------------------------------------------------------------------
                */

                $student = $conn->prepare(
                    "INSERT INTO students
                    (
                        user_id,
                        student_number,
                        first_name,
                        last_name,
                        email
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )"
                );

                $student->bind_param(
                    "issss",
                    $user_id,
                    $student_number,
                    $first_name,
                    $last_name,
                    $email
                );

                $student->execute();

                $student->close();


                /*
                |--------------------------------------------------------------------------
                | COMMIT
                |--------------------------------------------------------------------------
                */

                $conn->commit();


                /*
                |--------------------------------------------------------------------------
                | SUCCESS MESSAGE
                |--------------------------------------------------------------------------
                */

                $message =
                    "Registration successful!<br><br>" .
                    "<strong>School Year:</strong> " .
                    htmlspecialchars(
                        $school_year,
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    "<br>" .
                    "<strong>Student Number:</strong> " .
                    htmlspecialchars(
                        $student_number,
                        ENT_QUOTES,
                        "UTF-8"
                    ) .
                    "<br><br>" .
                    "Use your Student Number to login.";

                $messageType =
                    "success";

            } catch (Exception $e) {

                $conn->rollback();

                $message =
                    "Registration failed. Please try again.";

                $messageType =
                    "error";
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

    <title>
        Student Registration | Secure Enrollment
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;

            font-family: Arial, sans-serif;

            background:
                linear-gradient(
                    135deg,
                    #0f172a,
                    #1d4ed8
                );

            min-height: 100vh;

            display: flex;

            justify-content: center;

            align-items: flex-start;

            padding: 25px;

            overflow-y: auto;
        }

        .container {
            background: white;

            width: 100%;

            max-width: 500px;

            padding: 35px;

            border-radius: 16px;

            box-shadow:
                0 15px 40px
                rgba(0, 0, 0, 0.25);
        }

        h1 {
            color: #1d4ed8;

            text-align: center;

            margin-bottom: 8px;
        }

        .subtitle {
            text-align: center;

            color: #64748b;

            margin-bottom: 25px;
        }

        label {
            display: block;

            margin: 14px 0 6px;

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
            padding: 14px;

            margin-bottom: 15px;

            border-radius: 8px;

            line-height: 1.6;
        }

        .message.success {
            background: #dcfce7;

            color: #166534;
        }

        .message.error {
            background: #fee2e2;

            color: #991b1b;
        }

        .student-number-info {
            background: #eff6ff;

            border: 1px solid #bfdbfe;

            color: #1e40af;

            padding: 14px;

            border-radius: 8px;

            margin-bottom: 18px;

            font-size: 14px;

            line-height: 1.6;
        }

        .student-number-info strong {
            font-size: 15px;
        }

        .login-link {
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

    <div class="container">

        <h1>
            Student Registration
        </h1>

        <p class="subtitle">
            Create your enrollment account
        </p>

        <?php if ($message !== ""): ?>

            <div class="message <?= htmlspecialchars(
                $messageType,
                ENT_QUOTES,
                "UTF-8"
            ) ?>">

                <?= $message ?>

            </div>

        <?php endif; ?>

        <div class="student-number-info">

            🎓 <strong>Student Number</strong><br>

            Your Student Number will be
            automatically generated by the system.

            <br><br>

            The Student Number includes your
            school year and a unique number.

            <br><br>

            Example:
            <strong>2026-2027-0001</strong>

        </div>

        <form
            method="POST"
            action=""
        >

            <?= csrf_field() ?>

            <label for="first_name">
                First Name
            </label>

            <input
                type="text"
                id="first_name"
                name="first_name"
                required
                maxlength="100"
                autocomplete="given-name"
            >

            <label for="last_name">
                Last Name
            </label>

            <input
                type="text"
                id="last_name"
                name="last_name"
                required
                maxlength="100"
                autocomplete="family-name"
            >

            <label for="email">
                Email Address
            </label>

            <input
                type="email"
                id="email"
                name="email"
                required
                maxlength="100"
                autocomplete="email"
            >

            <label for="password">
                Password
            </label>

            <input
                type="password"
                id="password"
                name="password"
                required
                minlength="8"
                autocomplete="new-password"
            >

            <label for="confirm_password">
                Confirm Password
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
                Create Account
            </button>

        </form>

        <p class="login-link">

            Already have an account?

            <a href="../login.php">
                Login here
            </a>

        </p>

    </div>

</body>

</html>
