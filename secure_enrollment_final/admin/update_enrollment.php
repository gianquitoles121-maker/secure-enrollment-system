<?php

session_start();


/* ==========================================================
   SECURITY HEADERS
   ========================================================== */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");


/* ==========================================================
   ADMIN ACCESS
   ========================================================== */

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}


/* ==========================================================
   DEAD ENDPOINT
   ========================================================== */

/*
 * This file is no longer used to modify enrollments.
 *
 * All enrollment actions are now handled directly by:
 *
 *     admin/dashboard.php
 *
 * This endpoint intentionally performs NO database update.
 *
 * Keeping it as a dead endpoint prevents old forms, bookmarks,
 * or direct requests from accidentally modifying enrollment
 * records.
 */


/* ==========================================================
   OPTIONAL ADMIN MESSAGE
   ========================================================== */

$_SESSION["admin_message"] =
    "This enrollment action endpoint is no longer active. Please use the Admin Dashboard.";


/* ==========================================================
   REDIRECT
   ========================================================== */

header("Location: dashboard.php");
exit;

?>
