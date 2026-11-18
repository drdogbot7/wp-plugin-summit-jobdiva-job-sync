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
                "federal_jobs" => false,
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
        "summit_jobdiva_federal_jobs",
        __("Federal Jobs", "summit-jobdiva"),
        "summit_jobdiva_render_federal_jobs_field",
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

    // An empty password field means keep the saved credential, not clear it.
    return [
        "client_id" => $client_id,
        "username" => $username,
        "password" =>
            $password !== "" ? $password : $current_settings["password"] ?? "",
        "federal_jobs" =>
            isset($input["federal_jobs"]) && $input["federal_jobs"] === "1",
    ];
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

function summit_jobdiva_render_federal_jobs_field()
{
    $settings = get_option("summit_jobdiva_settings", []);
    $federal_jobs = is_array($settings) && !empty($settings["federal_jobs"]);
    ?>
    <label for="summit_jobdiva_federal_jobs">
        <input
            type="checkbox"
            id="summit_jobdiva_federal_jobs"
            name="summit_jobdiva_settings[federal_jobs]"
            value="1"
            <?php checked($federal_jobs); ?>
        />
        <?php esc_html_e("Federal Jobs Only", "summit-jobdiva"); ?>
    </label>
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
