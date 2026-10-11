<?php

session_start();

require_once "../config/database.php";
require_once "../config/csrf.php";


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

$message = "";
$error = "";


/*
|--------------------------------------------------------------------------
| SAVE CHANGES
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
     * CSRF PROTECTION
     */
    verify_csrf_token();


    /*
     * GET INPUT
     */

    $first_name = trim($_POST["first_name"] ?? "");
    $middle_name = trim($_POST["middle_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");

    $birth_date = trim($_POST["birth_date"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $contact_number = trim($_POST["contact_number"] ?? "");
    $email = trim($_POST["email"] ?? "");


    /*
     * INPUT LENGTH VALIDATION
     */

    if (strlen($first_name) > 100) {

        $error = "First name is too long.";

    } elseif (strlen($middle_name) > 100) {

        $error = "Middle name is too long.";

    } elseif (strlen($last_name) > 100) {

        $error = "Last name is too long.";

    } elseif (strlen($address) > 1000) {

        $error = "Address is too long.";

    } elseif (strlen($contact_number) > 30) {

        $error = "Contact number is too long.";

    } elseif (strlen($email) > 100) {

        $error = "Email address is too long.";

    }


    /*
     * REQUIRED FIELDS
     */

    elseif (
        $first_name === "" ||
        $last_name === ""
    ) {

        $error =
            "First name and last name are required.";

    }


    /*
     * NAME VALIDATION
     *
     * Allows letters, spaces, periods, hyphens
     * and apostrophes.
     */

    elseif (
        !preg_match(
            "/^[\p{L}\s.'-]+$/u",
            $first_name
        )
    ) {

        $error =
            "First name contains invalid characters.";

    } elseif (
        $middle_name !== "" &&
        !preg_match(
            "/^[\p{L}\s.'-]+$/u",
            $middle_name
        )
    ) {

        $error =
            "Middle name contains invalid characters.";

    } elseif (
        !preg_match(
            "/^[\p{L}\s.'-]+$/u",
            $last_name
        )
    ) {

        $error =
            "Last name contains invalid characters.";

    }


    /*
     * EMAIL VALIDATION
     */

    elseif (
        $email !== "" &&
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $error =
            "Please enter a valid email address.";

    }


    /*
     * GENDER VALIDATION
     */

    elseif (
        !in_array(
            $gender,
            ["", "Male", "Female", "Other"],
            true
        )
    ) {

        $error =
            "Invalid gender selected.";

    }


    /*
     * BIRTH DATE VALIDATION
     */

    elseif ($birth_date !== "") {

        $date = DateTime::createFromFormat(
            "Y-m-d",
            $birth_date
        );

        $validDate =
            $date !== false &&
            $date->format("Y-m-d") === $birth_date;

        if (!$validDate) {

            $error =
                "Please enter a valid birth date.";

        } elseif (
            $date > new DateTime("today")
        ) {

            $error =
                "Birth date cannot be in the future.";

        }

    }


    /*
     * UPDATE PROFILE
     */

    if ($error === "") {

        $stmt = $conn->prepare(
            "UPDATE students
             SET
                first_name = ?,
                middle_name = ?,
                last_name = ?,
                birth_date = NULLIF(?, ''),
                gender = ?,
                address = ?,
                contact_number = ?,
                email = ?
             WHERE user_id = ?"
        );


        $stmt->bind_param(
            "ssssssssi",
            $first_name,
            $middle_name,
            $last_name,
            $birth_date,
            $gender,
            $address,
            $contact_number,
            $email,
            $user_id
        );


        if ($stmt->execute()) {

            /*
             * POST/REDIRECT/GET
             */
            header(
                "Location: profile.php?updated=1"
            );

            exit;

        } else {

            $error =
                "Something went wrong while updating your profile.";

        }


        $stmt->close();

    }

}


/*
|--------------------------------------------------------------------------
| GET CURRENT PROFILE
|--------------------------------------------------------------------------
*/

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
     INNER JOIN students
        ON users.id = students.user_id
     WHERE users.id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows !== 1) {

    die("Student profile not found.");

}


$student = $result->fetch_assoc();

$stmt->close();

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
        Edit Profile - Secure Enrollment
    </title>


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


        .card {

            background: white;

            padding: 30px;

            border-radius: 12px;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.08);

        }


        .card h1 {

            margin-top: 0;

            margin-bottom: 25px;

        }


        .error {

            background: #f8d7da;

            color: #842029;

            padding: 12px 15px;

            border-radius: 7px;

            margin-bottom: 20px;

        }


        .form-grid {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 20px;

        }


        .form-group {

            display: flex;

            flex-direction: column;

        }


        .form-group.full {

            grid-column: span 2;

        }


        label {

            font-weight: bold;

            margin-bottom: 7px;

        }


        input,
        select,
        textarea {

            width: 100%;

            padding: 12px;

            border: 1px solid #ccc;

            border-radius: 7px;

            font-size: 15px;

            font-family: Arial, sans-serif;

        }


        textarea {

            min-height: 100px;

            resize: vertical;

        }


        input:focus,
        select:focus,
        textarea:focus {

            outline: none;

            border-color: #0d6efd;

            box-shadow:
                0 0 0 2px
                rgba(13, 110, 253, 0.1);

        }


        input[readonly] {

            background: #e9ecef;

            cursor: not-allowed;

        }


        .buttons {

            margin-top: 30px;

            display: flex;

            gap: 12px;

        }


        .save-btn {

            border: none;

            background: #0d6efd;

            color: white;

            padding: 12px 22px;

            border-radius: 7px;

            font-size: 15px;

            font-weight: bold;

            cursor: pointer;

        }


        .save-btn:hover {

            background: #0b5ed7;

        }


        .cancel-btn {

            text-decoration: none;

            background: #6c757d;

            color: white;

            padding: 12px 22px;

            border-radius: 7px;

            font-weight: bold;

        }


        .cancel-btn:hover {

            background: #5c636a;

        }


        .note {

            font-size: 13px;

            color: #777;

            margin-top: 5px;

        }


        @media (max-width: 700px) {

            .navbar {

                padding: 15px 20px;

            }


            .form-grid {

                grid-template-columns: 1fr;

            }


            .form-group.full {

                grid-column: span 1;

            }


            .buttons {

                flex-direction: column;

            }


            .save-btn,
            .cancel-btn {

                width: 100%;

                text-align: center;

            }

        }

    </style>

</head>


<body>


<div class="navbar">

    <h2>
        Secure Enrollment
    </h2>


    <a
        href="../logout.php"
        class="logout"
    >
        Logout
    </a>

</div>


<div class="container">


    <div class="card">


        <h1>
            ✏️ Edit Profile
        </h1>


        <?php if ($error !== ""): ?>

            <div class="error">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <form method="POST" action="">


            <?= csrf_field() ?>


            <div class="form-grid">


                <!-- Student Number -->

                <div class="form-group">

                    <label>
                        Student Number
                    </label>

                    <input
                        type="text"
                        value="<?= htmlspecialchars(
                            $student["student_number"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        readonly
                    >

                    <div class="note">
                        Student number cannot be changed.
                    </div>

                </div>


                <!-- Username -->

                <div class="form-group">

                    <label>
                        Username
                    </label>

                    <input
                        type="text"
                        value="<?= htmlspecialchars(
                            $student["username"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        readonly
                    >

                    <div class="note">
                        Username cannot be changed.
                    </div>

                </div>


                <!-- First Name -->

                <div class="form-group">

                    <label>
                        First Name *
                    </label>

                    <input
                        type="text"
                        name="first_name"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $student["first_name"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        required
                    >

                </div>


                <!-- Middle Name -->

                <div class="form-group">

                    <label>
                        Middle Name
                    </label>

                    <input
                        type="text"
                        name="middle_name"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $student["middle_name"] ?? "",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                </div>


                <!-- Last Name -->

                <div class="form-group">

                    <label>
                        Last Name *
                    </label>

                    <input
                        type="text"
                        name="last_name"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $student["last_name"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        required
                    >

                </div>


                <!-- Birth Date -->

                <div class="form-group">

                    <label>
                        Birth Date
                    </label>

                    <input
                        type="date"
                        name="birth_date"
                        value="<?= htmlspecialchars(
                            $student["birth_date"] ?? "",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                </div>


                <!-- Gender -->

                <div class="form-group">

                    <label>
                        Gender
                    </label>

                    <select name="gender">

                        <option value="">
                            Select Gender
                        </option>

                        <option
                            value="Male"
                            <?= (
                                $student["gender"] === "Male"
                            )
                                ? "selected"
                                : "" ?>
                        >
                            Male
                        </option>

                        <option
                            value="Female"
                            <?= (
                                $student["gender"] === "Female"
                            )
                                ? "selected"
                                : "" ?>
                        >
                            Female
                        </option>

                        <option
                            value="Other"
                            <?= (
                                $student["gender"] === "Other"
                            )
                                ? "selected"
                                : "" ?>
                        >
                            Other
                        </option>

                    </select>

                </div>


                <!-- Contact Number -->

                <div class="form-group">

                    <label>
                        Contact Number
                    </label>

                    <input
                        type="text"
                        name="contact_number"
                        maxlength="30"
                        value="<?= htmlspecialchars(
                            $student["contact_number"] ?? "",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                </div>


                <!-- Email -->

                <div class="form-group">

                    <label>
                        Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $student["email"] ?? "",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                </div>


                <!-- Address -->

                <div class="form-group full">

                    <label>
                        Address
                    </label>

                    <textarea
                        name="address"
                        maxlength="1000"
                    ><?= htmlspecialchars(
                        $student["address"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?></textarea>

                </div>


            </div>


            <div class="buttons">


                <button
                    type="submit"
                    class="save-btn"
                >
                    💾 Save Changes
                </button>


                <a
                    href="profile.php"
                    class="cancel-btn"
                >
                    Cancel
                </a>


            </div>


        </form>


    </div>


</div>


</body>

</html>
