<?php
function import_jobs_from_jobdiva()
{
    global $wpdb;
    try {
        $apiUrla =
            "https://www1.jobdiva.com/employers/connect/listofportaljobs.jsp?a=1fjdnwj5s1czk3c1p3k8e25uyoaj0s096595yhp5d8kysoguwx94p7sxk7uhqlg0&&fulldesc=1";
        $xmlfile = file_get_contents($apiUrla);
        // Convert xml string into an object
        $new = simplexml_load_string(
            $xmlfile,
            "SimpleXMLElement",
            LIBXML_NOCDATA,
        );
        // Convert into json
        $con = json_encode($new);
        // Convert into associative array
        $data = json_decode($con, true);
        $newdata = $data["jobs"]["job"];

        $errors = [];
        $settings = get_option("summit_jobdiva_settings", []);
        // Enabled imports only federal jobs; disabled excludes federal jobs.
        $federal_jobs_only =
            is_array($settings) && !empty($settings["federal_jobs"]);
        // Clear old imports before rebuilding from the selected division.
        $deleteJobs = delete_custom_posts("job");
        if ($deleteJobs) {
            foreach ($newdata as $key => $jsonToArray) {
                if (
                    !empty($jsonToArray) &&
                    isset($jsonToArray["jobdivaid"]) &&
                    $jsonToArray["jobdivaid"] != "" &&
                    isset($jsonToArray["internal_division"]) &&
                    ($federal_jobs_only
                        ? $jsonToArray["internal_division"] == "Federal"
                        : $jsonToArray["internal_division"] != "Federal")
                ) {
                    $fieldErrors = [];

                    // Check each field and collect errors if empty
                    if (empty($jsonToArray["title"])) {
                        $fieldErrors[] = "Job Title is empty";
                    }
                    if (empty($jsonToArray["jobdivaid"])) {
                        $fieldErrors[] = "Job ID is empty";
                    }

                    // Collect all errors
                    if (!empty($fieldErrors)) {
                        $errors[] =
                            "Job ID: " .
                            $jsonToArray["jobdivaid"] .
                            ", Job Title: " .
                            $jsonToArray["title"] .
                            " has the following issues: " .
                            implode(", ", $fieldErrors);
                    }

                    $date = $jsonToArray["issuedate"];

                    $createDate = new DateTime($date);

                    $strip = $createDate->format("Y-m-d");

                    // JobDiva scores flexibility from 0 (on-site) to 100 (remote).
                    $remotelocation = $jsonToArray["onsiteflexibility"];
                    if ($remotelocation == "100") {
                        $remotelocation = "Remote";
                    } elseif ($remotelocation == "0") {
                        $remotelocation = "On-Site";
                    } else {
                        $remotelocation = "Hybrid";
                    }

                    if (
                        $jsonToArray["onsiteflexibility"] >= 0 &&
                        $jsonToArray["onsiteflexibility"] <= 99
                    ) {
                        if (
                            empty($jsonToArray["city"]) &&
                            empty($jsonToArray["state"])
                        ) {
                            $saveLocation = "";
                        } elseif (
                            !empty($jsonToArray["city"]) &&
                            empty($jsonToArray["state"])
                        ) {
                            $saveLocation = ucwords(
                                strtolower($jsonToArray["city"]),
                            );
                        } elseif (
                            empty($jsonToArray["city"]) &&
                            !empty($jsonToArray["state"])
                        ) {
                            $saveLocation = $jsonToArray["state"];
                        } else {
                            $saveLocation =
                                ucwords(strtolower($jsonToArray["city"])) .
                                " " .
                                $jsonToArray["state"];
                        }
                    } else {
                        $saveLocation = "";
                    }

                    $my_post = [
                        "post_title" => $jsonToArray["title"],
                        "post_name" => $jsonToArray["jobdivaid"],
                        "post_content" => $jsonToArray["jobdescription"],
                        "post_status" => "publish",
                        "post_author" => "1",
                        "post_type" => "job",
                        "meta_input" => [
                            "location" => $saveLocation,
                            "reference_number" => $jsonToArray["jobdiva_no"],
                            "requirements" => $jsonToArray["jobdescription"],
                            "responsibilities" =>
                                $jsonToArray["jobdescription"],
                            "desired" => $jsonToArray["jobdescription"],
                            "remote" => $jsonToArray["onsiteflexibility"],
                            //'industry' => $jsonToArray->industry_code1,
                            //'industry' => $jsonToArray->industry_code2,
                            "job_post_date" => $strip,
                            "job_type" => $remotelocation,
                        ],
                    ];

                    $post_id[] = wp_insert_post($my_post);
                }
            }
        }

        // If there are any field errors, send an email
        if (!empty($errors)) {
            send_admin_error_email(
                "The following issues were found during the job import:<br>" .
                    implode("<br>", $errors),
            );
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        $mesg = $errorMsg;
    }
}

function delete_custom_posts($post_type)
{
    global $wpdb;
    $result = $wpdb->query(
        $wpdb->prepare(
            "
            DELETE posts,pt,pm
            FROM " .
                $wpdb->prefix .
                "posts posts
            LEFT JOIN " .
                $wpdb->prefix .
                "term_relationships pt ON pt.object_id = posts.ID
            LEFT JOIN " .
                $wpdb->prefix .
                "postmeta pm ON pm.post_id = posts.ID
            WHERE posts.post_type = %s
            ",
            $post_type,
        ),
    );
    return $result !== false;
}

//////// Code for Cron Job /////////////////////////////////////////////////////////////////////

function shc_import_jobs_from_jobdiva_deactivate()
{
    wp_clear_scheduled_hook("shc_import_jobs_from_jobdiva_cron");
}
add_action("init", function () {
    add_action(
        "shc_import_jobs_from_jobdiva_cron",
        "shc_import_jobs_from_jobdiva_run_cron",
    );
    register_deactivation_hook(
        __FILE__,
        "shc_import_jobs_from_jobdiva_deactivate",
    );

    if (!wp_next_scheduled("shc_import_jobs_from_jobdiva_cron")) {
        wp_schedule_event(
            date("d-m-Y h:i:s A"),
            "daily",
            "shc_import_jobs_from_jobdiva_cron",
        );
    }
});
function shc_import_jobs_from_jobdiva_run_cron()
{
    import_jobs_from_jobdiva();
}

// Function to send email to admin
function send_admin_error_email($message)
{
    $admin_email = "jeremy@coneflower.org";
    $subject = "Job Import Error Notification";
    $body = "There were issues during the job import process:<br>" . $message;
    $headers = [];
    add_filter("wp_mail_content_type", function ($content_type) {
        return "text/html";
    });
    $headers[] =
        "From: SummitHumanCapital <no_reply@summithumancapital.com>" . "\r\n";

    if (wp_mail($admin_email, $subject, $body, $headers)) {
        echo "Mail Sent";
    } else {
        echo "Main Not Sent";
    }
}
