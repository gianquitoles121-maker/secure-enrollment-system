<?php

session_start();

require_once "../config/database.php";

/*
|--------------------------------------------------------------------------
| SECURITY HEADERS
|--------------------------------------------------------------------------
*/

header(
    "Cache-Control: no-store, no-cache, must-revalidate, max-age=0"
);

header(
    "Cache-Control: post-check=0, pre-check=0",
    false
);

header("Pragma: no-cache");

header(
    "X-Content-Type-Options: nosniff"
);

header(
    "X-Frame-Options: SAMEORIGIN"
);

header(
    "Referrer-Policy: strict-origin-when-cross-origin"
);


/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$school_year_id = filter_input(
    INPUT_GET,
    "school_year_id",
    FILTER_VALIDATE_INT
);

if (
    $school_year_id === false ||
    $school_year_id === null ||
    $school_year_id < 0
) {
    $school_year_id = 0;
}


$semester = filter_input(
    INPUT_GET,
    "semester",
    FILTER_VALIDATE_INT
);

if (
    $semester === false ||
    $semester === null ||
    !in_array(
        $semester,
        [0, 1, 2],
        true
    )
) {
    $semester = 0;
}


$search = trim(
    $_GET["search"] ?? ""
);


/*
|--------------------------------------------------------------------------
| LIMIT SEARCH INPUT
|--------------------------------------------------------------------------
*/

if (strlen($search) > 100) {

    $search = substr(
        $search,
        0,
        100
    );

}


/*
|--------------------------------------------------------------------------
| GET SCHOOL YEARS
|--------------------------------------------------------------------------
*/

$school_years = [];

try {

    $sy_stmt = $conn->prepare(
        "SELECT
            id,
            school_year,
            status
         FROM school_years
         ORDER BY id DESC"
    );

    if (!$sy_stmt) {
        throw new Exception(
            "Unable to prepare school year query."
        );
    }

    if (!$sy_stmt->execute()) {
        throw new Exception(
            "Unable to load school years."
        );
    }

    $sy_result =
        $sy_stmt->get_result();

    while (
        $row =
        $sy_result->fetch_assoc()
    ) {

        $school_years[] = $row;

    }

    $sy_stmt->close();

} catch (Throwable $e) {

    error_log(
        "Enrollment history school year error: " .
        $e->getMessage()
    );

    $school_years = [];

}


/*
|--------------------------------------------------------------------------
| VERIFY SCHOOL YEAR FILTER
|--------------------------------------------------------------------------
|
| If a school year ID was supplied, make sure it actually exists.
|
*/

if ($school_year_id > 0) {

    try {

        $verify_stmt = $conn->prepare(
            "SELECT id
             FROM school_years
             WHERE id = ?
             LIMIT 1"
        );

        if (!$verify_stmt) {
            throw new Exception(
                "Unable to prepare school year validation."
            );
        }

        $verify_stmt->bind_param(
            "i",
            $school_year_id
        );

        if (!$verify_stmt->execute()) {
            throw new Exception(
                "Unable to validate school year."
            );
        }

        $verify_result =
            $verify_stmt->get_result();

        if (
            $verify_result->num_rows !== 1
        ) {

            $school_year_id = 0;

        }

        $verify_stmt->close();

    } catch (Throwable $e) {

        error_log(
            "Enrollment history school year validation error: " .
            $e->getMessage()
        );

        $school_year_id = 0;

    }

}


/*
|--------------------------------------------------------------------------
| GET ENROLLMENT HISTORY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        e.id,
        e.school_year_id,
        sy.school_year,

        e.semester,
        e.year_level,
        e.status,
        e.payment_status,
        e.payment_date,
        e.enrollment_date,

        s.student_number,
        s.first_name,
        s.middle_name,
        s.last_name,

        c.course_code,
        c.course_name

    FROM enrollments e

    INNER JOIN students s
        ON e.student_id = s.id

    INNER JOIN courses c
        ON e.course_id = c.id

    INNER JOIN school_years sy
        ON e.school_year_id = sy.id

    WHERE 1 = 1
";


$params = [];

$types = "";


/*
|--------------------------------------------------------------------------
| SCHOOL YEAR FILTER
|--------------------------------------------------------------------------
*/

if ($school_year_id > 0) {

    $sql .=
        " AND e.school_year_id = ?";

    $params[] =
        $school_year_id;

    $types .= "i";

}


/*
|--------------------------------------------------------------------------
| SEMESTER FILTER
|--------------------------------------------------------------------------
*/

if ($semester > 0) {

    $sql .=
        " AND e.semester = ?";

    $params[] =
        $semester;

    $types .= "i";

}


/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            s.student_number LIKE ?
            OR s.first_name LIKE ?
            OR s.middle_name LIKE ?
            OR s.last_name LIKE ?
            OR c.course_code LIKE ?
            OR c.course_name LIKE ?
        )
    ";


    $search_value =
        "%" . $search . "%";


    $params[] =
        $search_value;

    $params[] =
        $search_value;

    $params[] =
        $search_value;

    $params[] =
        $search_value;

    $params[] =
        $search_value;

    $params[] =
        $search_value;


    $types .= "ssssss";

}


/*
|--------------------------------------------------------------------------
| SORT
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY
        e.school_year_id DESC,
        e.semester ASC,
        e.enrollment_date DESC
";


/*
|--------------------------------------------------------------------------
| EXECUTE ENROLLMENT QUERY
|--------------------------------------------------------------------------
*/

$result = false;

$db_error = false;


try {

    $stmt =
        $conn->prepare($sql);


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare enrollment history query."
        );

    }


    if (!empty($params)) {

        $stmt->bind_param(
            $types,
            ...$params
        );

    }


    if (!$stmt->execute()) {

        throw new Exception(
            "Unable to retrieve enrollment history."
        );

    }


    $result =
        $stmt->get_result();


    if (!$result) {

        throw new Exception(
            "Unable to read enrollment history."
        );

    }

} catch (Throwable $e) {

    error_log(
        "Enrollment history query error: " .
        $e->getMessage()
    );

    $db_error = true;

}


/*
|--------------------------------------------------------------------------
| COUNTER
|--------------------------------------------------------------------------
*/

$total_records =
    $result
    ? $result->num_rows
    : 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Enrollment History | Admin</title>


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


        .header {

            background: #0f172a;

            color: white;

            padding: 18px 30px;

            display: flex;

            justify-content: space-between;

            align-items: center;

        }


        .header h1 {

            margin: 0;

            font-size: 22px;

        }


        .back-btn {

            background: #2563eb;

            color: white;

            text-decoration: none;

            padding: 10px 16px;

            border-radius: 8px;

            font-weight: bold;

        }


        .back-btn:hover {

            background: #1d4ed8;

        }


        .container {

            max-width: 1400px;

            margin: 30px auto;

            padding: 0 20px;

        }


        .title-section {

            margin-bottom: 20px;

        }


        .title-section h2 {

            margin: 0 0 6px;

        }


        .title-section p {

            margin: 0;

            color: #64748b;

        }


        .filters {

            background: white;

            padding: 20px;

            border-radius: 12px;

            box-shadow:
                0 4px 12px
                rgba(0, 0, 0, 0.08);

            margin-bottom: 20px;

        }


        .filter-grid {

            display: grid;

            grid-template-columns:
                1fr
                1fr
                1.5fr
                auto;

            gap: 15px;

            align-items: end;

        }


        .filter-group label {

            display: block;

            margin-bottom: 7px;

            font-weight: bold;

            font-size: 14px;

        }


        .filter-group select,
        .filter-group input {

            width: 100%;

            padding: 11px;

            border: 1px solid #cbd5e1;

            border-radius: 8px;

            font-size: 14px;

        }


        .filter-group select:focus,
        .filter-group input:focus {

            outline: 2px solid #93c5fd;

            border-color: #2563eb;

        }


        .filter-btn {

            padding: 11px 20px;

            border: none;

            border-radius: 8px;

            background: #2563eb;

            color: white;

            font-weight: bold;

            cursor: pointer;

        }


        .filter-btn:hover {

            background: #1d4ed8;

        }


        .reset-btn {

            display: inline-block;

            margin-left: 8px;

            padding: 11px 15px;

            border-radius: 8px;

            background: #e2e8f0;

            color: #334155;

            text-decoration: none;

            font-weight: bold;

        }


        .reset-btn:hover {

            background: #cbd5e1;

        }


        .summary {

            background: white;

            padding: 16px 20px;

            border-radius: 12px;

            margin-bottom: 20px;

            box-shadow:
                0 4px 12px
                rgba(0, 0, 0, 0.06);

            font-weight: bold;

        }


        .error-box {

            background: #fee2e2;

            color: #991b1b;

            padding: 14px 18px;

            border-radius: 10px;

            margin-bottom: 20px;

            font-weight: bold;

        }


        .table-container {

            background: white;

            border-radius: 12px;

            box-shadow:
                0 4px 12px
                rgba(0, 0, 0, 0.08);

            overflow-x: auto;

        }


        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 1100px;

        }


        th {

            background: #0f172a;

            color: white;

            padding: 14px;

            text-align: left;

            font-size: 13px;

        }


        td {

            padding: 13px 14px;

            border-bottom: 1px solid #e2e8f0;

            font-size: 14px;

        }


        tr:hover td {

            background: #f8fafc;

        }


        .student-name {

            font-weight: bold;

        }


        .student-number {

            color: #64748b;

            font-size: 12px;

            margin-top: 3px;

        }


        .badge {

            display: inline-block;

            padding: 5px 9px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: bold;

        }


        .approved {

            background: #dcfce7;

            color: #166534;

        }


        .pending {

            background: #fef3c7;

            color: #92400e;

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


        .empty {

            text-align: center;

            padding: 50px;

            color: #64748b;

        }


        .semester-badge {

            background: #dbeafe;

            color: #1e40af;

            padding: 5px 9px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: bold;

        }


        .school-year {

            font-weight: bold;

            color: #1d4ed8;

        }


        @media (max-width: 900px) {

            .filter-grid {

                grid-template-columns: 1fr;

            }


            .filter-btn,
            .reset-btn {

                width: 100%;

                text-align: center;

                margin-left: 0;

                margin-top: 5px;

            }

        }

    </style>

</head>


<body>


<header class="header">

    <h1>
        Enrollment History
    </h1>


    <a
        href="dashboard.php"
        class="back-btn"
    >
        ← Back to Dashboard
    </a>

</header>


<div class="container">


    <div class="title-section">

        <h2>
            Student Enrollment History
        </h2>

        <p>
            View enrollment records from current and previous school years.
        </p>

    </div>


    <?php if ($db_error): ?>

        <div class="error-box">

            Unable to load enrollment history.
            Please try again later.

        </div>

    <?php endif; ?>


    <!-- FILTERS -->

    <form
        method="GET"
        class="filters"
    >

        <div class="filter-grid">


            <div class="filter-group">

                <label for="school_year_id">
                    School Year
                </label>

                <select
                    name="school_year_id"
                    id="school_year_id"
                >

                    <option value="0">
                        All School Years
                    </option>


                    <?php foreach ($school_years as $sy): ?>

                        <option
                            value="<?= (int) $sy["id"] ?>"
                            <?= $school_year_id == $sy["id"]
                                ? "selected"
                                : "" ?>
                        >

                            <?= htmlspecialchars(
                                $sy["school_year"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                            <?php if (
                                $sy["status"] === "active"
                            ): ?>

                                (Active)

                            <?php endif; ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="filter-group">

                <label for="semester">
                    Semester
                </label>

                <select
                    name="semester"
                    id="semester"
                >

                    <option value="0">
                        All Semesters
                    </option>


                    <option
                        value="1"
                        <?= $semester === 1
                            ? "selected"
                            : "" ?>
                    >
                        1st Semester
                    </option>


                    <option
                        value="2"
                        <?= $semester === 2
                            ? "selected"
                            : "" ?>
                    >
                        2nd Semester
                    </option>

                </select>

            </div>


            <div class="filter-group">

                <label for="search">
                    Search Student
                </label>


                <input
                    type="text"
                    name="search"
                    id="search"
                    maxlength="100"
                    placeholder="Student number, name, or course..."
                    value="<?= htmlspecialchars(
                        $search,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <div>

                <button
                    type="submit"
                    class="filter-btn"
                >
                    Filter
                </button>


                <a
                    href="enrollment_history.php"
                    class="reset-btn"
                >
                    Reset
                </a>

            </div>


        </div>

    </form>


    <!-- SUMMARY -->

    <div class="summary">

        <?= (int) $total_records ?>

        enrollment record(s) found.

    </div>


    <!-- TABLE -->

    <div class="table-container">


        <?php if (
            !$db_error &&
            $result &&
            $result->num_rows > 0
        ): ?>


            <table>

                <thead>

                    <tr>

                        <th>
                            School Year
                        </th>

                        <th>
                            Student
                        </th>

                        <th>
                            Course
                        </th>

                        <th>
                            Year
                        </th>

                        <th>
                            Semester
                        </th>

                        <th>
                            Enrollment Status
                        </th>

                        <th>
                            Payment
                        </th>

                        <th>
                            Enrollment Date
                        </th>

                    </tr>

                </thead>


                <tbody>


                    <?php while (
                        $row =
                        $result->fetch_assoc()
                    ): ?>


                        <tr>


                            <td>

                                <span class="school-year">

                                    <?= htmlspecialchars(
                                        $row["school_year"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <div class="student-name">

                                    <?= htmlspecialchars(
                                        trim(
                                            $row["first_name"] .
                                            " " .
                                            (
                                                !empty(
                                                    $row["middle_name"]
                                                )
                                                ? $row["middle_name"] .
                                                  " "
                                                : ""
                                            ) .
                                            $row["last_name"]
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </div>


                                <div class="student-number">

                                    <?= htmlspecialchars(
                                        $row["student_number"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </div>

                            </td>


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


                            <td>

                                <?= (int) $row["year_level"] ?>

                                Year

                            </td>


                            <td>

                                <span class="semester-badge">

                                    <?= (int) $row["semester"] === 1
                                        ? "1st Semester"
                                        : "2nd Semester"
                                    ?>

                                </span>

                            </td>


                            <td>

                                <?php

                                $status =
                                    $row["status"];

                                $allowed_status_classes = [
                                    "approved",
                                    "pending",
                                    "rejected",
                                    "cancelled"
                                ];

                                if (
                                    !in_array(
                                        $status,
                                        $allowed_status_classes,
                                        true
                                    )
                                ) {
                                    $status = "pending";
                                }

                                ?>


                                <span
                                    class="badge <?= $status ?>"
                                >

                                    <?= htmlspecialchars(
                                        ucfirst(
                                            $row["status"]
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?php
                                $payment_status =
                                    $row["payment_status"];

                                $payment_class =
                                    $payment_status === "paid"
                                    ? "paid"
                                    : "unpaid";
                                ?>


                                <span
                                    class="badge <?= $payment_class ?>"
                                >

                                    <?= htmlspecialchars(
                                        ucfirst(
                                            $payment_status
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?php

                                $timestamp =
                                    strtotime(
                                        $row["enrollment_date"]
                                    );

                                if (
                                    $timestamp !== false
                                ) {

                                    echo htmlspecialchars(
                                        date(
                                            "M d, Y h:i A",
                                            $timestamp
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                } else {

                                    echo "—";

                                }

                                ?>

                            </td>


                        </tr>


                    <?php endwhile; ?>


                </tbody>

            </table>


        <?php elseif (!$db_error): ?>


            <div class="empty">

                <h3>
                    No enrollment records found.
                </h3>

                <p>
                    Try changing the school year,
                    semester, or search filters.
                </p>

            </div>


        <?php endif; ?>


    </div>


</div>


</body>

</html>
