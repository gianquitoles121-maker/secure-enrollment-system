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

$username = $_SESSION["username"] ?? "Student";


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

$csrfToken = csrf_token();


/*
|--------------------------------------------------------------------------
| MARK NOTIFICATIONS AS READ
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["mark_notifications_read"])
) {

    /*
     * Verify CSRF token before changing database data.
     */
    verify_csrf_token();


    $stmt = $conn->prepare(
        "UPDATE notifications
         SET is_read = 1
         WHERE user_id = ?
         AND is_read = 0"
    );


    $stmt->bind_param(
        "i",
        $user_id
    );


    $stmt->execute();

    $stmt->close();


    /*
     * Return JSON response.
     */

    header("Content-Type: application/json");

    echo json_encode([
        "success" => true
    ]);

    exit;
}


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

    $activeSchoolYear =
        $result->fetch_assoc();

    $activeSchoolYearId =
        (int) $activeSchoolYear["id"];
}


$stmt->close();


/*
|--------------------------------------------------------------------------
| GET NOTIFICATIONS
|--------------------------------------------------------------------------
*/

$notifications = [];


$stmt = $conn->prepare(
    "SELECT
        id,
        message,
        is_read,
        created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 10"
);


$stmt->bind_param(
    "i",
    $user_id
);


$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    $notifications[] = $row;
}


$stmt->close();


/*
|--------------------------------------------------------------------------
| COUNT UNREAD NOTIFICATIONS
|--------------------------------------------------------------------------
*/

$unreadCount = 0;


$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total
     FROM notifications
     WHERE user_id = ?
     AND is_read = 0"
);


$stmt->bind_param(
    "i",
    $user_id
);


$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();


$unreadCount =
    (int) ($row["total"] ?? 0);


$stmt->close();


/*
|--------------------------------------------------------------------------
| CHECK APPROVED ENROLLMENT
| IN ACTIVE SCHOOL YEAR
|--------------------------------------------------------------------------
*/

$hasApprovedEnrollment = false;


if ($activeSchoolYearId !== null) {

    $stmt = $conn->prepare(
        "SELECT
            e.id
         FROM enrollments e
         INNER JOIN students s
             ON e.student_id = s.id
         WHERE s.user_id = ?
         AND e.school_year_id = ?
         AND e.status = 'approved'
         LIMIT 1"
    );


    $stmt->bind_param(
        "ii",
        $user_id,
        $activeSchoolYearId
    );


    $stmt->execute();

    $result = $stmt->get_result();


    if ($result->num_rows === 1) {

        $hasApprovedEnrollment = true;
    }


    $stmt->close();
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
        Student Dashboard
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


        /*
        |--------------------------------------------------------------------------
        | NAVBAR
        |--------------------------------------------------------------------------
        */

        .navbar {
            background: #1d4ed8;

            color: white;

            padding: 18px 30px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            box-shadow:
                0 4px 12px
                rgba(0, 0, 0, 0.12);
        }


        .navbar h2 {
            margin: 0;

            font-size: 22px;
        }


        .navbar-right {
            display: flex;

            align-items: center;

            gap: 20px;
        }


        .welcome {
            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATIONS
        |--------------------------------------------------------------------------
        */

        .notification-wrapper {
            position: relative;
        }


        .notification-button {
            border: none;

            background: transparent;

            color: white;

            font-size: 23px;

            cursor: pointer;

            position: relative;
        }


        .notification-badge {
            position: absolute;

            top: -6px;

            right: -7px;

            background: #dc2626;

            color: white;

            width: 18px;

            height: 18px;

            border-radius: 50%;

            font-size: 11px;

            font-weight: bold;

            display: flex;

            justify-content: center;

            align-items: center;
        }


        .notification-dropdown {
            display: none;

            position: absolute;

            right: 0;

            top: 40px;

            width: 320px;

            background: white;

            color: #222;

            border-radius: 10px;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.18);

            overflow: hidden;

            z-index: 1000;
        }


        .notification-dropdown.show {
            display: block;
        }


        .notification-header {
            padding: 15px;

            font-weight: bold;

            border-bottom:
                1px solid #ddd;
        }


        .notification-item {
            padding: 13px 15px;

            border-bottom:
                1px solid #eee;

            font-size: 14px;
        }


        .notification-item.unread {
            background: #eff6ff;

            font-weight: bold;
        }


        .notification-date {
            margin-top: 5px;

            font-size: 11px;

            color: #888;

            font-weight: normal;
        }


        .no-notification {
            padding: 20px;

            text-align: center;

            color: #777;
        }


        /*
        |--------------------------------------------------------------------------
        | MAIN CONTAINER
        |--------------------------------------------------------------------------
        */

        .container {
            width: 92%;

            max-width: 1100px;

            margin: 35px auto;
        }


        /*
        |--------------------------------------------------------------------------
        | WELCOME CARD
        |--------------------------------------------------------------------------
        */

        .welcome-card {
            background: white;

            padding: 30px;

            border-radius: 14px;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.06);

            margin-bottom: 25px;
        }


        .welcome-card h1 {
            margin-top: 0;

            color: #1e3a8a;
        }


        .welcome-card p {
            color: #64748b;
        }


        /*
        |--------------------------------------------------------------------------
        | SCHOOL YEAR BADGE
        |--------------------------------------------------------------------------
        */

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

            color: #b91c1c;

            font-size: 13px;

            font-weight: bold;
        }


        /*
        |--------------------------------------------------------------------------
        | DASHBOARD CARDS
        |--------------------------------------------------------------------------
        */

        .cards {
            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 20px;
        }


        .card {
            background: white;

            padding: 25px;

            border-radius: 14px;

            text-align: center;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.06);
        }


        .icon {
            font-size: 40px;

            margin-bottom: 10px;
        }


        .card h3 {
            margin: 10px 0;
        }


        .card p {
            color: #64748b;

            min-height: 40px;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | BUTTONS
        |--------------------------------------------------------------------------
        */

        .button {
            display: inline-block;

            margin-top: 10px;

            padding: 11px 18px;

            border-radius: 8px;

            text-decoration: none;

            font-weight: bold;

            font-size: 14px;
        }


        .blue {
            background: #2563eb;

            color: white;
        }


        .blue:hover {
            background: #1d4ed8;
        }


        .green {
            background: #16a34a;

            color: white;
        }


        .green:hover {
            background: #15803d;
        }


        .red {
            background: #dc2626;

            color: white;
        }


        .red:hover {
            background: #b91c1c;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 800px) {

            .cards {
                grid-template-columns: 1fr;
            }

        }


        @media (max-width: 600px) {

            .navbar {
                padding: 15px 18px;
            }


            .navbar h2 {
                font-size: 18px;
            }


            .welcome {
                display: none;
            }


            .notification-dropdown {
                position: fixed;

                top: 65px;

                left: 5%;

                width: 90%;
            }

        }

    </style>

</head>


<body>


<!--
|--------------------------------------------------------------------------
| NAVBAR
|--------------------------------------------------------------------------
-->

<div class="navbar">


    <h2>
        Online Enrollment System
    </h2>


    <div class="navbar-right">


        <span class="welcome">

            Welcome,

            <?= htmlspecialchars(
                $username,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </span>


        <div class="notification-wrapper">


            <button
                type="button"
                class="notification-button"
                id="notificationButton"
                aria-label="Notifications"
            >

                🔔


                <?php if ($unreadCount > 0): ?>

                    <span
                        class="notification-badge"
                        id="notificationBadge"
                    >

                        <?= $unreadCount ?>

                    </span>

                <?php endif; ?>


            </button>


            <div
                class="notification-dropdown"
                id="notificationDropdown"
            >


                <div class="notification-header">

                    Notifications

                </div>


                <?php if (empty($notifications)): ?>


                    <div class="no-notification">

                        No notifications yet.

                    </div>


                <?php else: ?>


                    <?php foreach (
                        $notifications
                        as $notification
                    ): ?>


                        <div
                            class="notification-item
                            <?= $notification["is_read"] == 0
                                ? "unread"
                                : "" ?>"
                        >

                            <?= htmlspecialchars(
                                $notification["message"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>


                            <div class="notification-date">

                                <?= date(
                                    "M d, Y h:i A",
                                    strtotime(
                                        $notification["created_at"]
                                    )
                                ) ?>

                            </div>

                        </div>


                    <?php endforeach; ?>


                <?php endif; ?>


            </div>


        </div>


    </div>


</div>


<!--
|--------------------------------------------------------------------------
| MAIN CONTENT
|--------------------------------------------------------------------------
-->

<div class="container">


    <div class="welcome-card">


        <h1>
            Student Dashboard
        </h1>


        <p>

            Welcome back,

            <strong>

                <?= htmlspecialchars(
                    $username,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </strong>!

            Manage your enrollment and student
            information here.

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

                No active school year

            </span>

        <?php endif; ?>


    </div>


    <div class="cards">


        <!-- ENROLLMENT -->

        <div class="card">


            <div class="icon">
                📝
            </div>


            <h3>
                Enrollment
            </h3>


            <p>
                Submit a new enrollment application.
            </p>


            <a
                href="./enroll.php"
                class="button blue"
            >

                Enroll Now

            </a>


        </div>


        <!-- ENROLLMENT STATUS -->

        <div class="card">


            <div class="icon">
                📋
            </div>


            <h3>
                Enrollment Status
            </h3>


            <p>
                View all your enrollment records
                and their current status.
            </p>


            <a
                href="./status.php"
                class="button blue"
            >

                View Status

            </a>


        </div>


        <!-- PROFILE -->

        <div class="card">


            <div class="icon">
                👤
            </div>


            <h3>
                My Profile
            </h3>


            <p>
                View your registered student information.
            </p>


            <a
                href="./profile.php"
                class="button blue"
            >

                View Profile

            </a>


        </div>


        <!-- CONTACT SUPPORT -->

        <div class="card">


            <div class="icon">
                💬
            </div>


            <h3>
                Contact Support
            </h3>


            <p>
                Send a concern or message to the administrator.
            </p>


            <a
                href="./support.php"
                class="button blue"
            >

                Contact Support

            </a>


        </div>


        <!-- ENROLLED SUBJECTS -->

        <?php if ($hasApprovedEnrollment): ?>

            <div class="card">


                <div class="icon">
                    📚
                </div>


                <h3>
                    My Enrolled Subjects
                </h3>


                <p>
                    View your enrolled subjects,
                    units, class schedule, room,
                    and instructor.
                </p>


                <a
                    href="./enrolled_subjects.php"
                    class="button green"
                >

                    View Subjects

                </a>


            </div>

        <?php endif; ?>


        <!-- LOGOUT -->

        <div class="card">


            <div class="icon">
                🚪
            </div>


            <h3>
                Logout
            </h3>


            <p>
                Securely log out of your account.
            </p>


            <a
                href="../logout.php"
                class="button red"
            >

                Logout

            </a>


        </div>


    </div>


</div>


<!--
|--------------------------------------------------------------------------
| NOTIFICATION SCRIPT
|--------------------------------------------------------------------------
-->

<script>

(function () {

    const AUTO_REFRESH_INTERVAL = 5000;

    const CSRF_TOKEN =
        <?= json_encode($csrfToken) ?>;

    let isUpdating = false;


    /*
    |--------------------------------------------------------------------------
    | NOTIFICATION EVENTS
    |--------------------------------------------------------------------------
    */

    function setupNotificationEvents() {

        const notificationButton =
            document.getElementById(
                "notificationButton"
            );


        const notificationDropdown =
            document.getElementById(
                "notificationDropdown"
            );


        if (
            !notificationButton ||
            !notificationDropdown
        ) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION BUTTON
        |--------------------------------------------------------------------------
        */

        notificationButton.onclick =
            function (event) {

                event.stopPropagation();


                notificationDropdown
                    .classList
                    .toggle("show");


                /*
                * Mark notifications as read.
                *
                * CSRF token is sent with the
                * database-changing POST request.
                */

                const currentBadge =
                    document.getElementById(
                        "notificationBadge"
                    );


                if (currentBadge) {

                    const body =
                        new URLSearchParams();


                    body.append(
                        "mark_notifications_read",
                        "1"
                    );


                    body.append(
                        "csrf_token",
                        CSRF_TOKEN
                    );


                    fetch(
                        "dashboard.php",
                        {
                            method: "POST",

                            headers: {
                                "Content-Type":
                                    "application/x-www-form-urlencoded; charset=UTF-8",

                                "X-Requested-With":
                                    "XMLHttpRequest"
                            },

                            body: body.toString()
                        }
                    )
                    .then(function (response) {

                        if (!response.ok) {
                            throw new Error(
                                "Request failed."
                            );
                        }

                        return response.json();

                    })
                    .then(function (data) {

                        if (data.success) {

                            const badge =
                                document.getElementById(
                                    "notificationBadge"
                                );


                            if (badge) {
                                badge.remove();
                            }


                            const unreadItems =
                                document.querySelectorAll(
                                    ".notification-item.unread"
                                );


                            unreadItems.forEach(
                                function (item) {

                                    item.classList.remove(
                                        "unread"
                                    );

                                }
                            );

                        }

                    })
                    .catch(function (error) {

                        console.error(
                            "Notification error:",
                            error
                        );

                    });

                }

            };


        /*
        |--------------------------------------------------------------------------
        | PREVENT DROPDOWN FROM CLOSING
        |--------------------------------------------------------------------------
        */

        notificationDropdown.onclick =
            function (event) {

                event.stopPropagation();

            };


        /*
        |--------------------------------------------------------------------------
        | CLOSE DROPDOWN WHEN CLICKING OUTSIDE
        |--------------------------------------------------------------------------
        */

        document.onclick =
            function () {

                const dropdown =
                    document.getElementById(
                        "notificationDropdown"
                    );


                if (dropdown) {

                    dropdown
                        .classList
                        .remove("show");

                }

            };

    }


    /*
    |--------------------------------------------------------------------------
    | AUTO UPDATE DASHBOARD
    |--------------------------------------------------------------------------
    */

    async function updateDashboard() {

        if (isUpdating) {
            return;
        }


        isUpdating = true;


        try {

            /*
            * Remember whether notification dropdown
            * is currently open.
            */

            const currentDropdown =
                document.getElementById(
                    "notificationDropdown"
                );


            const dropdownWasOpen =
                currentDropdown &&
                currentDropdown.classList.contains(
                    "show"
                );


            /*
            * GET request only.
            *
            * No database changes are performed.
            */

            const response =
                await fetch(
                    window.location.href,
                    {
                        method: "GET",

                        headers: {
                            "X-Requested-With":
                                "XMLHttpRequest",

                            "Cache-Control":
                                "no-cache"
                        },

                        cache: "no-store"
                    }
                );


            if (!response.ok) {
                return;
            }


            const html =
                await response.text();


            /*
            * Convert returned HTML into
            * a temporary document.
            */

            const parser =
                new DOMParser();


            const newDocument =
                parser.parseFromString(
                    html,
                    "text/html"
                );


            /*
            |--------------------------------------------------------------------------
            | CURRENT ELEMENTS
            |--------------------------------------------------------------------------
            */

            const currentNavbarRight =
                document.querySelector(
                    ".navbar-right"
                );


            const currentCards =
                document.querySelector(
                    ".cards"
                );


            /*
            |--------------------------------------------------------------------------
            | NEW ELEMENTS
            |--------------------------------------------------------------------------
            */

            const newNavbarRight =
                newDocument.querySelector(
                    ".navbar-right"
                );


            const newCards =
                newDocument.querySelector(
                    ".cards"
                );


            /*
            |--------------------------------------------------------------------------
            | UPDATE NAVBAR / NOTIFICATIONS
            |--------------------------------------------------------------------------
            */

            if (
                currentNavbarRight &&
                newNavbarRight &&
                currentNavbarRight.innerHTML !==
                    newNavbarRight.innerHTML
            ) {

                currentNavbarRight.innerHTML =
                    newNavbarRight.innerHTML;

            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE DASHBOARD CARDS
            |--------------------------------------------------------------------------
            */

            if (
                currentCards &&
                newCards &&
                currentCards.innerHTML !==
                    newCards.innerHTML
            ) {

                currentCards.innerHTML =
                    newCards.innerHTML;

            }


            /*
            |--------------------------------------------------------------------------
            | RESTORE EVENTS
            |--------------------------------------------------------------------------
            */

            setupNotificationEvents();


            /*
            |--------------------------------------------------------------------------
            | RESTORE DROPDOWN STATE
            |--------------------------------------------------------------------------
            */

            if (dropdownWasOpen) {

                const updatedDropdown =
                    document.getElementById(
                        "notificationDropdown"
                    );


                if (updatedDropdown) {

                    updatedDropdown
                        .classList
                        .add("show");

                }

            }

        } catch (error) {

            console.warn(
                "Student dashboard auto-update failed:",
                error
            );

        } finally {

            isUpdating = false;

        }

    }


    /*
    |--------------------------------------------------------------------------
    | INITIALIZE
    |--------------------------------------------------------------------------
    */

    setupNotificationEvents();


    /*
    |--------------------------------------------------------------------------
    | AUTO REFRESH EVERY 5 SECONDS
    |--------------------------------------------------------------------------
    */

    setInterval(
        updateDashboard,
        AUTO_REFRESH_INTERVAL
    );


})();

</script>


</body>

</html>
