<?php
function summit_jobdiva_register_settings()
{
    register_setting(
        "summit_jobdiva_settings_group",
        "summit_jobdiva_settings",
        [
            "type" => "array",
            "sanitize_callback" => "summit_jobdiva_sanitize_settings",
            "default" => [
                "client_id" => "",
                "username" => "",
                "password" => "",
                "job_division" => "Commercial",
                "admin_email" => "",
                "negative_keywords" => "",
            ],
        ],
    );

    add_settings_section(
        "summit_jobdiva_connection",
        __("JobDiva Connection", "summit-jobdiva"),
        "__return_null",
        "summit-jobdiva-settings",
    );

    add_settings_field(
        "summit_jobdiva_client_id",
        __("JobDiva Client ID", "summit-jobdiva"),
        "summit_jobdiva_render_client_id_field",
        "summit-jobdiva-settings",
        "summit_jobdiva_connection",
    );

    add_settings_field(
        "summit_jobdiva_username",
        __("JobDiva Username", "summit-jobdiva"),
        "summit_jobdiva_render_username_field",
        "summit-jobdiva-settings",
        "summit_jobdiva_connection",
    );

    add_settings_field(
        "summit_jobdiva_password",
        __("JobDiva Password", "summit-jobdiva"),
        "summit_jobdiva_render_password_field",
        "summit-jobdiva-settings",
        "summit_jobdiva_connection",
    );

    add_settings_field(
        "summit_jobdiva_job_division",
        __("Job Division", "summit-jobdiva"),
        "summit_jobdiva_render_job_division_field",
        "summit-jobdiva-settings",
        "summit_jobdiva_connection",
    );

    add_settings_field(
        "summit_jobdiva_admin_email",
        __("Admin Notification Email", "summit-jobdiva"),
        "summit_jobdiva_render_admin_email_field",
        "summit-jobdiva-settings",
        "summit_jobdiva_connection",
    );

    add_settings_section(
        "summit_jobdiva_job_filters",
        __("Job Filters", "summit-jobdiva"),
        "__return_null",
        "summit-jobdiva-settings",
    );

    add_settings_field(
        "summit_jobdiva_negative_keywords",
        __("Exclude Job Titles Containing", "summit-jobdiva"),
        "summit_jobdiva_render_negative_keywords_field",
        "summit-jobdiva-settings",
        "summit_jobdiva_job_filters",
    );
}
add_action("admin_init", "summit_jobdiva_register_settings");

function summit_jobdiva_register_options_page()
{
    add_options_page(
        __("JobDiva Settings", "summit-jobdiva"),
        __("JobDiva", "summit-jobdiva"),
        "manage_options",
        "summit-jobdiva-settings",
        "summit_jobdiva_render_options_page",
    );
}
add_action("admin_menu", "summit_jobdiva_register_options_page");

add_action(
    "admin_post_summit_jobdiva_manual_sync",
    "summit_jobdiva_handle_manual_sync",
);

function summit_jobdiva_handle_manual_sync()
{
    if (!current_user_can("manage_options")) {
        wp_die(
            esc_html__(
                "You are not allowed to run a JobDiva sync.",
                "summit-jobdiva",
            ),
        );
    }

    check_admin_referer("summit_jobdiva_manual_sync");
    import_jobs_from_jobdiva();

    wp_safe_redirect(
        add_query_arg(
            "page",
            "summit-jobdiva-settings",
            admin_url("options-general.php"),
        ),
    );
    exit();
}

function summit_jobdiva_sanitize_settings($input)
{
    if (!is_array($input)) {
        add_settings_error(
            "summit_jobdiva_settings",
            "invalid_settings",
            __("Invalid JobDiva settings.", "summit-jobdiva"),
        );
        return get_option("summit_jobdiva_settings", []);
    }

    $current_settings = get_option("summit_jobdiva_settings", []);
    if (!is_array($current_settings)) {
        $current_settings = [];
    }

    $client_id =
        isset($input["client_id"]) && is_string($input["client_id"])
            ? sanitize_text_field(wp_unslash($input["client_id"]))
            : "";
    $username =
        isset($input["username"]) && is_string($input["username"])
            ? sanitize_text_field(wp_unslash($input["username"]))
            : "";
    $password =
        isset($input["password"]) && is_string($input["password"])
            ? wp_unslash($input["password"])
            : "";
    $job_division =
        isset($input["job_division"]) && is_string($input["job_division"])
            ? sanitize_text_field(wp_unslash($input["job_division"]))
            : "";
    $admin_email_input =
        isset($input["admin_email"]) && is_string($input["admin_email"])
            ? trim(wp_unslash($input["admin_email"]))
            : "";
    if (
        isset($input["negative_keywords"]) &&
        is_string($input["negative_keywords"])
    ) {
        $negative_keywords = sanitize_textarea_field(
            wp_unslash($input["negative_keywords"]),
        );
    } elseif (
        isset($current_settings["negative_keywords"]) &&
        is_string($current_settings["negative_keywords"])
    ) {
        $negative_keywords = $current_settings["negative_keywords"];
    } else {
        $negative_keywords = "";
    }
    $admin_email = sanitize_email($admin_email_input);
    if ($admin_email_input !== "" && !is_email($admin_email)) {
        add_settings_error(
            "summit_jobdiva_settings",
            "invalid_admin_email",
            __(
                "Please enter a valid admin notification email address.",
                "summit-jobdiva",
            ),
        );
        $admin_email =
            isset($current_settings["admin_email"]) &&
            is_string($current_settings["admin_email"]) &&
            is_email($current_settings["admin_email"])
                ? $current_settings["admin_email"]
                : "";
    }

    if (!in_array($job_division, ["Federal", "Commercial"], true)) {
        $job_division = summit_jobdiva_get_selected_division($current_settings);
    }

    // An empty password field means keep the saved credential, not clear it.
    return [
        "client_id" => $client_id,
        "username" => $username,
        "password" =>
            $password !== "" ? $password : $current_settings["password"] ?? "",
        "job_division" => $job_division,
        "admin_email" => $admin_email,
        "negative_keywords" => $negative_keywords,
    ];
}

function summit_jobdiva_get_selected_division($settings)
{
    if (!is_array($settings)) {
        return "Commercial";
    }

    if (
        isset($settings["job_division"]) &&
        in_array($settings["job_division"], ["Federal", "Commercial"], true)
    ) {
        return $settings["job_division"];
    }

    return "Commercial";
}

function summit_jobdiva_render_client_id_field()
{
    $settings = get_option("summit_jobdiva_settings", []);
    $client_id = is_array($settings) ? $settings["client_id"] ?? "" : "";
    ?>
    <input
        type="text"
        id="summit_jobdiva_client_id"
        name="summit_jobdiva_settings[client_id]"
        value="<?php echo esc_attr($client_id); ?>"
        class="regular-text"
        autocomplete="client_id"
    />
    <?php
}

function summit_jobdiva_render_username_field()
{
    $settings = get_option("summit_jobdiva_settings", []);
    $username = is_array($settings) ? $settings["username"] ?? "" : "";
    ?>
    <input
        type="text"
        id="summit_jobdiva_username"
        name="summit_jobdiva_settings[username]"
        value="<?php echo esc_attr($username); ?>"
        class="regular-text"
        autocomplete="username"
    />
    <?php
}

function summit_jobdiva_render_password_field()
{
    ?>
    <input
        type="password"
        id="summit_jobdiva_password"
        name="summit_jobdiva_settings[password]"
        value=""
        class="regular-text"
        autocomplete="new-password"
    />
    <p class="description">
        <?php esc_html_e(
            "Leave blank to keep the current password.",
            "summit-jobdiva",
        ); ?>
    </p>
    <?php
}

function summit_jobdiva_render_job_division_field()
{
    $settings = get_option("summit_jobdiva_settings", []);
    $job_division = summit_jobdiva_get_selected_division($settings);
    ?>
    <select
        id="summit_jobdiva_job_division"
        name="summit_jobdiva_settings[job_division]"
    >
        <option value="Federal" <?php selected($job_division, "Federal"); ?>>
            <?php esc_html_e("Federal", "summit-jobdiva"); ?>
        </option>
        <option value="Commercial" <?php selected(
            $job_division,
            "Commercial",
        ); ?>>
            <?php esc_html_e("Commercial", "summit-jobdiva"); ?>
        </option>
    </select>
    <p class="description">
        <?php esc_html_e(
            "Federal includes jobs with a Federal division name; Commercial includes all other jobs.",
            "summit-jobdiva",
        ); ?>
    </p>
    <?php
}

function summit_jobdiva_render_admin_email_field()
{
    $settings = get_option("summit_jobdiva_settings", []);
    $admin_email =
        is_array($settings) && isset($settings["admin_email"])
            ? $settings["admin_email"]
            : "";
    if (!is_string($admin_email) || !is_email($admin_email)) {
        $admin_email = get_option("admin_email", "");
    }
    ?>
    <input
        type="email"
        id="summit_jobdiva_admin_email"
        name="summit_jobdiva_settings[admin_email]"
        value="<?php echo esc_attr($admin_email); ?>"
        class="regular-text"
        autocomplete="email"
    />
    <p class="description">
        <?php esc_html_e(
            "Job import failure notifications are sent here. Leave blank to use the WordPress admin email.",
            "summit-jobdiva",
        ); ?>
    </p>
    <?php
}

function summit_jobdiva_render_negative_keywords_field()
{
    $settings = get_option("summit_jobdiva_settings", []);
    $negative_keywords =
        is_array($settings) && isset($settings["negative_keywords"])
            ? $settings["negative_keywords"]
            : "";
    if (!is_string($negative_keywords)) {
        $negative_keywords = "";
    }
    ?>
    <textarea
        id="summit_jobdiva_negative_keywords"
        name="summit_jobdiva_settings[negative_keywords]"
        rows="6"
        class="large-text"
    ><?php echo esc_textarea($negative_keywords); ?></textarea>
    <p class="description">
        <?php esc_html_e(
            "Enter one keyword or phrase per line. A job is excluded when its title contains any listed term, regardless of capitalization.",
            "summit-jobdiva",
        ); ?>
    </p>
    <?php
}

function summit_jobdiva_render_last_sync_status()
{
    $sync_status = get_option("summit_jobdiva_last_sync", []);
    if (
        !is_array($sync_status) ||
        !isset($sync_status["status"], $sync_status["timestamp"]) ||
        !is_numeric($sync_status["timestamp"]) ||
        !in_array($sync_status["status"], ["success", "failed"], true)
    ) { ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e(
                "No JobDiva sync status is available yet.",
                "summit-jobdiva",
            ); ?></p>
        </div>
        <?php return;}

    $timestamp = (int) $sync_status["timestamp"];
    $date_format = get_option("date_format") . " " . get_option("time_format");
    $completed_at = wp_date($date_format, $timestamp);
    $added =
        isset($sync_status["added"]) && is_numeric($sync_status["added"])
            ? max(0, (int) $sync_status["added"])
            : 0;
    $trashed =
        isset($sync_status["trashed"]) && is_numeric($sync_status["trashed"])
            ? max(0, (int) $sync_status["trashed"])
            : 0;
    $excluded =
        isset($sync_status["excluded"]) && is_numeric($sync_status["excluded"])
            ? max(0, (int) $sync_status["excluded"])
            : 0;

    if ($sync_status["status"] === "success") { ?>
            <p>
                <strong><?php esc_html_e(
                    "Last JobDiva sync completed:",
                    "summit-jobdiva",
                ); ?></strong>
                <?php echo esc_html($completed_at); ?>
            </p>
            <p>
                <?php printf(
                    esc_html__(
                        "%1\$s jobs added; %2\$s jobs trashed; %3\$s jobs excluded.",
                        "summit-jobdiva",
                    ),
                    esc_html(number_format_i18n($added)),
                    esc_html(number_format_i18n($trashed)),
                    esc_html(number_format_i18n($excluded)),
                ); ?>
            </p>
        <?php return;}

    $error =
        isset($sync_status["error"]) && is_string($sync_status["error"])
            ? $sync_status["error"]
            : __("Unknown JobDiva sync error.", "summit-jobdiva");
    ?>
    <div class="notice notice-error inline" role="status">
        <p>
            <strong><?php esc_html_e(
                "Last JobDiva sync failed:",
                "summit-jobdiva",
            ); ?></strong>
            <?php echo esc_html($completed_at); ?>
        </p>
        <p>
            <strong><?php esc_html_e("Error:", "summit-jobdiva"); ?></strong>
            <?php echo esc_html($error); ?>
        </p>
        <p>
            <?php printf(
                esc_html__(
                    "Before failure: %1\$s jobs added; %2\$s jobs trashed; %3\$s jobs excluded.",
                    "summit-jobdiva",
                ),
                esc_html(number_format_i18n($added)),
                esc_html(number_format_i18n($trashed)),
                esc_html(number_format_i18n($excluded)),
            ); ?>
        </p>
    </div>
    <?php
}

function summit_jobdiva_render_options_page()
{
    if (!current_user_can("manage_options")) {
        return;
    } ?>
    <div class="wrap">
        <h1><?php esc_html_e("JobDiva Settings", "summit-jobdiva"); ?></h1>
        <h2>Sync Status</h2>
        <?php summit_jobdiva_render_last_sync_status(); ?>
        <form
            action="<?php echo esc_url(admin_url("admin-post.php")); ?>"
            method="post"
        >
            <input type="hidden" name="action" value="summit_jobdiva_manual_sync" />
            <?php
            wp_nonce_field("summit_jobdiva_manual_sync");
            submit_button(
                __("Sync Jobs Now", "summit-jobdiva"),
                "secondary",
                "submit",
                false,
            );
            ?>
        </form>
        <form action="options.php" method="post">
            <?php
            settings_fields("summit_jobdiva_settings_group");
            do_settings_sections("summit-jobdiva-settings");
            submit_button();?>
        </form>
    </div>
    <?php
}
