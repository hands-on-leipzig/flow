<?php

require_once 'generator_db.php';

require_once 'generator_functions_explore.php';
require_once 'generator_functions_challenge.php';
require_once 'generator_functions_finale.php';


// ***********************************************************************************
// Useful functions
// ***********************************************************************************

// Handling time objects   

// Function to add minutes to the time (modifies original)
function g_add_minutes(DateTime $time, $minutes) {
    $intervalSpec = 'PT' . abs((int)$minutes) . 'M';
    $interval = new DateInterval($intervalSpec);

    if ($minutes < 0) {
        $interval->invert = 1;
    }

    $time->add($interval);
}

// Function to add minutes to the time and return a new DateTime (does not modify original)
function g_shift_minutes(DateTime $time, $minutes) {
    $newTime = clone $time;
    $intervalSpec = 'PT' . abs((int)$minutes) . 'M';
    $interval = new DateInterval($intervalSpec);

    if ($minutes < 0) {
        $interval->invert = 1;
    }

    $newTime->add($interval);
    return $newTime;
}

// Calculate difference between two times
function g_diff_in_minutes(DateTime $time1, DateTime $time2) {
    // Calculate the difference between the two DateTime objects
    $interval = $time1->diff($time2);

    // Convert the difference to total minutes
    $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

    // Check if time1 is earlier than time2
    if ($time1 < $time2) {
        // Return negative minutes if time1 is earlier than time2
        return -$minutes;
    }

    // Return the total minutes (positive)
    return $minutes;
}

// Debug output
function g_debug_timing($text, $c_block = 0, $r_start_shift = 0) {
        
    global $DEBUG;
    global $j_time;
    global $r_time;

    if($DEBUG >= 3) {
        echo "<b>$text</b> round:$c_block jt:{$j_time->format('H:i')} rt:{$r_time->format('H:i')} rss:$r_start_shift <br>";
    }
}

function g_debug_log($parameter) {

    global $DEBUG;

    error_log("DEBUG" . $DEBUG . " ". $parameter. "=" . gp($parameter));

}