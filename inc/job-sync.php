<?php
/**
 * Index existing JobDiva posts by their durable external ID.
 */
function summit_jobdiva_get_existing_job_posts()
{
    $post_ids = get_posts([
        "post_type" => "job",
        "post_status" => array_values(get_post_stati([], "names")),
        "numberposts" => -1,
        "fields" => "ids",
    ]);

    $existing_jobs = [];
    foreach ($post_ids as $post_id) {
        $job_id = get_post_meta($post_id, "_summit_jobdiva_id", true);
        if (
            !(is_string($job_id) || is_int($job_id)) ||
            !ctype_digit((string) $job_id) ||
            (string) $job_id === "0"
        ) {
            continue;
        }

        $job_id = (string) $job_id;
        if (isset($existing_jobs[$job_id])) {
            return new WP_Error(
                "jobdiva_duplicate_local_job",
                "Multiple WordPress job posts match the same JobDiva ID.",
            );
        }

        $existing_jobs[$job_id] = (int) $post_id;
    }

    return $existing_jobs;
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
        !isset($token_data["token"], $token_data["refreshtoken"]) ||
        !is_string($token_data["token"]) ||
        !is_string($token_data["refreshtoken"]) ||
        $token_data["token"] === "" ||
        $token_data["refreshtoken"] === ""
    ) {
        return new WP_Error(
            "jobdiva_auth_invalid_response",
            "JobDiva returned an invalid token response.",
        );
    }

    return [
        "token" => $token_data["token"],
        "refreshtoken" => $token_data["refreshtoken"],
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
    // This claim is only a refresh-timing hint; JobDiva validates the token itself.
    if (!is_string($token)) {
        return null;
    }

    // JWTs have three dot-separated parts: header, payload, and signature.
    $segments = explode(".", $token);
    if (count($segments) !== 3) {
        return null;
    }

    // The payload is Base64URL-encoded (not encrypted); restore its Base64 alphabet
    // and padding before decoding it.
    $payload = $segments[1];
    $payload .= str_repeat("=", (4 - (strlen($payload) % 4)) % 4);
    $decoded_payload = base64_decode(strtr($payload, "-_", "+/"), true);
    if (!is_string($decoded_payload)) {
        return null;
    }

    // The JWT exp claim is a NumericDate: seconds since the Unix epoch.
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

function summit_jobdiva_fetch_open_jobs_response()
{
    $response = summit_jobdiva_api_get(
        "https://api.jobdiva.com/apiv2/bi/OpenJobsList",
    );
    if (is_wp_error($response)) {
        return $response;
    }

    $jobs_response = json_decode(wp_remote_retrieve_body($response), true);
    if (
        json_last_error() !== JSON_ERROR_NONE ||
        !is_array($jobs_response) ||
        !isset($jobs_response["data"]) ||
        !is_array($jobs_response["data"])
    ) {
        return new WP_Error(
            "jobdiva_open_jobs_invalid_json",
            "JobDiva OpenJobsList returned an invalid job list.",
        );
    }

    return $jobs_response;
}

/**
 * Select OpenJobsList records for the configured division, keyed by JobDiva ID.
 */
function summit_jobdiva_select_open_jobs($jobs_response, $selected_division)
{
    if (
        !is_array($jobs_response) ||
        !isset($jobs_response["data"]) ||
        !is_array($jobs_response["data"])
    ) {
        return new WP_Error(
            "jobdiva_open_jobs_missing_data",
            "JobDiva OpenJobsList did not contain job data.",
        );
    }

    $selected_jobs = [];
    foreach ($jobs_response["data"] as $job) {
        if (!is_array($job)) {
            return new WP_Error(
                "jobdiva_open_jobs_invalid_record",
                "JobDiva OpenJobsList contained an invalid job record.",
            );
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
            return new WP_Error(
                "jobdiva_open_jobs_invalid_id",
                "A selected JobDiva job did not contain a valid job ID.",
            );
        }

        $selected_jobs[(string) $job_id] = $job;
    }

    return $selected_jobs;
}

/**
 * Fetch full details for the supplied numeric JobDiva IDs.
 */
function summit_jobdiva_fetch_job_details_response($job_ids)
{
    if ($job_ids === []) {
        return [];
    }

    $query = implode(
        "&",
        array_map(
            static fn($job_id) => "jobIds=" . rawurlencode((string) $job_id),
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
    if (
        json_last_error() !== JSON_ERROR_NONE ||
        !is_array($details_response) ||
        !isset($details_response["data"]) ||
        !is_array($details_response["data"])
    ) {
        return new WP_Error(
            "jobdiva_job_details_invalid_json",
            "JobDiva JobsDetail returned an invalid response.",
        );
    }

    return $details_response;
}

function summit_jobdiva_get_negative_keywords($settings)
{
    if (
        !is_array($settings) ||
        !array_key_exists("negative_keywords", $settings)
    ) {
        $keywords = "";
    } elseif (is_string($settings["negative_keywords"])) {
        $keywords = $settings["negative_keywords"];
    } else {
        $keywords = "";
    }

    $keywords = preg_split('/\r\n|\r|\n/', $keywords);

    return array_values(
        array_filter(
            array_map("trim", $keywords),
            static fn($keyword) => $keyword !== "",
        ),
    );
}

function summit_jobdiva_should_exclude_job_title($title, $negative_keywords)
{
    if (!is_string($title)) {
        return false;
    }

    foreach ($negative_keywords as $keyword) {
        if ($keyword !== "" && stripos($title, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Map one v2 JobDetail record to the WordPress post format used by the site.
 */
function summit_jobdiva_map_job_to_post($job_id, $list_job, $job)
{
    $title = $job["JOBTITLE"] ?? ($list_job["TITLE"] ?? "");
    $date_value = $job["DATEISSUED"] ?? ($list_job["ISSUEDATE"] ?? "");
    $flexibility =
        $job["ONSITE_FLEXIBILITY"] ?? ($list_job["ONSITE_FLEXIBILITY"] ?? null);

    $errors = [];
    if (!is_string($title) || trim($title) === "") {
        $errors[] = "Job Title is empty";
    }

    $issue_date = false;
    if (is_string($date_value) && trim($date_value) !== "") {
        try {
            $issue_date = date_create($date_value);
        } catch (Throwable $error) {
            $issue_date = false;
        }
    }
    if ($issue_date === false) {
        $errors[] = "Issue date is missing or invalid";
    }

    if (!is_numeric($flexibility)) {
        $errors[] = "Onsite flexibility is missing or invalid";
    }

    if ($errors !== []) {
        return new WP_Error(
            "jobdiva_job_invalid_fields",
            implode(", ", $errors),
        );
    }

    $flexibility_value = (float) $flexibility;
    if ($flexibility_value == 100) {
        $job_type = "Remote";
    } elseif ($flexibility_value == 0) {
        $job_type = "On-Site";
    } else {
        $job_type = "Hybrid";
    }

    $city = $job["CITY"] ?? ($list_job["CITY"] ?? "");
    $state = $job["STATE"] ?? ($list_job["STATE"] ?? "");
    $city = is_string($city) ? $city : "";
    $state = is_string($state) ? $state : "";
    $location = "";
    if ($flexibility_value >= 0 && $flexibility_value <= 99) {
        if (!empty($city) && !empty($state)) {
            $location = ucwords(strtolower($city)) . ", " . $state;
        } elseif (!empty($city)) {
            $location = ucwords(strtolower($city));
        } elseif (!empty($state)) {
            $location = $state;
        }
    }

    $reference_number = $job["JOBDIVANO"] ?? ($list_job["JOBDIVANO"] ?? "");
    $description = $job["JOBDESCRIPTION"] ?? "";

    return [
        "post_title" => $title,
        "post_name" => (string) $job_id,
        "post_content" => is_string($description) ? $description : "",
        "post_status" => "publish",
        "post_author" => "1",
        "post_type" => "job",
        "meta_input" => [
            "_summit_jobdiva_id" => (string) $job_id,
            "location" => $location,
            "reference_number" => is_scalar($reference_number)
                ? (string) $reference_number
                : "",
            "remote" => (string) $flexibility,
            "job_post_date" => $issue_date->format("Y-m-d"),
            "job_type" => $job_type,
        ],
    ];
}

/**
 * Save the outcome of the latest JobDiva sync for the settings page.
 */
function summit_jobdiva_save_sync_status(
    $status,
    $added,
    $trashed,
    $excluded,
    $error = "",
) {
    update_option(
        "summit_jobdiva_last_sync",
        [
            "status" => $status,
            "timestamp" => time(),
            "added" => (int) $added,
            "trashed" => (int) $trashed,
            "excluded" => (int) $excluded,
            "error" => $error,
        ],
        false,
    );
}

/**
 * Log and email a JobDiva import failure.
 */
function summit_jobdiva_report_import_error(
    $error,
    $email_message = "",
    $added = 0,
    $trashed = 0,
    $excluded = 0,
    $status_error = "",
) {
    error_log("JobDiva import failed: " . $error->get_error_message());
    summit_jobdiva_save_sync_status(
        "failed",
        $added,
        $trashed,
        $excluded,
        $status_error !== "" ? $status_error : $error->get_error_message(),
    );

    if ($email_message === "") {
        $email_message = esc_html($error->get_error_message());
    }

    send_admin_error_email($email_message);

    return $error;
}

/**
 * Import selected JobDiva jobs from the v2 list and detail endpoints.
 */
function import_jobs_from_jobdiva()
{
    $settings = get_option("summit_jobdiva_settings", []);
    $selected_division = summit_jobdiva_get_selected_division($settings);

    $jobs_response = summit_jobdiva_fetch_open_jobs_response();
    if (is_wp_error($jobs_response)) {
        return summit_jobdiva_report_import_error($jobs_response);
    }

    if ($jobs_response["data"] === []) {
        $error = new WP_Error(
            "jobdiva_open_jobs_empty",
            "JobDiva OpenJobsList returned no open jobs; existing posts were left unchanged.",
        );
        return summit_jobdiva_report_import_error($error);
    }

    $selected_jobs = summit_jobdiva_select_open_jobs(
        $jobs_response,
        $selected_division,
    );
    if (is_wp_error($selected_jobs)) {
        return summit_jobdiva_report_import_error($selected_jobs);
    }

    $details_response = summit_jobdiva_fetch_job_details_response(
        array_keys($selected_jobs),
    );
    if (is_wp_error($details_response)) {
        return summit_jobdiva_report_import_error($details_response);
    }

    set_transient(
        "summit_jobdiva_job_details_response",
        $details_response,
        HOUR_IN_SECONDS,
    );

    $details_by_id = [];
    foreach ($details_response["data"] ?? [] as $job) {
        if (!is_array($job)) {
            $error = new WP_Error(
                "jobdiva_job_details_invalid_record",
                "JobDiva JobsDetail contained an invalid job record.",
            );
            return summit_jobdiva_report_import_error($error);
        }

        $job_id = $job["ID"] ?? "";
        if (
            !(is_string($job_id) || is_int($job_id)) ||
            !ctype_digit((string) $job_id)
        ) {
            $error = new WP_Error(
                "jobdiva_job_details_invalid_id",
                "JobDiva JobsDetail contained a record without a valid job ID.",
            );
            return summit_jobdiva_report_import_error($error);
        }

        $job_id = (string) $job_id;
        if (isset($selected_jobs[$job_id])) {
            $details_by_id[$job_id] = $job;
        }
    }

    $missing_details = array_diff(
        array_keys($selected_jobs),
        array_keys($details_by_id),
    );
    if ($missing_details !== []) {
        $error = new WP_Error(
            "jobdiva_job_details_incomplete",
            "JobDiva JobsDetail did not return details for every selected job.",
        );
        return summit_jobdiva_report_import_error($error);
    }

    $posts = [];
    $field_errors = [];
    $negative_keywords = summit_jobdiva_get_negative_keywords($settings);
    $excluded_count = 0;
    foreach ($selected_jobs as $job_id => $list_job) {
        $title =
            $details_by_id[$job_id]["JOBTITLE"] ?? ($list_job["TITLE"] ?? "");
        if (
            summit_jobdiva_should_exclude_job_title($title, $negative_keywords)
        ) {
            $excluded_count++;
            continue;
        }

        $post = summit_jobdiva_map_job_to_post(
            $job_id,
            $list_job,
            $details_by_id[$job_id],
        );
        if (is_wp_error($post)) {
            $field_errors[] =
                "Job ID: " .
                $job_id .
                " has the following issues: " .
                $post->get_error_message();
            continue;
        }

        $posts[(string) $job_id] = $post;
    }

    if ($field_errors !== []) {
        $error = new WP_Error(
            "jobdiva_job_details_invalid_fields",
            "JobDiva job details failed validation; existing posts were not changed.",
        );
        return summit_jobdiva_report_import_error(
            $error,
            "The following issues prevented the JobDiva import:<br>" .
                implode("<br>", array_map("esc_html", $field_errors)),
            0,
            0,
            $excluded_count,
            implode("; ", $field_errors),
        );
    }

    $existing_jobs = summit_jobdiva_get_existing_job_posts();
    if (is_wp_error($existing_jobs)) {
        return summit_jobdiva_report_import_error(
            $existing_jobs,
            "",
            0,
            0,
            $excluded_count,
        );
    }

    $upsert_errors = [];
    $added_count = 0;
    foreach ($posts as $job_id => $post) {
        $is_new_job = !isset($existing_jobs[$job_id]);
        if (!$is_new_job) {
            // Preserve the existing slug so WordPress keeps the same permalink.
            $post["ID"] = $existing_jobs[$job_id];
            unset($post["post_name"]);
            $post_id = wp_update_post($post, true);
        } else {
            $post_id = wp_insert_post($post, true);
        }

        if (is_wp_error($post_id) || !$post_id) {
            $upsert_errors[] =
                "Job ID: " .
                $job_id .
                " could not be saved: " .
                (is_wp_error($post_id)
                    ? $post_id->get_error_message()
                    : "WordPress did not return a post ID.");
            continue;
        }

        if ($is_new_job) {
            $added_count++;
        }
    }

    if ($upsert_errors !== []) {
        $error = new WP_Error(
            "jobdiva_import_upsert_failed",
            "One or more JobDiva jobs could not be saved; stale jobs were left unchanged.",
        );
        return summit_jobdiva_report_import_error(
            $error,
            "The following errors occurred while saving JobDiva jobs:<br>" .
                implode("<br>", array_map("esc_html", $upsert_errors)),
            $added_count,
            0,
            $excluded_count,
            implode("; ", $upsert_errors),
        );
    }

    $trash_errors = [];
    $trashed_count = 0;
    foreach ($existing_jobs as $job_id => $post_id) {
        if (isset($posts[$job_id]) || get_post_status($post_id) === "trash") {
            continue;
        }

        $trashed_post = wp_trash_post($post_id);
        if (is_wp_error($trashed_post) || !$trashed_post) {
            $trash_errors[] =
                "Job ID: " . $job_id . " could not be moved to the trash.";
        } else {
            $trashed_count++;
        }
    }

    if ($trash_errors !== []) {
        $error = new WP_Error(
            "jobdiva_import_trash_failed",
            "One or more stale JobDiva jobs could not be moved to the trash.",
        );
        return summit_jobdiva_report_import_error(
            $error,
            "The following errors occurred while moving stale JobDiva jobs to the trash:<br>" .
                implode("<br>", array_map("esc_html", $trash_errors)),
            $added_count,
            $trashed_count,
            $excluded_count,
            implode("; ", $trash_errors),
        );
    }

    summit_jobdiva_save_sync_status(
        "success",
        $added_count,
        $trashed_count,
        $excluded_count,
    );

    return true;
}

//////// Code for Cron Job /////////////////////////////////////////////////////////////////////

/**
 * Remove the importer schedule when the plugin is deactivated.
 */
function shc_import_jobs_from_jobdiva_deactivate()
{
    wp_clear_scheduled_hook("shc_import_jobs_from_jobdiva_cron");
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
            time() + MINUTE_IN_SECONDS,
            "daily",
            "shc_import_jobs_from_jobdiva_cron",
        );
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
 * Email the configured administrator about a JobDiva import failure.
 */
function send_admin_error_email($message)
{
    $settings = get_option("summit_jobdiva_settings", []);
    $admin_email =
        is_array($settings) && isset($settings["admin_email"])
            ? $settings["admin_email"]
            : "";
    if (!is_string($admin_email) || !is_email($admin_email)) {
        $admin_email = get_option("admin_email", "");
    }
    if (!is_string($admin_email) || !is_email($admin_email)) {
        error_log(
            "JobDiva import notification was not sent: no valid notification email is configured.",
        );
        return false;
    }

    $subject = "Job Import Error Notification";
    $body = "There were issues during the job import process:<br>" . $message;
    $headers = [
        "Content-Type: text/html; charset=UTF-8",
        "From: SummitHumanCapital <no_reply@summithumancapital.com>",
    ];

    $sent = wp_mail($admin_email, $subject, $body, $headers);
    if (!$sent) {
        error_log("JobDiva import notification email could not be sent.");
    }

    return $sent;
}
