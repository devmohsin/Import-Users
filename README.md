# Import Users

WordPress admin plugin: bulk-import students from CSV, provision their WordPress accounts, enroll them into Tutor LMS courses (including bundle child courses), and send welcome/set-password emails.

Adds **Tools → Import Users** with four sections, used in order:

1. **Download Demo CSV** — sample file with the expected columns (First name, Last name, Email, Course IDs, Enrollment date).
2. **Import CSV** — uploads and validates rows into a custom table (`{prefix}iu_import_records`). Invalid rows and exact duplicates (same email + course IDs) are skipped, not silently dropped — see the summary message and the Recent Records table.
3. **Process Database** — for each pending record: finds or creates the WordPress user, enrolls them in every course ID (expanding any bundle into its child courses), verifies the enrollment, and only then marks the record `processed`.
4. **Send Welcome Emails** — sends the set-password email to records where `processed = 1` and `messaged = 0`.

Also adds a **"Send set-password email"** row action on `wp-admin/users.php` for any user, and an **"Import Users — Enrollment Log"** section on the edit-user screen showing what course access was granted from a spreadsheet import and why.

## Dependencies

Hard dependency on **Tutor LMS** (`tutor/tutor.php`) and **CL Tutor Courses** (`cl-tutor-courses/cl-tutor-courses.php`). The plugin self-deactivates with an admin notice if either is missing.

This plugin deliberately reuses existing logic from those two plugins rather than reimplementing it:

- Enrollment: `CodeLinden\UserCourseAccessAdmin\Enrollment_Handler::grant_enrollment()`
- Bundle detection/children: `CodeLinden\TutorCourseBundle\Bundle_Utils`
- Set-password email/link: `CodeLinden\TutorCourses\Guest_Checkout::send_welcome_email()` + `Guest_Password_Setup`

## Notes on behavior that isn't obvious from the code

- **Student role**: neither Tutor LMS nor CL Tutor Courses register a dedicated "student" role. Tutor LMS marks students purely via the `_is_tutor_student` user meta flag (set automatically as a side effect of enrollment). New users created by this plugin get the site's normal default new-user role (`get_option('default_role')`, filterable via `iu_student_role`) — the same as every other real student account on the site.
- **Bundle children**: resolved directly from `Bundle_Utils::get_bundled_course_ids()` rather than relying solely on CL Tutor's `tutor_after_enrolled` hook chain. That hook resolves option-tiered bundles' children via WooCommerce order line items, which don't exist for an admin/CSV-granted enrollment — it would silently grant nothing for bundles configured with purchase options. Reading the flat meta directly works correctly for both simple and option-based bundles.

## Testing

`tests/run.php` is a single-file integration test (same pattern as `cl-tutor-courses/tests/run.php`) that boots real WordPress and exercises the actual Tutor LMS / CL Tutor Courses classes against a live database, self-cleaning its fixtures on every run.

```
php wp-content/plugins/import-users/tests/run.php
```

or, as a logged-in administrator, visit `/wp-content/plugins/import-users/tests/run.php` in the browser.
