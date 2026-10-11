<?php

session_start();

require_once "../config/database.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "student") {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION["user_id"];

$message = "";
$error = "";

/*
|--------------------------------------------------------------------------
| Get Student ID
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id FROM students WHERE user_id = ?"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Student record not found.");
}

$student = $result->fetch_assoc();

$student_id = $student["id"];


/*
|--------------------------------------------------------------------------
| Get Latest Enrollment
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        e.id,
        e.status,
        e.payment_status,
        e.payment_proof,
        e.enrollment_date,
        c.course_name
     FROM enrollments e
     INNER JOIN courses c ON e.course_id = c.id
     WHERE e.student_id = ?
     ORDER BY e.id DESC
     LIMIT 1"
);

$stmt->bind_param("i", $student_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("You do not have an enrollment record yet.");
}

$enrollment = $result->fetch_assoc();

$enrollment_id = $enrollment["id"];


/*
|--------------------------------------------------------------------------
| Upload Proof of Payment
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if ($enrollment["payment_status"] === "paid") {

        $error = "Your payment has already been verified.";

    } elseif (!isset($_FILES["payment_proof"])) {

        $error = "Please select a payment proof image.";

    } else {

        $file = $_FILES["payment_proof"];

        if ($file["error"] !== UPLOAD_ERR_OK) {

            $error = "There was a problem uploading the file.";

        } else {

            $max_size = 5 * 1024 * 1024; // 5 MB

            if ($file["size"] > $max_size) {

                $error = "File is too large. Maximum size is 5 MB.";

            } else {

                $allowed_types = [
                    "image/jpeg",
                    "image/png"
                ];

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $file_type = finfo_file(
                    $finfo,
                    $file["tmp_name"]
                );
                finfo_close($finfo);

                if (!in_array($file_type, $allowed_types, true)) {

                    $error = "Only JPG, JPEG, and PNG images are allowed.";

                } else {

                    $extension = ($file_type === "image/png")
                        ? "png"
                        : "jpg";

                    $random_name =
                        bin2hex(random_bytes(16)) .
                        "." .
                        $extension;

                    $upload_directory =
                        "../uploads/";

                    $upload_path =
                        $upload_directory .
                        $random_name;

                    if (move_uploaded_file(
                        $file["tmp_name"],
                        $upload_path
                    )) {

                        /*
                        |--------------------------------------------------------------------------
                        | Delete previous proof if one exists
                        |--------------------------------------------------------------------------
                        */

                        if (!empty($enrollment["payment_proof"])) {

                            $old_file =
                                "../uploads/" .
                                basename(
                                    $enrollment["payment_proof"]
                                );

                            if (file_exists($old_file)) {
                                unlink($old_file);
                            }
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Save new proof in database
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $conn->prepare(
                            "UPDATE enrollments
                             SET payment_proof = ?
                             WHERE id = ?
                               AND student_id = ?"
                        );

                        $stmt->bind_param(
                            "sii",
                            $random_name,
                            $enrollment_id,
                            $student_id
                        );

                        if ($stmt->execute()) {

                            $message =
                                "Payment proof uploaded successfully.";

                            $enrollment["payment_proof"] =
                                $random_name;

                        } else {

                            if (file_exists($upload_path)) {
                                unlink($upload_path);
                            }

                            $error =
                                "Failed to save payment proof.";
                        }

                    } else {

                        $error =
                            "Failed to upload the image.";
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

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Payment Proof | Secure Enrollment</title>

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

        .container {
            max-width: 700px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin-top: 0;
            color: #1d4ed8;
        }

        .info {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .info p {
            margin: 7px 0;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #f8fafc;
        }

        .hint {
            color: #64748b;
            font-size: 14px;
            margin-top: 8px;
        }

        button {
            margin-top: 20px;
            padding: 11px 18px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            cursor: pointer;
            font-size: 15px;
        }

        button:hover {
            background: #1d4ed8;
        }

        .back {
            display: inline-block;
            margin-top: 20px;
            color: #2563eb;
            text-decoration: none;
        }

        .proof {
            margin-top: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .proof img {
            max-width: 100%;
            max-height: 400px;
            margin-top: 10px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
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

        <div class="card">

            <h1>💳 Payment Proof</h1>

            <div class="info">

                <p>
                    <strong>Course:</strong>
                    <?= htmlspecialchars(
                        $enrollment["course_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </p>

                <p>
                    <strong>Enrollment Status:</strong>
                    <?= htmlspecialchars(
                        ucfirst($enrollment["status"]),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </p>

                <p>
                    <strong>Payment Status:</strong>
                    <?= htmlspecialchars(
                        ucfirst($enrollment["payment_status"]),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </p>

            </div>


            <?php if ($message): ?>

                <div class="success">
                    <?= htmlspecialchars(
                        $message,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>

            <?php endif; ?>


            <?php if ($error): ?>

                <div class="error">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>

            <?php endif; ?>


            <?php if ($enrollment["payment_status"] !== "paid"): ?>

                <form
                    method="POST"
                    enctype="multipart/form-data"
                >

                    <label for="payment_proof">
                        Upload Proof of Payment
                    </label>

                    <input
                        type="file"
                        name="payment_proof"
                        id="payment_proof"
                        accept=".jpg,.jpeg,.png"
                        required
                    >

                    <div class="hint">
                        JPG, JPEG, or PNG only. Maximum file size: 5 MB.
                    </div>

                    <button type="submit">
                        Upload Payment Proof
                    </button>

                </form>

            <?php else: ?>

                <div class="success">
                    Your payment has been verified by the administrator.
                </div>

            <?php endif; ?>


            <?php if (!empty($enrollment["payment_proof"])): ?>

                <div class="proof">

                    <strong>
                        Current Payment Proof:
                    </strong>

                    <br>

                    <img
                        src="../uploads/<?= htmlspecialchars(
                            basename($enrollment["payment_proof"]),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        alt="Payment Proof"
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

    </div>

</body>

</html>
