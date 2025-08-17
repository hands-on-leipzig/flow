<?php

require_once 'generator_db.php';
require_once 'generator_day_plan_functions.php';

// Main program
if (isset($_GET['plan'])) {
    $plan_id = intval($_GET['plan']);
}

ini_set('display_errors', 'Off');

if (isset($plan_id) && $plan_id > 0) {

    db_connect_persistent();

    echo "<!DOCTYPE html>";
    echo "<html>";
    echo "<head>";
    echo "<title>Day plan - $plan_id</title>";

    ?>

    <style>
        @font-face {
            font-family: 'Uniform';
            src: url("<?php print dirname($_SERVER["PHP_SELF"]); ?>res/Uniform-Regular.otf") format('opentype');
        }

        * {
            font-family: Uniform;
        }

        td {
            padding: .5em;
        }
    </style>
    <?php
    echo "</head>";
    echo "<body>";

    g_show_day_plan($plan_id);

    echo "</body> 
    </html>";

} else {
    echo "<p>Invalid plan ID.</p>";
}
?>
