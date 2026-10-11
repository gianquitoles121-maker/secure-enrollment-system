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


/*
|--------------------------------------------------------------------------
| GET STUDENT
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id
     FROM students
     WHERE user_id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows === 0) {
    die("Student record not found.");
}


$student = $result->fetch_assoc();

$student_id = (int) $student["id"];

$stmt->close();


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


if ($activeSchoolYearId === null) {

    die(
        "There is currently no active school year."
    );
}


/*
|--------------------------------------------------------------------------
| GET ENROLLMENT ID
|--------------------------------------------------------------------------
*/

$enrollment_id = 0;


if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["enrollment_id"])
) {

    $enrollment_id =
        (int) $_POST["enrollment_id"];

} elseif (
    isset($_GET["enrollment_id"])
) {

    $enrollment_id =
        (int) $_GET["enrollment_id"];

}


/*
|--------------------------------------------------------------------------
| IF NO ENROLLMENT ID
|--------------------------------------------------------------------------
*/

if ($enrollment_id <= 0) {

    header("Location: status.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET SPECIFIC ENROLLMENT
|--------------------------------------------------------------------------
|
| IMPORTANT:
| The enrollment must belong to the currently logged-in student.
|
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        e.id,
        e.school_year_id,
        e.year_level,
        e.semester,
        e.status,
        e.payment_status,
        e.payment_date,
        e.payment_proof,
        e.enrollment_date,

        c.course_code,
        c.course_name,

        sy.school_year

     FROM enrollments e

     INNER JOIN courses c
        ON e.course_id = c.id

     INNER JOIN school_years sy
        ON e.school_year_id = sy.id

     WHERE e.id = ?
     AND e.student_id = ?
     AND e.school_year_id = ?

     LIMIT 1"
);


$stmt->bind_param(
    "iii",
    $enrollment_id,
    $student_id,
    $activeSchoolYearId
);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows === 0) {

    die(
        "Enrollment record not found or it does not belong to the active school year."
    );
}


$enrollment = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| MESSAGE VARIABLES
|--------------------------------------------------------------------------
*/

$message = "";
$error = "";


/*
|--------------------------------------------------------------------------
| PROCESS PAYMENT PROOF UPLOAD
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF PROTECTION
    |--------------------------------------------------------------------------
    */

    verify_csrf_token();


    /*
    |--------------------------------------------------------------------------
    | PAYMENT / ENROLLMENT VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        $enrollment["payment_status"] === "paid"
    ) {

        $error =
            "Your payment has already been verified.";

    } elseif (
        $enrollment["status"] !== "approved"
    ) {

        $error =
            "Payment proof can only be submitted after your enrollment has been approved by the administrator.";

    } elseif (
        !isset($_FILES["payment_proof"])
    ) {

        $error =
            "Please select a payment proof.";

    } else {

        $file = $_FILES["payment_proof"];


        /*
        |--------------------------------------------------------------------------
        | UPLOAD ERROR CHECK
        |--------------------------------------------------------------------------
        */

        if (
            !isset($file["error"]) ||
            $file["error"] !== UPLOAD_ERR_OK
        ) {

            $error =
                "Please select a valid file.";

        /*
        |--------------------------------------------------------------------------
        | FILE SIZE CHECK
        |--------------------------------------------------------------------------
        */

        } elseif (
            !isset($file["size"]) ||
            $file["size"] <= 0
        ) {

            $error =
                "The selected file is empty.";

        } elseif (
            $file["size"] > 5 * 1024 * 1024
        ) {

            $error =
                "File is too large. Maximum size is 5 MB.";

        /*
        |--------------------------------------------------------------------------
        | TEMPORARY FILE CHECK
        |--------------------------------------------------------------------------
        */

        } elseif (
            !isset($file["tmp_name"]) ||
            !is_uploaded_file($file["tmp_name"])
        ) {

            $error =
                "Invalid file upload.";

        } else {

            /*
            |--------------------------------------------------------------------------
            | MIME TYPE CHECK
            |--------------------------------------------------------------------------
            */

            $finfo =
                finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo === false) {

                $error =
                    "Unable to validate the uploaded file.";

            } else {

                $file_type =
                    finfo_file(
                        $finfo,
                        $file["tmp_name"]
                    );

                finfo_close($finfo);


                /*
                |--------------------------------------------------------------------------
                | ALLOWED MIME TYPES
                |--------------------------------------------------------------------------
                */

                $allowed_types = [
                    "image/jpeg",
                    "image/png"
                ];


                if (
                    !in_array(
                        $file_type,
                        $allowed_types,
                        true
                    )
                ) {

                    $error =
                        "Only JPG, JPEG, and PNG images are allowed.";

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | ACTUAL IMAGE VALIDATION
                    |--------------------------------------------------------------------------
                    |
                    | This makes sure the uploaded file is actually
                    | a readable image and not simply a renamed file.
                    |
                    */

                    $image_info =
                        @getimagesize(
                            $file["tmp_name"]
                        );


                    if (
                        $image_info === false
                    ) {

                        $error =
                            "The uploaded file is not a valid image.";

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | VERIFY IMAGE MIME TYPE
                        |--------------------------------------------------------------------------
                        */

                        $image_mime =
                            $image_info["mime"] ?? "";


                        if (
                            !in_array(
                                $image_mime,
                                $allowed_types,
                                true
                            )
                        ) {

                            $error =
                                "Invalid image type.";

                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | DETERMINE SAFE EXTENSION
                            |--------------------------------------------------------------------------
                            */

                            $extension =
                                (
                                    $image_mime ===
                                    "image/png"
                                )
                                ? "png"
                                : "jpg";


                            /*
                            |--------------------------------------------------------------------------
                            | GENERATE RANDOM SERVER-SIDE FILENAME
                            |--------------------------------------------------------------------------
                            |
                            | Never trust the original filename.
                            |
                            */

                            try {

                                $random_name =
                                    bin2hex(
                                        random_bytes(16)
                                    )
                                    . "."
                                    . $extension;

                            } catch (Throwable $e) {

                                $error =
                                    "Unable to generate a secure filename.";

                                $random_name = "";
                            }


                            if (
                                $error === "" &&
                                $random_name !== ""
                            ) {

                                /*
                                |--------------------------------------------------------------------------
                                | UPLOAD DIRECTORY
                                |--------------------------------------------------------------------------
                                */

                                $upload_directory =
                                    __DIR__ . "/../uploads/";


                                /*
                                |--------------------------------------------------------------------------
                                | ENSURE UPLOAD DIRECTORY EXISTS
                                |--------------------------------------------------------------------------
                                */

                                if (
                                    !is_dir(
                                        $upload_directory
                                    )
                                ) {

                                    $error =
                                        "Uploads folder does not exist.";

                                } elseif (
                                    !is_writable(
                                        $upload_directory
                                    )
                                ) {

                                    $error =
                                        "Uploads folder is not writable.";

                                } else {

                                    /*
                                    |--------------------------------------------------------------------------
                                    | FINAL UPLOAD PATH
                                    |--------------------------------------------------------------------------
                                    */

                                    $upload_path =
                                        $upload_directory .
                                        $random_name;


                                    /*
                                    |--------------------------------------------------------------------------
                                    | MOVE UPLOADED FILE
                                    |--------------------------------------------------------------------------
                                    */

                                    if (
                                        !move_uploaded_file(
                                            $file["tmp_name"],
                                            $upload_path
                                        )
                                    ) {

                                        $error =
                                            "Failed to upload the payment proof.";

                                    } else {

                                        /*
                                        |--------------------------------------------------------------------------
                                        | SAVE DATABASE RECORD
                                        |--------------------------------------------------------------------------
                                        */

                                        $stmt = $conn->prepare(
                                            "UPDATE enrollments
                                             SET
                                                payment_proof = ?,
                                                payment_status = 'unpaid',
                                                payment_date = NULL
                                             WHERE id = ?
                                             AND student_id = ?
                                             AND school_year_id = ?"
                                        );


                                        $stmt->bind_param(
                                            "siii",
                                            $random_name,
                                            $enrollment_id,
                                            $student_id,
                                            $activeSchoolYearId
                                        );


                                        if (
                                            $stmt->execute() &&
                                            $stmt->affected_rows >= 0
                                        ) {

                                            /*
                                            |--------------------------------------------------------------------------
                                            | UPDATE LOCAL DATA
                                            |--------------------------------------------------------------------------
                                            */

                                            $enrollment["payment_proof"] =
                                                $random_name;

                                            $enrollment["payment_status"] =
                                                "unpaid";

                                            $enrollment["payment_date"] =
                                                null;


                                            /*
                                            |--------------------------------------------------------------------------
                                            | NOTIFICATION
                                            |--------------------------------------------------------------------------
                                            */

                                            $notificationMessage =
                                                "Payment proof uploaded successfully for "
                                                .
                                                (
                                                    $enrollment["semester"] == 1
                                                    ? "1st Semester"
                                                    : "2nd Semester"
                                                )
                                                .
                                                ". It is now pending verification.";


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

                                            $notification->close();


                                            $message =
                                                "Payment proof uploaded successfully.";

                                        } else {

                                            /*
                                            |--------------------------------------------------------------------------
                                            | DELETE UPLOADED FILE IF DATABASE UPDATE FAILS
                                            |--------------------------------------------------------------------------
                                            */

                                            if (
                                                file_exists(
                                                    $upload_path
                                                )
                                            ) {

                                                unlink(
                                                    $upload_path
                                                );
                                            }


                                            $error =
                                                "Failed to save payment proof.";
                                        }


                                        $stmt->close();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

```
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Payment Proof | Secure Enrollment
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
    }


    .navbar h2 {
        margin: 0;
    }


    .logout {
        color: white;
        text-decoration: none;
        background: #dc2626;
        padding: 9px 15px;
        border-radius: 7px;
    }


    .logout:hover {
        background: #b91c1c;
    }


    .container {
        max-width: 700px;
        margin: 40px auto;
        padding: 0 20px;
    }


    .card {
        background: white;
        padding: 30px;
        border-radius: 14px;
        box-shadow:
            0 5px 20px
            rgba(0, 0, 0, 0.08);
    }


    h1 {
        margin-top: 0;
        color: #1d4ed8;
    }


    .info {
        background: #f8fafc;
        padding: 18px;
        border-radius: 10px;
        margin-bottom: 20px;
    }


    .info p {
        margin: 7px 0;
    }


    .school-year {
        display: inline-block;
        margin-bottom: 12px;
        padding: 7px 12px;
        background: #dbeafe;
        color: #1d4ed8;
        border-radius: 20px;
        font-size: 13px;
        font-weight: bold;
    }


    .semester {
        display: inline-block;
        margin-bottom: 15px;
        padding: 7px 12px;
        background: #eff6ff;
        color: #1d4ed8;
        border-radius: 7px;
        font-size: 13px;
        font-weight: bold;
    }


    .status {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 13px;
        font-weight: bold;
    }


    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }


    .status-approved {
        background: #dcfce7;
        color: #166534;
    }


    .status-rejected {
        background: #fee2e2;
        color: #991b1b;
    }


    .status-cancelled {
        background: #e2e8f0;
        color: #475569;
    }


    .payment-paid {
        background: #dcfce7;
        color: #166534;
    }


    .payment-unpaid {
        background: #fee2e2;
        color: #991b1b;
    }


    .success {
        background: #dcfce7;
        color: #166534;
        padding: 13px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: bold;
    }


    .error {
        background: #fee2e2;
        color: #991b1b;
        padding: 13px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: bold;
    }


    .upload-box {
        margin-top: 20px;
        padding: 25px;
        border: 2px dashed #94a3b8;
        border-radius: 12px;
        text-align: center;
        background: #f8fafc;
    }


    .upload-box p {
        color: #64748b;
        margin-bottom: 15px;
    }


    #payment_proof {
        display: none;
    }


    .choose-button {
        display: inline-block;
        padding: 12px 20px;
        background: #2563eb;
        color: white;
        border-radius: 8px;
        cursor: pointer;
        font-weight: bold;
    }


    .choose-button:hover {
        background: #1d4ed8;
    }


    .file-name {
        margin-top: 15px;
        color: #475569;
        font-size: 14px;
    }


    .preview {
        margin-top: 20px;
        display: none;
    }


    .preview img {
        max-width: 100%;
        max-height: 350px;
        border-radius: 10px;
        border: 1px solid #cbd5e1;
    }


    .upload-submit {
        margin-top: 20px;
        padding: 12px 22px;
        background: #16a34a;
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 15px;
        font-weight: bold;
        cursor: pointer;
    }


    .upload-submit:hover {
        background: #15803d;
    }


    .verified {
        background: #dcfce7;
        color: #166534;
        padding: 15px;
        border-radius: 8px;
        font-weight: bold;
    }


    .current-proof {
        margin-top: 25px;
        padding: 20px;
        background: #f8fafc;
        border-radius: 10px;
    }


    .current-proof img {
        display: block;
        max-width: 100%;
        max-height: 400px;
        margin-top: 15px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
    }


    .back {
        display: inline-block;
        margin-top: 25px;
        color: #2563eb;
        text-decoration: none;
        font-weight: bold;
    }


    .back:hover {
        text-decoration: underline;
    }


    @media (max-width: 600px) {

        .navbar {
            padding: 15px 18px;
        }

        .navbar h2 {
            font-size: 18px;
        }

    }

</style>
```

</head>

<body>

<div class="navbar">

```
<h2>
    Secure Enrollment
</h2>


<a
    href="../logout.php"
    class="logout"
>
    Logout
</a>
```

</div>

<div class="container">

```
<div class="card">


    <h1>
        💳 Payment Proof
    </h1>


    <div class="info">


        <span class="school-year">

            School Year:

            <?= htmlspecialchars(
                $enrollment["school_year"],
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </span>


        <br>


        <span class="semester">

            <?= $enrollment["semester"] == 1
                ? "1st Semester"
                : "2nd Semester" ?>

        </span>


        <p>

            <strong>
                Course:
            </strong>

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

        </p>


        <p>

            <strong>
                Year Level:
            </strong>

            <?= htmlspecialchars(
                $enrollment["year_level"],
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </p>


        <p>

            <strong>
                Enrollment Status:
            </strong>


            <span
                class="status status-<?= htmlspecialchars(
                    strtolower(
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

        </p>


        <p>

            <strong>
                Payment Status:
            </strong>


            <span
                class="status payment-<?= htmlspecialchars(
                    strtolower(
                        $enrollment["payment_status"]
                    ),
                    ENT_QUOTES,
                    "UTF-8"
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

        </p>


    </div>


    <?php if (!empty($message)): ?>

        <div class="success">

            <?= htmlspecialchars(
                $message,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <?php if (!empty($error)): ?>

        <div class="error">

            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <?php if (
        $enrollment["status"] === "approved" &&
        $enrollment["payment_status"] !== "paid"
    ): ?>


        <form
            method="POST"
            action=""
            enctype="multipart/form-data"
        >

            <?= csrf_field() ?>


            <input
                type="hidden"
                name="enrollment_id"
                value="<?= (int) $enrollment_id ?>"
            >


            <div class="upload-box">

                <h3>
                    📷 Upload Proof of Payment
                </h3>


                <p>
                    Select a picture of your payment receipt.
                </p>


                <input
                    type="file"
                    name="payment_proof"
                    id="payment_proof"
                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                    required
                    onchange="showFile(this)"
                >


                <label
                    for="payment_proof"
                    class="choose-button"
                >
                    📁 Choose Payment Proof
                </label>


                <div
                    id="fileName"
                    class="file-name"
                >
                    No file selected.
                </div>


                <div
                    id="preview"
                    class="preview"
                >

                    <p>
                        <strong>Preview:</strong>
                    </p>


                    <img
                        id="previewImage"
                        src=""
                        alt="Payment Proof Preview"
                    >

                </div>


                <button
                    type="submit"
                    class="upload-submit"
                >
                    ⬆️ Upload Payment Proof
                </button>

            </div>

        </form>


    <?php elseif (
        $enrollment["payment_status"] === "paid"
    ): ?>


        <div class="verified">

            ✓ Your payment has already been
            verified by the administrator.

        </div>


    <?php else: ?>


        <div class="error">

            Your enrollment is still waiting for administrator approval.
            You can upload your payment proof only after your enrollment
            has been approved.

        </div>


    <?php endif; ?>


    <?php if (
        !empty(
            $enrollment["payment_proof"]
        )
    ): ?>


        <div class="current-proof">

            <strong>
                Current Payment Proof:
            </strong>


            <img
                src="../uploads/<?= htmlspecialchars(
                    basename(
                        $enrollment["payment_proof"]
                    ),
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
                alt="Current Payment Proof"
            >

        </div>


    <?php endif; ?>


    <a
        href="status.php"
        class="back"
    >
        ← Back to Enrollment Status
    </a>


</div>
```

</div>

<script>

function showFile(input) {

    const fileName =
        document.getElementById("fileName");

    const preview =
        document.getElementById("preview");

    const previewImage =
        document.getElementById("previewImage");


    if (
        input.files &&
        input.files.length > 0
    ) {

        const file =
            input.files[0];


        fileName.textContent =
            "Selected file: " + file.name;


        if (
            file.type === "image/jpeg" ||
            file.type === "image/png"
        ) {

            const reader =
                new FileReader();


            reader.onload =
                function(e) {

                    previewImage.src =
                        e.target.result;

                    preview.style.display =
                        "block";

                };


            reader.readAsDataURL(file);

        }

    } else {

        fileName.textContent =
            "No file selected.";

        preview.style.display =
            "none";

    }

}

</script>

</body>

</html>
