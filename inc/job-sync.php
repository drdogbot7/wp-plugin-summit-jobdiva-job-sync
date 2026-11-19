<?php
/**
 * Import jobs from the existing XML feed into WordPress job posts.
 *
 * The v2 API helpers below currently cache responses for inspection; the
 * scheduled importer still uses this legacy feed.
 */
function import_jobs_from_jobdiva()
{
    global $wpdb;
    try {
        // Keep the existing XML feed and field mapping until the v2 importer is ready.
        $apiUrla =
            "https://www1.jobdiva.com/employers/connect/listofportaljobs.jsp?a=1fjdnwj5s1czk3c1p3k8e25uyoaj0s096595yhp5d8kysoguwx94p7sxk7uhqlg0&&fulldesc=1";
        $xmlfile = file_get_contents($apiUrla);
        // Convert XML into an array to preserve the legacy field-access pattern.
        $new = simplexml_load_string(
            $xmlfile,
            "SimpleXMLElement",
            LIBXML_NOCDATA,
        );
        $con = json_encode($new);
        $data = json_decode($con, true);
        $newdata = $data["jobs"]["job"];

        $errors = [];
        $settings = get_option("summit_jobdiva_settings", []);
        $selected_division = summit_jobdiva_get_selected_division($settings);
        // Only the exact Federal division is Federal; all other values are Commercial.
        // Existing posts are deleted before replacement jobs are validated or inserted.
        $deleteJobs = delete_custom_posts("job");
        if ($deleteJobs) {
            foreach ($newdata as $key => $jsonToArray) {
                // Ignore malformed feed rows rather than reading fields from them.
                if (!is_array($jsonToArray)) {
                    continue;
                }
                $division = $jsonToArray["internal_division"] ?? "";
                $job_division =
                    $division === "Federal" ? "Federal" : "Commercial";
                if (
                    !empty($jsonToArray) &&
                    isset($jsonToArray["jobdivaid"]) &&
                    $jsonToArray["jobdivaid"] != "" &&
                    $job_division === $selected_division
                ) {
                    // Collect missing required values for the error email below.
                    // The current importer still attempts to insert these posts.
                    $fieldErrors = [];

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

                    // Store the JobDiva issue date in the site's YYYY-MM-DD format.
                    $date = $jsonToArray["issuedate"];

                    $createDate = new DateTime($date);

                    $strip = $createDate->format("Y-m-d");

                    // JobDiva scores flexibility from 0 (on-site) to 100 (remote);
                    // the theme displays those values as On-Site, Hybrid, or Remote.
                    $remotelocation = $jsonToArray["onsiteflexibility"];
                    if ($remotelocation == "100") {
                        $remotelocation = "Remote";
                    } elseif ($remotelocation == "0") {
                        $remotelocation = "On-Site";
                    } else {
                        $remotelocation = "Hybrid";
                    }

                    // Store location only for flexibility scores from 0 to 99,
                    // combining whichever city/state components are available.
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

                    // The current mapping writes the description to the post body
                    // and these three job-detail metadata fields.
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
                            "remote" => $jsonToArray["onsiteflexibility"],
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

/**
 * Delete posts of the requested type along with their terms and metadata.
 */
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

/**
 * Make a JobDiva authentication or refresh request and validate its token response.
 */
function summit_jobdiva_request_token($url, $headers = [])
{
    $response = wp_remote_get($url, [
        "headers" => array_merge(
            [
                "Accept" => "*/*",
            ],
            $headers,
        ),
        "timeout" => 20,
        "httpversion" => "1.1",
        "redirection" => 0,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        // Include useful API error text while ensuring credentials and tokens
        // from the request URL or headers cannot be copied into the log message.
        $response_data = json_decode(wp_remote_retrieve_body($response), true);
        $api_message = "";
        if (is_array($response_data)) {
            foreach (
                ["message", "error_description", "detail", "error"]
                as $field
            ) {
                if (
                    isset($response_data[$field]) &&
                    is_string($response_data[$field])
                ) {
                    $api_message = $response_data[$field];
                    break;
                }
            }
        }

        $query_parameters = [];
        $query_string = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($query_string)) {
            parse_str($query_string, $query_parameters);
        }
        $sensitive_values = array_merge(
            array_values($query_parameters),
            array_values($headers),
        );
        foreach ($sensitive_values as $sensitive_value) {
            if (!is_string($sensitive_value) || $sensitive_value === "") {
                continue;
            }

            foreach (
                [
                    $sensitive_value,
                    rawurlencode($sensitive_value),
                    urlencode($sensitive_value),
                ]
                as $encoded_value
            ) {
                if ($encoded_value !== "") {
                    $api_message = str_replace(
                        $encoded_value,
                        "[redacted]",
                        $api_message,
                    );
                }
            }
        }
        $api_message = substr(sanitize_text_field($api_message), 0, 250);

        return new WP_Error(
            "jobdiva_auth_http_error",
            "JobDiva authentication request failed with HTTP status " .
                $status .
                ($api_message !== "" ? ": " . $api_message : "") .
                ".",
        );
    }

    $token_data = json_decode(wp_remote_retrieve_body($response), true);
    if (
        !is_array($token_data) ||
        empty($token_data["token"]) ||
        empty($token_data["refreshtoken"])
    ) {
        return new WP_Error(
            "jobdiva_auth_invalid_response",
            "JobDiva returned an invalid token response.",
        );
    }

    // Keep only the token values needed for later requests plus their save time.
    return [
        "token" => $token_data["token"],
        "refreshtoken" => $token_data["refreshtoken"],
        "refreshed_at" => time(),
        "expires_at" => summit_jobdiva_get_token_expiration(
            $token_data["token"],
        ),
    ];
}

/**
 * Read the JWT expiration claim for refresh timing; null means it is unavailable.
 */
function summit_jobdiva_get_token_expiration($token)
{
    if (!is_string($token)) {
        return null;
    }

    $segments = explode(".", $token);
    if (count($segments) !== 3) {
        return null;
    }

    $payload = $segments[1];
    $payload .= str_repeat("=", (4 - (strlen($payload) % 4)) % 4);
    $decoded_payload = base64_decode(strtr($payload, "-_", "+/"), true);
    if (!is_string($decoded_payload)) {
        return null;
    }

    $claims = json_decode($decoded_payload, true);
    if (
        !is_array($claims) ||
        !isset($claims["exp"]) ||
        !is_numeric($claims["exp"])
    ) {
        return null;
    }

    return (int) $claims["exp"];
}

/**
 * Refresh the saved JobDiva token, falling back to credential authentication.
 */
function summit_jobdiva_refresh_auth_token()
{
    $settings = get_option("summit_jobdiva_settings", []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $client_id =
        isset($settings["client_id"]) && is_string($settings["client_id"])
            ? trim((string) $settings["client_id"])
            : "";
    $username =
        isset($settings["username"]) && is_string($settings["username"])
            ? trim((string) $settings["username"])
            : "";
    $password =
        isset($settings["password"]) && is_string($settings["password"])
            ? $settings["password"]
            : "";

    // Do not trim the password: whitespace may be part of the credential.
    if ($client_id === "" || $username === "" || $password === "") {
        error_log(
            "JobDiva token refresh failed: configure the client ID, username, and password in the JobDiva settings.",
        );
        return new WP_Error(
            "jobdiva_auth_missing_credentials",
            "Configure the JobDiva client ID, username, and password in settings.",
        );
    }

    $saved_auth = get_option("summit_jobdiva_auth", []);
    $token_data = false;
    // Prefer the refresh token when available; the error path below retries
    // with the saved credentials if refreshing fails.
    if (is_array($saved_auth) && !empty($saved_auth["refreshtoken"])) {
        $token_data = summit_jobdiva_request_token(
            "https://api.jobdiva.com/apiv2/v2/refreshToken",
            [
                "Authorization" => $saved_auth["refreshtoken"],
            ],
        );

        if (is_wp_error($token_data)) {
            error_log(
                "JobDiva token refresh failed; attempting to authenticate with the saved credentials.",
            );
        }
    }

    if (is_wp_error($token_data) || $token_data === false) {
        // RFC 3986 encoding keeps special characters inside their credential values.
        $auth_parameters = http_build_query(
            [
                "clientid" => $client_id,
                "username" => $username,
                "password" => $password,
            ],
            "",
            "&",
            PHP_QUERY_RFC3986,
        );
        $auth_url =
            "https://api.jobdiva.com/apiv2/v2/authenticate?" . $auth_parameters;
        $token_data = summit_jobdiva_request_token($auth_url);
    }

    if (is_wp_error($token_data)) {
        $error_code = $token_data->get_error_code();
        $error_detail = in_array(
            $error_code,
            ["jobdiva_auth_http_error", "jobdiva_auth_invalid_response"],
            true,
        )
            ? ": " . $token_data->get_error_message()
            : "";
        error_log(
            "JobDiva token authentication failed (error code: " .
                $error_code .
                ")" .
                $error_detail .
                ".",
        );
        return $token_data;
    }

    // Avoid loading token material during unrelated WordPress requests.
    update_option("summit_jobdiva_auth", $token_data, false);

    return $token_data;
}

/**
 * Return a usable access token, refreshing it near expiry or when forced.
 */
function summit_jobdiva_get_access_token($force_refresh = false)
{
    $saved_auth = get_option("summit_jobdiva_auth", []);
    $token =
        is_array($saved_auth) && isset($saved_auth["token"])
            ? $saved_auth["token"]
            : "";
    if (!is_string($token)) {
        $token = "";
    }

    $expires_at =
        is_array($saved_auth) &&
        isset($saved_auth["expires_at"]) &&
        is_numeric($saved_auth["expires_at"])
            ? (int) $saved_auth["expires_at"]
            : summit_jobdiva_get_token_expiration($token);

    $refresh_is_due =
        $token === "" ||
        ($expires_at !== null && $expires_at <= time() + 5 * MINUTE_IN_SECONDS);
    if (!$force_refresh && !$refresh_is_due) {
        return $token;
    }

    $token_data = summit_jobdiva_refresh_auth_token();
    if (!is_wp_error($token_data)) {
        return $token_data["token"];
    }

    // A still-valid token can serve this request if a proactive refresh fails.
    if (
        !$force_refresh &&
        $token !== "" &&
        ($expires_at === null || $expires_at > time())
    ) {
        return $token;
    }

    return $token_data;
}

/**
 * Send an authenticated v2 API request, refreshing and retrying once on 401.
 */
function summit_jobdiva_api_get($url)
{
    $token = summit_jobdiva_get_access_token();
    if (is_wp_error($token)) {
        return $token;
    }

    $request_args = [
        "headers" => [
            "Accept" => "application/json",
            "Authorization" => $token,
        ],
        "timeout" => 30,
        "httpversion" => "1.1",
    ];
    $response = wp_remote_get($url, $request_args);
    if (is_wp_error($response)) {
        return $response;
    }

    if (wp_remote_retrieve_response_code($response) === 401) {
        $token = summit_jobdiva_get_access_token(true);
        if (is_wp_error($token)) {
            return $token;
        }

        $request_args["headers"]["Authorization"] = $token;
        $response = wp_remote_get($url, $request_args);
        if (is_wp_error($response)) {
            return $response;
        }
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return new WP_Error(
            "jobdiva_api_http_error",
            "JobDiva API request failed with HTTP status " . $status . ".",
        );
    }

    return $response;
}

/**
 * Fetch and cache the v2 OpenJobsList JSON response for on-demand inspection.
 */
function summit_jobdiva_cache_open_jobs_response()
{
    $response = summit_jobdiva_api_get(
        "https://api.jobdiva.com/apiv2/bi/OpenJobsList",
    );
    if (is_wp_error($response)) {
        return $response;
    }

    $jobs_response = json_decode(wp_remote_retrieve_body($response), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($jobs_response)) {
        return new WP_Error(
            "jobdiva_open_jobs_invalid_json",
            "JobDiva OpenJobsList returned invalid JSON.",
        );
    }

    // Keep the decoded response available for the detail-fetch step and inspection.
    set_transient(
        "summit_jobdiva_open_jobs_response",
        $jobs_response,
        HOUR_IN_SECONDS,
    );

    return true;
}

/**
 * Fetch details for jobs in the cached list and cache their JSON response.
 */
function summit_jobdiva_cache_job_details_response()
{
    $open_jobs = get_transient("summit_jobdiva_open_jobs_response");
    if (
        !is_array($open_jobs) ||
        !isset($open_jobs["data"]) ||
        !is_array($open_jobs["data"])
    ) {
        return new WP_Error(
            "jobdiva_open_jobs_missing_data",
            "Fetch the OpenJobsList response before fetching job details.",
        );
    }

    $settings = get_option("summit_jobdiva_settings", []);
    $selected_division = summit_jobdiva_get_selected_division($settings);
    $job_ids = [];
    // Classify listed jobs before requesting details, using the configured division.
    foreach ($open_jobs["data"] as $job) {
        if (!is_array($job)) {
            continue;
        }

        $division = $job["DIVISIONNAME"] ?? ($job["internal_division"] ?? "");
        $job_division = $division === "Federal" ? "Federal" : "Commercial";
        if ($job_division !== $selected_division) {
            continue;
        }

        $job_id = $job["JOBID"] ?? ($job["jobdivaid"] ?? "");
        if (
            !(is_string($job_id) || is_int($job_id)) ||
            !ctype_digit((string) $job_id) ||
            (string) $job_id === "0"
        ) {
            continue;
        }

        $job_ids[] = (string) $job_id;
    }
    // The endpoint accepts repeated jobIds parameters; send each unique ID once.
    $job_ids = array_values(array_unique($job_ids));

    if ($job_ids === []) {
        // An empty selection is still a valid result for the chosen division.
        set_transient(
            "summit_jobdiva_job_details_response",
            [],
            HOUR_IN_SECONDS,
        );
        return true;
    }

    $query = implode(
        "&",
        array_map(
            static fn($job_id) => "jobIds=" . rawurlencode($job_id),
            $job_ids,
        ),
    );
    $response = summit_jobdiva_api_get(
        "https://api.jobdiva.com/apiv2/bi/JobsDetail?" . $query,
    );
    if (is_wp_error($response)) {
        return $response;
    }

    $details_response = json_decode(wp_remote_retrieve_body($response), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($details_response)) {
        return new WP_Error(
            "jobdiva_job_details_invalid_json",
            "JobDiva JobsDetail returned invalid JSON.",
        );
    }

    // Keep the raw decoded response available for inspection for one hour.
    set_transient(
        "summit_jobdiva_job_details_response",
        $details_response,
        HOUR_IN_SECONDS,
    );

    return true;
}

//////// Code for Cron Job /////////////////////////////////////////////////////////////////////

/**
 * Remove both scheduled events when the plugin is deactivated.
 */
function shc_import_jobs_from_jobdiva_deactivate()
{
    wp_clear_scheduled_hook("shc_import_jobs_from_jobdiva_cron");
    wp_clear_scheduled_hook("shc_jobdiva_token_refresh_cron");
}

// Register callbacks globally so WordPress can dispatch the scheduled events.
add_action(
    "shc_import_jobs_from_jobdiva_cron",
    "shc_import_jobs_from_jobdiva_run_cron",
);
register_deactivation_hook(
    dirname(__DIR__) . "/index.php",
    "shc_import_jobs_from_jobdiva_deactivate",
);

add_action("init", function () {
    // Preserve the existing importer schedule when no import event exists.
    if (!wp_next_scheduled("shc_import_jobs_from_jobdiva_cron")) {
        wp_schedule_event(
            date("d-m-Y h:i:s A"),
            "daily",
            "shc_import_jobs_from_jobdiva_cron",
        );
    }

    // Remove the old fixed weekly refresh; v2 requests now refresh as needed.
    if (wp_next_scheduled("shc_jobdiva_token_refresh_cron")) {
        wp_clear_scheduled_hook("shc_jobdiva_token_refresh_cron");
    }
});

/**
 * Run the existing importer when its cron event fires.
 */
function shc_import_jobs_from_jobdiva_run_cron()
{
    import_jobs_from_jobdiva();
}

/**
 * Email the site administrator about job-import field validation issues.
 */
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
