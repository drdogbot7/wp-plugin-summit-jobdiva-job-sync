<?php

function create_jobs_cpt()
{
    register_post_type("job", [
        "labels" => [
            "name" => "Jobs",
            "singular_name" => "Job",
            "add_new" => "Add New",
            "add_new_item" => "Add New Job",
            "edit_item" => "Edit Job",
            "new_item" => "New Job",
            "all_items" => "All Jobs",
            "view_item" => "View Job",
            "search_items" => "Search Jobs",
            "not_found" => "No Job found",
            "not_found_in_trash" => "No Job found in Trash",
            "parent_item_colon" => "",
            "menu_name" => "Job",
        ],
        "public" => true,
        "hierarchical" => true,
        "has_archive" => true,
        "rewrite" => ["slug" => "open-positions/job"],
        "query_var" => true,
        "supports" => ["title", "editor", "excerpt", "thumbnail"],
    ]);
}

add_action("init", "create_jobs_cpt");
