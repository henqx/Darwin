<?php
//Shorthand for performing MySQL queries, also includes system-specif error handling (i.e. exits, loggin and alert emails)
function q($con1, $query, $include_num_rows = 0,$email_error = 0, $exit = 0, $running = 0)
{
    $result = mysqli_query($con1, $query);
    if (!$result) {
        log_error($con1, mysqli_error($con1) . ' - ' . $query);
        if ($email_error == 1) {
            info_email($con1, array(
                'content' => mysqli_error($con1) . ' - ' .$query,
                'subject' => 'Hyperion Is Dead',
                'type' => 'error'
            ));
        }
        if($exit == 1)
        {
            if($running != 0)
            {
                stop_file($con1,$running);
            }
            exit();
        }
    }
    if ($include_num_rows == 1) {
        if (mysqli_num_rows($result) == 0) {
            log_error($con1, $query);
            if ($email_error == 1) {
                info_email_amazon($con1, array(
                    'content' => $query,
                    'subject' => 'Hyperion Is Dead',
                    'type' => 'error'
                ));
            }
            if($exit == 1)
            {
                if($running != 0)
                {
                    stop_file($con1,$running);
                }
                exit();
            }
        }
    }
    return $result;
}