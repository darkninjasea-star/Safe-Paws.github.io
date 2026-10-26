<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function base_url(string $path = ''): string
{
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (preg_match('#/(admin|shelter|volunteer|user|adoption)$#', $base)) {
        $base = str_replace('\\', '/', dirname($base));
    }
    $base = rtrim($base, '/.');
    return ($base === '/' ? '' : $base) . '/' . ltrim($path, '/');
}

function notification_url(?string $path): string
{
    if ($path === null || preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$#D', $path) !== 1) {
        return base_url('user/notifications.php');
    }
    return base_url($path);
}

function redirect(string $path): void
{
    header('Location: ' . base_url($path));
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
        return null;
    }
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('Invalid form token. Please go back and try again.');
    }
}

function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function validate_shelter_profile(array $input): array
{
    $profile = [
        'name' => trim((string) ($input['name'] ?? '')),
        'description' => trim((string) ($input['description'] ?? '')),
        'address' => trim((string) ($input['address'] ?? '')),
        'city' => trim((string) ($input['city'] ?? '')),
        'email' => trim((string) ($input['email'] ?? '')),
        'phone' => trim((string) ($input['phone'] ?? '')),
    ];
    if (strlen($profile['name']) < 2 || strlen($profile['name']) > 160) {
        throw new InvalidArgumentException('Shelter name must be between 2 and 160 characters.');
    }
    if (strlen($profile['description']) > 5000) {
        throw new InvalidArgumentException('Description must be 5,000 characters or fewer.');
    }
    if (strlen($profile['address']) < 2 || strlen($profile['address']) > 255) {
        throw new InvalidArgumentException('Address must be between 2 and 255 characters.');
    }
    if (strlen($profile['city']) < 2 || strlen($profile['city']) > 100) {
        throw new InvalidArgumentException('City must be between 2 and 100 characters.');
    }
    if (strlen($profile['email']) > 190
        || ($profile['email'] !== '' && !filter_var($profile['email'], FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('Enter a valid contact email address.');
    }
    if (strlen($profile['phone']) > 30
        || ($profile['phone'] !== '' && (!preg_match('/^[0-9+().\\-\\s]+$/', $profile['phone'])
            || strlen((string) preg_replace('/\\D/', '', $profile['phone'])) < 7))) {
        throw new InvalidArgumentException('Enter a valid phone number with at least 7 digits.');
    }

    return $profile;
}

function notify(
    int $userId,
    string $title,
    string $message,
    ?string $link = null,
    ?string $relatedType = null,
    ?int $relatedId = null,
    ?string $eventKey = null
): void {
    if ($userId < 1 || $title === '' || strlen($title) > 180 || $message === ''
        || ($relatedType !== null && (strlen($relatedType) > 50 || !preg_match('/^[a-z_]+$/', $relatedType)))
        || ($relatedId !== null && $relatedId < 1)
        || ($eventKey !== null && ($eventKey === '' || strlen($eventKey) > 190))) {
        throw new InvalidArgumentException('Notification details are invalid.');
    }

    $sql = 'INSERT INTO notifications (user_id,title,message,link,related_type,related_id,event_key)
            VALUES (?,?,?,?,?,?,?)';
    if ($eventKey !== null) {
        $sql .= ' ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)';
    }
    db()->prepare($sql)->execute([
        $userId,
        $title,
        $message,
        $link,
        $relatedType,
        $relatedId,
        $eventKey,
    ]);
}

function notify_admins(
    string $eventKey,
    string $title,
    string $message,
    string $link,
    string $relatedType,
    int $relatedId
): void {
    $admins = db()->query('SELECT id FROM users WHERE role="admin" AND status="active"')->fetchAll();
    foreach ($admins as $admin) {
        notify(
            (int) $admin['id'],
            $title,
            $message,
            $link,
            $relatedType,
            $relatedId,
            $eventKey
        );
    }
}

function unread_notification_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function recent_notifications(int $userId, int $limit = 5): array
{
    $limit = max(1, min($limit, 10));
    $stmt = db()->prepare(
        'SELECT id,title,message,link,is_read,created_at
         FROM notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT ' . $limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function record_rescue_intake(PDO $pdo, int $reportId): int
{
    $stmt = $pdo->prepare(
        'SELECT r.id,r.animal_type,r.description,r.image,a.volunteer_id,a.shelter_id
         FROM stray_reports r
         JOIN rescue_assignments a ON a.report_id=r.id
         WHERE r.id=? FOR UPDATE'
    );
    $stmt->execute([$reportId]);
    $rescue = $stmt->fetch();
    if (!$rescue || !$rescue['shelter_id']) {
        throw new RuntimeException('Assign an active volunteer and approved destination shelter before completing this rescue.');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id=? AND role="volunteer" AND status="active" FOR UPDATE');
    $stmt->execute([$rescue['volunteer_id']]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('The assigned volunteer is no longer active. Choose an active volunteer and try again.');
    }

    $stmt = $pdo->prepare('SELECT id FROM shelters WHERE id=? AND status="approved" FOR UPDATE');
    $stmt->execute([$rescue['shelter_id']]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('The destination shelter is no longer approved. Choose an approved shelter and try again.');
    }

    $species = match (strtolower(trim((string) $rescue['animal_type']))) {
        'dog' => 'dog',
        'cat' => 'cat',
        'rabbit' => 'rabbit',
        default => 'other',
    };
    $stmt = $pdo->prepare(
        'INSERT INTO pets (rescue_report_id,shelter_id,name,species,description,image,status)
         VALUES (?,?,?,?,?,?,"unavailable")
         ON DUPLICATE KEY UPDATE shelter_id=VALUES(shelter_id)'
    );
    $stmt->execute([
        $reportId,
        $rescue['shelter_id'],
        'Rescue report #' . $reportId,
        $species,
        $rescue['description'],
        $rescue['image'],
    ]);

    $stmt = $pdo->prepare('SELECT id FROM pets WHERE rescue_report_id=?');
    $stmt->execute([$reportId]);
    $petId = $stmt->fetchColumn();
    if ($petId === false) {
        throw new RuntimeException('The shelter intake record could not be created.');
    }
    return (int) $petId;
}

function sync_user_role_assignment(PDO $pdo, int $userId, string $role, ?int $assignedBy = null): void
{
    $roleName = match ($role) {
        'admin' => 'administrator',
        'shelter' => 'shelter_manager',
        'volunteer' => 'volunteer',
        'user' => 'adopter',
        default => throw new InvalidArgumentException('Choose a valid user role.'),
    };
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name=?');
    $stmt->execute([$roleName]);
    $roleId = $stmt->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('The role definitions are missing. Import the current Safe PAWS database schema.');
    }

    $pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
    $pdo->prepare('INSERT INTO user_roles (user_id,role_id,assigned_by) VALUES (?,?,?)')
        ->execute([$userId, (int) $roleId, $assignedBy]);
}

function activity_log(?int $userId, string $action, string $entityType, ?int $entityId = null, ?array $details = null): int
{
    $stmt = db()->prepare(
        'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    return (int) db()->lastInsertId();
}

function registration_attempt_key(string $email): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', strtolower(trim($email)) . '|' . $ip);
}

function registration_lock_seconds(string $attemptKey): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(GREATEST(0,TIMESTAMPDIFF(SECOND,CURRENT_TIMESTAMP,blocked_until)),0)
         FROM registration_attempts WHERE attempt_key=?'
    );
    $stmt->execute([$attemptKey]);
    return max(0, (int) $stmt->fetchColumn());
}

function record_registration_failure(string $attemptKey): void
{
    db()->prepare(
        'INSERT INTO registration_attempts (attempt_key,attempt_count,last_attempt,blocked_until)
         VALUES (?,1,CURRENT_TIMESTAMP,NULL)
         ON DUPLICATE KEY UPDATE
           blocked_until=IF(last_attempt < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), NULL,
             IF(attempt_count + 1 >= 5, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), blocked_until)),
           attempt_count=IF(last_attempt < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), 1, attempt_count + 1),
           last_attempt=CURRENT_TIMESTAMP'
    )->execute([$attemptKey]);
}

function clear_registration_attempts(string $attemptKey): void
{
    db()->prepare('DELETE FROM registration_attempts WHERE attempt_key=?')->execute([$attemptKey]);
}

function review_adoption_application(
    int $applicationId,
    int $actorId,
    string $status,
    string $notes,
    ?int $shelterId = null
): void {
    if (!in_array($status, ['under_review', 'approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Choose a valid application status.');
    }
    if (strlen($notes) > 5000) {
        throw new InvalidArgumentException('The note must be 5,000 characters or fewer.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sql = 'SELECT a.id,a.applicant_id,a.status AS application_status,a.pet_id,p.name AS pet_name,p.status AS pet_status,s.user_id AS shelter_user_id
                FROM adoption_applications a
                JOIN pets p ON p.id=a.pet_id
                JOIN shelters s ON s.id=p.shelter_id
                WHERE a.id=?';
        $stmt = $pdo->prepare($sql . ' FOR UPDATE');
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();
        if (!$application || ($shelterId !== null && (int) $application['shelter_user_id'] !== $shelterId)) {
            throw new RuntimeException('This application is no longer available to review.');
        }
        if (!in_array($application['application_status'], ['submitted', 'under_review'], true)) {
            throw new RuntimeException('This application has already received a final decision.');
        }
        if ($status === 'approved' && !in_array($application['pet_status'], ['available', 'pending'], true)) {
            throw new RuntimeException('This pet is no longer eligible for adoption.');
        }

        $pdo->prepare(
            'UPDATE adoption_applications
             SET status=?,shelter_notes=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?'
        )->execute([$status, $notes !== '' ? $notes : null, $actorId, $applicationId]);
        $statusChanged = $application['application_status'] !== $status;
        $historyId = null;
        if ($statusChanged) {
            $pdo->prepare(
                'INSERT INTO application_status_history (application_id,previous_status,new_status,actor_id,notes)
                 VALUES (?,?,?,?,?)'
            )->execute([$applicationId, $application['application_status'], $status, $actorId, $notes ?: null]);
            $historyId = (int) $pdo->lastInsertId();
        }

        if ($status === 'approved') {
            $otherApplications = $pdo->prepare(
                'SELECT id,applicant_id,status FROM adoption_applications
                 WHERE pet_id=? AND id<>? AND status IN ("submitted","under_review") FOR UPDATE'
            );
            $otherApplications->execute([$application['pet_id'], $applicationId]);
            foreach ($otherApplications->fetchAll() as $other) {
                $pdo->prepare(
                    'UPDATE adoption_applications SET status="rejected",shelter_notes=? WHERE id=?'
                )->execute(['The pet has been matched with another applicant.', $other['id']]);
                $pdo->prepare(
                    'INSERT INTO application_status_history (application_id,previous_status,new_status,actor_id,notes)
                     VALUES (?,?,?,?,?)'
                )->execute([
                    $other['id'],
                    $other['status'],
                    'rejected',
                    $actorId,
                    'The pet has been matched with another applicant.',
                ]);
                $otherHistoryId = (int) $pdo->lastInsertId();
                notify((int) $other['applicant_id'], 'Adoption application update',
                    'The pet has been matched with another applicant.', 'application_tracker.php',
                    'adoption_application', (int) $other['id'], 'adoption_application:status:' . $otherHistoryId);
            }
            $pdo->prepare('UPDATE pets SET status="adopted" WHERE id=?')
                ->execute([$application['pet_id']]);
        } elseif ($status === 'rejected' && $application['pet_status'] === 'pending') {
            $remaining = $pdo->prepare(
                'SELECT COUNT(*) FROM adoption_applications
                 WHERE pet_id=? AND id<>? AND status IN ("submitted","under_review")'
            );
            $remaining->execute([$application['pet_id'], $applicationId]);
            $petStatus = (int) $remaining->fetchColumn() > 0 ? 'pending' : 'available';
            $pdo->prepare('UPDATE pets SET status=? WHERE id=?')
                ->execute([$petStatus, $application['pet_id']]);
        }

        $message = 'Your application for ' . $application['pet_name'] . ' is now ' . str_replace('_', ' ', $status) . '.';
        if ($notes !== '') {
            $message .= ' Note: ' . $notes;
        }
        if ($statusChanged) {
            notify(
                (int) $application['applicant_id'],
                'Adoption application updated',
                $message,
                'application_tracker.php',
                'adoption_application',
                $applicationId,
                'adoption_application:status:' . $historyId
            );
        }
        activity_log($actorId, 'reviewed', 'adoption_application', $applicationId, ['status' => $status]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function upload_image(string $field, string $folder): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Select a JPG, PNG, or WebP image no larger than 5 MB.');
    }
    if (!is_uploaded_file($_FILES[$field]['tmp_name'])) {
        throw new RuntimeException('The uploaded image could not be validated.');
    }
    $size = filesize($_FILES[$field]['tmp_name']);
    if ($size === false || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be no larger than 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $imageInfo = @getimagesize($_FILES[$field]['tmp_name']);
    $imageTypes = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];
    if (!isset($allowed[$mime]) || !$imageInfo || ($imageTypes[$mime] ?? null) !== $imageInfo[2]) {
        throw new RuntimeException('Only JPG, JPEG, PNG or WEBP images are accepted.');
    }
    $root = dirname(__DIR__) . '/uploads/' . trim($folder, '/');
    if (!is_dir($root)) {
        if (!mkdir($root, 0755, true) && !is_dir($root)) {
            throw new RuntimeException('Could not prepare the upload folder.');
        }
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $root . '/' . $name)) {
        throw new RuntimeException('Could not save the image.');
    }
    return 'uploads/' . trim($folder, '/') . '/' . $name;
}

function image_url(?string $path): string
{
    return $path ? base_url($path) : base_url('assets/images/paw-placeholder.svg');
}

function remove_uploaded_image(?string $path): void
{
    if (!$path || !str_starts_with($path, 'uploads/')) {
        return;
    }
    $uploadsRoot = realpath(dirname(__DIR__) . '/uploads');
    $file = realpath(dirname(__DIR__) . '/' . $path);
    if ($uploadsRoot && $file && str_starts_with($file, $uploadsRoot . DIRECTORY_SEPARATOR) && is_file($file)) {
        unlink($file);
    }
}

function remove_unreferenced_pet_image(PDO $pdo, ?string $path): void
{
    if (!$path || !str_starts_with($path, 'uploads/')) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT
               (SELECT COUNT(*) FROM pets WHERE image=?)
               + (SELECT COUNT(*) FROM stray_reports WHERE image=?)'
        );
        $stmt->execute([$path, $path]);
    } catch (PDOException $exception) {
        error_log('Safe PAWS pet image cleanup check failed: ' . $exception->getMessage());
        return;
    }
    if ((int) $stmt->fetchColumn() === 0) {
        remove_uploaded_image($path);
    }
}

function remove_unreferenced_shelter_image(PDO $pdo, ?string $path): void
{
    if (!$path || !str_starts_with($path, 'uploads/')) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM shelters WHERE image=?');
        $stmt->execute([$path]);
        if ((int) $stmt->fetchColumn() === 0) {
            remove_uploaded_image($path);
        }
    } catch (PDOException $exception) {
        error_log('Safe PAWS shelter image cleanup check failed: ' . $exception->getMessage());
    }
}

function dashboard_url(array $user): string
{
    return match ($user['role']) {
        'admin' => 'admin/index.php',
        'shelter' => 'shelter/index.php',
        'volunteer' => 'volunteer/index.php',
        default => 'user/index.php',
    };
}
