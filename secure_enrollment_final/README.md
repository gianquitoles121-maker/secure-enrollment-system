# Secure Enrollment — Online Enrollment System

## Project overview
**Secure Enrollment** is a web-based enrollment-system prototype built for an academic project. It provides separate student and administrator areas for enrollment tasks and student support.

**Live site:** https://enrollmentsystem.infinityfreeapp.com/

## Business name and description
**Business name:** Secure Enrollment (academic prototype)

The system is intended for a school or small educational institution that needs a central online location for student registration, enrollment applications, enrollment-status checking, and support messages. Students use the student area; administrators review enrollment information and manage selected academic records and support requests.

This is a school project prototype, not an officially certified school information system.

## Problem being solved
Enrollment can involve paper forms, repeated inquiries, scattered messages, and uncertainty about application status. Secure Enrollment aims to organize these tasks by offering online registration, enrollment forms, status information, payment-proof submission, and student support in one web application.

## Feature list
Features represented in the source code include:
- Landing page.
- Student registration and login/logout.
- Separate student and administrator areas with role checks.
- Student profile viewing/editing.
- Enrollment application and status viewing.
- Course and subject information.
- Administrator management of subjects and school years (CRUD-related operations where implemented).
- Enrollment review/history and student details.
- Payment-proof upload and payment status workflow.
- Notifications.
- Student support tickets and administrator replies/status updates.
- Student password change and student password-reset request workflow.
- CSRF helper functions and login-attempt/lockout fields in the database.

**Not listed as a supported feature:** admin self-service “Forgot Password.” Use a separate authorized admin password reset procedure if the admin account needs recovery.

Actual behavior should be demonstrated only after testing the current version.

## Technology stack
- **PHP** — server-side logic, sessions, form handling, and database access.
- **MySQL/MariaDB** — data storage.
- **HTML5** — page structure and forms.
- **CSS3** — styling and responsive layouts.
- **JavaScript** — client-side interface behavior.
- **XAMPP** — local Apache/PHP/MySQL environment.
- **Visual Studio Code** — source-code editor.
- **phpMyAdmin** — database import/export and administration.
- **InfinityFree** — online PHP/MySQL hosting.
- **ChatGPT (OpenAI)** — help with explanations, troubleshooting, code drafting/refinement, and README preparation.

Only describe Brevo SMTP/email as an integrated feature if you have personally verified that emails send successfully in the current setup.

## Project structure
- `index.php` — landing page.
- `login.php`, `logout.php` — login/logout.
- `config/database.php` — database connection settings; configure it for your own environment.
- `config/csrf.php` — CSRF helper functions.
- `admin/` — administrator dashboard, enrollment history, school years, subjects, student details, password-reset-request review, and support-ticket management.
- `student/` — student registration, dashboard, profile, enrollment, enrolled subjects, status, payment-proof handling, password change, and support.
- `uploads/` — upload-related protection/handling files.
- `database/enrollment_system.sql` — sanitized SQL schema plus minimal demo data. Original student rows, activity logs, support messages, and payment-proof references were removed for privacy.

The PHP source files in the supplied project ZIP were checked for PHP syntax using `php -l` in the packaging environment. This does not guarantee that every feature works against every database or hosting configuration.

## Setup and run locally with XAMPP
1. Install XAMPP and start **Apache** and **MySQL**.
2. Copy/extract the project folder into `C:\xampp\htdocs\enrollment_system`.
3. Open `http://localhost/phpmyadmin`.
4. Create a database named `enrollment_system` using `utf8mb4` if prompted.
5. Select the new database and use **Import** to import `database/enrollment_system.sql`.
6. Open `config/database.php`. For a typical local XAMPP setup, use host `localhost`, username `root`, an empty password, and database `enrollment_system`. Adjust these values if your local configuration differs.
7. Open `http://localhost/enrollment_system/`.
8. Sign in using one of the demo accounts below, then test the main workflows.

### Clean-machine verification checklist
These are setup instructions for a fresh XAMPP installation. Before claiming a clean-machine test is complete, actually perform the steps on a clean environment and record any issues. The packaging process only performed PHP syntax checks; it did not execute every page against a fresh database.

## Setup on InfinityFree
1. Create a MySQL database through the InfinityFree control panel.
2. Import `database/enrollment_system.sql` into that database through phpMyAdmin only if you intend to create a fresh demo database. **Do not import this sanitized demo dump over the live database**, because it contains only demo rows and does not preserve existing live records.
3. Upload the project files to the website's `htdocs` directory.
4. Configure the online database connection using the exact host, username, database name, and password provided by the hosting panel. Do not publish those credentials in GitHub.
5. Test login, registration, enrollment, payment-proof handling, support, and role restrictions after deployment.
6. Do not leave detailed PHP error display enabled on a public website.

## Database tables
The schema contains:
- `users`
- `students`
- `courses`
- `school_years`
- `enrollments`
- `subjects`
- `notifications`
- `activity_logs`
- `support_tickets`
- `support_messages`
- `password_reset_requests`

The included SQL is sanitized for source-code sharing. It contains only demo accounts and sample catalog data, not the original database records.

## Test accounts
The following credentials are seeded in `database/enrollment_system.sql` for local evaluation. They are **demo credentials only** and must not be reused for personal or production accounts.

| Role | Username | Password |
|---|---|---|
| Administrator | `admin_demo` | `AdminDemo123!` |
| Student | `student_demo` | `StudentDemo123!` |

The demo password hashes were generated with PHP's `password_hash()` and are intended for testing this sanitized local database. Verify both logins after importing the SQL. Do not use these accounts on the public/live deployment.

## AI tools used
**ChatGPT (OpenAI)** was used for selected explanations, troubleshooting guidance, code drafting/refinement, and documentation preparation. The project developer is responsible for reviewing the code and testing the final implementation. Do not claim AI independently tested the full application.

## Testing checklist
- [ ] Landing page loads.
- [ ] Student registration works.
- [ ] Valid login works and invalid credentials are rejected.
- [ ] Student accounts cannot open administrator-only pages.
- [ ] Administrator pages load.
- [ ] Enrollment records are saved and status displays correctly.
- [ ] Payment-proof workflow works.
- [ ] Student support ticket/message can be created.
- [ ] Administrator can reply to a ticket and update status.
- [ ] Typing a support reply does not cause the page to auto-refresh.
- [ ] Logout ends the session.
- [ ] No PHP errors or HTTP 500 pages occur during normal use.

## Security and privacy notes
- Never publish database passwords, SMTP passwords, API keys, or other secrets in the repository.
- Do not upload the raw live database dump or real payment-proof images. The included SQL has been sanitized for sharing.
- Rotate any credentials that have previously been shared in chats or screenshots.
- Do not use the demo accounts on the public deployment.
- Review and test access controls and upload restrictions before using real student data.

## Final submission checklist
1. Complete source code.
2. `README.md` with the business description, problem, features, technology stack, setup instructions, AI tools, and test accounts.
3. Sanitized SQL file at `database/enrollment_system.sql`.
4. A GitHub repository link containing the files above. Submit the repository link, as requested by the instructor.
