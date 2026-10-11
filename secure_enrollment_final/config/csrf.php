<?php

/*
|--------------------------------------------------------------------------
| CSRF Protection Helper
|--------------------------------------------------------------------------
|
| This file creates and validates a CSRF token stored in the user's
| session. The token must be included in every important POST form.
|
*/


/*
|--------------------------------------------------------------------------
| Generate / Get CSRF Token
|--------------------------------------------------------------------------
*/

function csrf_token()
{
    if (empty($_SESSION["csrf_token"])) {

        $_SESSION["csrf_token"] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}


/*
|--------------------------------------------------------------------------
| CSRF Hidden Input
|--------------------------------------------------------------------------
*/

function csrf_field()
{
    return
        '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(
            csrf_token(),
            ENT_QUOTES,
            "UTF-8"
        ) .
        '">';
}


/*
|--------------------------------------------------------------------------
| Validate CSRF Token
|--------------------------------------------------------------------------
*/

function verify_csrf_token()
{
    if (
        !isset($_POST["csrf_token"]) ||
        !isset($_SESSION["csrf_token"])
    ) {

        http_response_code(403);

        die("Invalid CSRF token.");
    }


    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {

        http_response_code(403);

        die("Invalid CSRF token.");
    }
}
