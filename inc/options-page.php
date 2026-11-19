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

    if (array_key_exists("federal_jobs", $settings)) {
        return !empty($settings["federal_jobs"]) ? "Federal" : "Commercial";
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

function summit_jobdiva_render_options_page()
{
    if (!current_user_can("manage_options")) {
        return;
    } ?>
    <div class="wrap">
        <h1><?php esc_html_e("JobDiva Settings", "summit-jobdiva"); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields("summit_jobdiva_settings_group");
            do_settings_sections("summit-jobdiva-settings");
            submit_button();?>
        </form>
    </div>
    <?php
}
