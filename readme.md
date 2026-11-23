# Summit JobDiva Integrations

This plugin syncs open jobs from JobDiva to Wordpress using JobDiva's API. Created for Summit Human Capital.

## Setup

1. In WordPress, go to **Settings → JobDiva**.
2. Enter the JobDiva client ID, username, and password.
3. Choose which jobs to import: **Federal** or **Commercial**. Jobs whose division name is exactly `Federal` are treated as Federal; all others are treated as Commercial.
4. Optionally enter an email address for sync failure notifications. If left blank, notifications go to the WordPress administrator email.
5. Optionally enter title keywords or phrases to exclude, one per line. Matching is case-insensitive. The field is blank by default, so no titles are excluded unless terms are added.
6. Save the settings.

## Running a sync

Use **Sync Jobs Now** on the JobDiva settings page to run an import immediately. The plugin also schedules a daily import using WordPress Cron.

The settings page displays the last sync time and its outcome. Successful syncs show the number of jobs added, trashed, and excluded. Failed syncs show the error and any counts recorded before the failure.

## Import behavior

- Jobs are matched by their JobDiva ID. Existing WordPress posts are updated in place, preserving their IDs and permalinks.
- New jobs are published as WordPress job posts.
- Jobs that are no longer returned for the selected division, including jobs excluded by title keywords, are moved to the trash.
- If JobDiva returns a valid but completely empty open-jobs list, the sync reports an error and leaves existing job posts unchanged.
- Job titles, descriptions, issue dates, locations, reference numbers, and work-location type are mapped from JobDiva's open-job list and job-detail data.
- The saved access token is reused until JobDiva rejects it with HTTP 401. The plugin then refreshes the token and retries the request once.

## Sync scheduling

The daily schedule uses WordPress Cron.

## License

This plugin is distributed under the GNU General Public License version 3. See [LICENSE](./LICENSE).
