# Safe PAWS — Pet Rescue & Adoption

Safe PAWS is a local PHP 8, MySQL, and Python/Flask application for coordinating pet listings, adoption applications, stray reports, shelters, and rescue volunteers. PHP owns authentication, sessions, authorization, and the web interface. The Python API is the authoritative writer for adoption applications and stray reports; both services use the same MySQL database.

## Windows / XAMPP setup

### 1. Install the application

1. Copy this `safe-paws` directory to `C:\xampp\htdocs\safe-paws`.
2. Copy `.env.example` to `.env` in that directory.
3. Set a long, random `SAFE_PAWS_API_TOKEN` in `.env`. For example, generate one with:

   ```powershell
   py -c "import secrets; print(secrets.token_urlsafe(48))"
   ```

4. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` for your local MySQL instance. The example uses `127.0.0.1:3307`; change the port if XAMPP MySQL is listening on `3306`. The database name must remain `safe_paws`. PHP and Python read the same settings from `.env`.
5. In the XAMPP Control Panel, start **Apache** and **MySQL**.
6. Open `http://localhost/phpmyadmin/`, choose **Import**, select `database/safe_paws_complete.sql`, and start the import. The script creates and selects `safe_paws`; it does not drop tables or create accounts with default passwords.

> The complete installer is safe to rerun against a database created from that installer: its tables use `IF NOT EXISTS`, and its sample rows have stable seed keys. Its explicit idempotent migrations add only missing rescue-intake and notification metadata. For an existing installation created with `database/safe_paws.sql`, back up your database and migrate it separately before importing; the older file is a legacy destructive installer and must not be used to reset a database containing data you need.

### 2. Configure PHP

Use PHP 8 or later and enable `pdo_mysql`, `curl`, and `fileinfo` in the `php.ini` used by Apache. Restart Apache after changing PHP extensions. `.env` is denied by the included Apache configuration; keep that protection enabled and do not place secrets in public assets.

### 3. Install and run the Python API

In PowerShell:

```powershell
Set-Location C:\xampp\htdocs\safe-paws\python-api
py -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
python app.py
```

The API listens on `127.0.0.1:5000` by default. Keep this process running while using adoption and stray-report submission. The API requires the same `SAFE_PAWS_API_TOKEN` as PHP and does not create database tables at startup; the SQL import is authoritative. Check `http://127.0.0.1:5000/` for the service health response.

### 4. Open the website

Visit `http://localhost/safe-paws/`.

For local development, the installer creates these six accounts. Their initial password is `password`; the installer stores a PHP password hash, not the plaintext password.

| Name | Email | Assigned role | Initial state |
| --- | --- | --- | --- |
| Allan Arcilla Jr. | `allan@safepaws.local` | Administrator | Active |
| Terrence James Pagulon | `terrence@safepaws.local` | Adopter | Active; separate administrator request is pending |
| Irish Alaon | `irish@safepaws.local` | Adopter | Active |
| Raiza Monte | `raiza@safepaws.local` | Adopter | Active |
| John Mike Asuncion | `john@safepaws.local` | Shelter Manager | Active |
| Mark Jeremy Manuel | `mark@safepaws.local` | Volunteer | Active |

Terrence does **not** receive the Administrator role unless an authorized administrator approves his separate pending request. The shared password is for local development only: do not deploy these credentials to production. Set unique passwords before any production deployment.

The initial 11 gallery records use the supplied images in `assets/images/pets/`. Their names, ages, and genders are illustrative, not verified animal records. The seeded shelter is **Safe Paws Animal Shelter – Cainta City**, with the supplied community description and Cainta, Rizal location. Its street address and phone remain `NULL`; the seeded shelter is approved so these sample dog and cat photos appear in the public gallery.

The uploaded photo sources were checked against the copied assets. The gallery uses this dog/cat mapping:

| Pet | Species | Uploaded image | Website image |
| --- | --- | --- | --- |
| Shadow | Dog | `IMG_1b437a02-7552-4d74-bfc6-7340b1fdf190.jpg` | `assets/images/pets/shadow.jpg` |
| Bruno | Dog | `IMG_0e269909-af46-4a5b-be83-d9acc765688f.jpg` | `assets/images/pets/bruno.jpg` |
| Daisy | Dog | `IMG_a2b3301e-cba0-432b-b31c-a8c030b5f359.jpg` | `assets/images/pets/daisy.jpg` |
| Mochi | Dog | `IMG_694eb60b-2917-4452-9246-d92c5b3b0b6f.png` | `assets/images/pets/mochi.png` |
| Luna | Cat | `caat.jpg` | `assets/images/pets/luna.jpg` |
| Milo | Cat | `cat.jpg` | `assets/images/pets/milo.jpg` |
| Ginger | Cat | `672798137_1844344736260967_3714280890369788179_n.jpg` | `assets/images/pets/ginger.jpg` |
| Snowy | Dog | `844460470_2191706608409324_7862792686606853966_n.jpg` | `assets/images/pets/snowy.jpg` |
| Rocky | Dog | `af661d96-8e6f-42e8-8590-1c3e1aa7c27d.jpg` | `assets/images/pets/rocky.jpg` |
| Cotton | Cat | `catto.jpg` | `assets/images/pets/cotton.jpg` |
| Peanut | Dog | `IMG_20261018_201301.jpg` | `assets/images/pets/peanut.jpg` |

That is seven dogs and four cats. Each pet's database `image` value matches its local primary image in `pet_images`; the gallery reads those database records and uses the placeholder image if a photo cannot load.

### Database connection configuration

The existing application reads these values from `.env` through `config/database.php`. The committed `.env.example` is a template, not a production configuration:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=safe_paws
DB_USER=root
DB_PASS=
PYTHON_API_BASE_URL=http://127.0.0.1:5000
SAFE_PAWS_API_TOKEN=replace-with-a-long-random-secret
```

Use the actual local MySQL port and credentials for your XAMPP installation. Do not use an empty database password or the local root account in production.

### Table relationships

- `users` links to `user_roles` and `roles`; the legacy `users.role` field remains in place for current PHP/Python compatibility. Registration, administrator approval, and admin role edits synchronize the normalized mapping.
- `admin_requests.user_id` identifies the requester; `reviewed_by` records the reviewing administrator. Approval updates account status and assigns the administrator role in one transaction.
- `shelters` may have an optional manager. `pets.shelter_id` links each pet to a shelter; `pet_images.pet_id` supports multiple images and primary-image metadata.
- `adoption_applications` links an adopter and pet. `application_status_history` records initial submission, decisions, and competing-application rejections.
- `stray_reports.reporter_id` is optional to allow anonymous reports. `report_status_history` records submission and staff or volunteer status changes. When a rescue is completed, `pets.rescue_report_id` links its single shelter intake to the original report; the report photo and rescue details remain available to shelter staff.
- `rescue_assignments` and `volunteers` connect staff activity to the corresponding user, report, or shelter. `notifications` are recipient-scoped and store read state, related-record metadata, and unique event keys to prevent duplicate event notices. `support_records` retain user requests and their staff review status.
- `activity_logs` is the application's audit trail; the `audit_logs` view exposes that history without duplicating it. `adoption_api_logs` and `stray_api_logs` record API request outcomes.

### Compatibility notes

- The installer uses `safe_paws`, matching `config/database.php`, `.env.example`, and the Python API configuration. It does not use the unrelated `animal_shelter_db` name because changing it would disconnect the existing application.
- It preserves the PHP/Python column names already in use (`users.id`, `name`, `password`, `role`; `pets.id`, `sex`, `age`, `status`, `image`) and adds normalized role and status-history tables around them.
- Administrator requests stay unassigned in `user_roles` until approval. PHP authorization still checks the existing `users.role` and active account status; the registration, approval, and administrator role-edit workflows keep `user_roles` synchronized.
- The existing website uses PHP pages for user, role, pet, shelter, and dashboard operations. The Python API currently provides the adoption-submission and stray-report endpoints; the installer does not claim separate REST endpoints for every PHP page.
- The complete installer is intended for new installs and safe reruns. It adds the idempotent `pets.rescue_report_id` relationship to an existing complete-installer database without replacing existing pet records. It is not an automatic migration from the older legacy schema.

### Database verification

After import, run these in phpMyAdmin's SQL tab:

```sql
USE safe_paws;
SHOW TABLES;

SELECT role_name FROM roles ORDER BY role_name;

SELECT name, role, status, email IS NULL AS credentials_not_set
FROM users
WHERE seed_key LIKE 'initial-person-%'
ORDER BY name;

SELECT name, species, sex, age, status, image
FROM pets
WHERE seed_key LIKE 'initial-gallery-%'
ORDER BY id;

SELECT COUNT(*) AS primary_gallery_images
FROM pet_images pi
JOIN pets p ON p.id = pi.pet_id
WHERE p.seed_key LIKE 'initial-gallery-%' AND pi.is_primary = 1;

SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'safe_paws' AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;
```

Expected seed checks: five role definitions, six named account records, one pending administrator request, 11 sample pets, and 11 primary gallery images. The installer also runs verification selects at the end of its import.

## Implemented workflows

- Public home page, searchable pet listings, pet profiles, and shelter directory.
- Database-backed gallery seeded with the 11 supplied local pet photos, descriptive primary-image metadata, and a fallback for missing images.
- Shared light, dark, and system themes; responsive administrator navigation; reduced-motion-aware page reveals and image previews.
- Adopter registration and login, password hashing, session ID rotation, CSRF-protected forms, and logout.
- Public administrator access requests with purpose, password hashing, five-failure cooldown, pending-only login prevention, and admin-only approval or rejection. Authorized administrators can also create approved admin accounts directly.
- Normalized role definitions and role assignments synchronized with the legacy role field used by existing authorization checks.
- Adoption requests submitted to the authenticated Python API, duplicate and eligibility checks, per-pet locking, application tracking, and transactional shelter/admin review with consistent pet status updates.
- Adoption and stray-report status history, with status changes recorded alongside application and rescue workflows.
- Stray reports with validated image uploads, Python API persistence, tracking references, staff status and note updates, and volunteer assignment/progress.
- Admin pet and shelter status management, user/role management, volunteer coordination, in-app notifications, activity logs, and live dashboard counts.
- Local configuration through `.env`; the PHP app returns an error instead of pretending a failed Python API request was saved.

## Testing checklist

Run the Python API tests from the `python-api` directory:

```powershell
python -B -m unittest discover -s tests -v
```

1. Browse `pets.php`, search by name/breed/city, and open an available pet profile.
2. Register an adopter, sign in, and sign out; try a duplicate email and mismatched passwords.
3. Submit invalid registration data five times, then confirm the cooldown message appears.
4. Register an administrator request; confirm its password is hashed and the account cannot sign in until approved from **Admin Registration Requests**. Exercise approval and rejection.
5. As an administrator, create a direct admin account and verify duplicate emails are rejected.
6. Submit an adoption request with the Python API running; confirm it appears in the adopter tracker and admin/shelter review.
7. Approve one application and confirm the pet becomes adopted and competing applications are rejected; reject an application and confirm availability stays pending if another application remains.
8. Submit a stray report with and without a valid image; check the returned reference and admin report list.
9. Stop the Python API and submit a report/application; confirm the web page shows a service error and does not claim success.
10. Assign a report to a volunteer, update rescue progress, and confirm the staff status and notifications.
11. Open admin pages while signed out or signed in as a non-admin; protected routes should deny access.
12. Check navigation and forms at mobile viewport widths.

Automated checks completed for the gallery update: SHA-256 checks confirm all 11 copied files match their uploaded originals; the SQL installer imports twice into MariaDB 10.4.32 with seven dogs, four cats, 11 publicly available sample listings, matching primary image paths, and no duplicate seeds. The local pet gallery and dog/cat image URLs return HTTP 200, and the six Python API tests pass. Changed PHP files pass `php -l`, and `app.js` passes `node --check`.

## Known limitations

- Notifications are stored in the application and shown to signed-in users; outbound email/SMS is not configured.
- Report status tracking is available for reports submitted while signed in. Anonymous reports receive a reference at submission but do not have a public status lookup.
- Gallery names, ages, genders, and health notes are sample values from the request and must be verified by shelter staff; the photos are local assets and do not represent confirmed rescue outcomes.
- `safe_paws_complete.sql` creates the full schema but does not migrate an older installation automatically. `safe_paws.sql` remains only as a legacy installer; do not import it over data you want to keep.
- PHP pages provide the current user, role, pet, and dashboard workflows; the Python API currently handles adoption submissions and stray reports rather than exposing a separate REST endpoint for every PHP page.
- The import was validated on MariaDB 10.4.32, not MySQL 8.0. Browser-based end-to-end checks against Apache and the running Python service remain a manual setup step.
