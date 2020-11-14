<?php

    // configure your database connection here:
    define('DB_SERVER',"127.0.0.1");
    define('DB_NAME',"iSpindel");
    define('DB_USER',"root");
    define('DB_PASSWORD',"wolfram6+");
    define('DB_PORT',"3306");

    $conn = mysqli_connect(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
    if (!$conn) {
            die("Connection failed: " . mysqli_connect_error());
    }

    define("defaultTimePeriod", 24);    // Timeframe for chart (backwards from now)
    define("defaultReset",  false);     // Flag for Timeframe Start (beginning of chart display)
    define("defaultDaysAgo", 365);        // Default number of days past to look for active iSpindels
?>

