<?php
require __DIR__ . '/../app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Secret');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    exit;
}
header('Access-Control-Allow-Origin: *');

function route_match(string $pattern, string $uri): ?array {
    $regex = '#^' . preg_replace('#:([a-zA-Z_]+)#', '(?P<$1>[^/]+)', $pattern) . '$#';
    if (!preg_match($regex, $uri, $matches)) return null;
    return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
}

function safe_json_decode(?string $json): ?array {
    if (!$json) return null;
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function require_admin(): array {
    $user = auth_user();
    if (!is_admin($user)) json_response(['error' => 'forbidden'], 403);
    return $user;
}

function find_device(string $deviceId): ?array {
    $st = db()->prepare('SELECT * FROM devices WHERE device_id = ? LIMIT 1');
    $st->execute([$deviceId]);
    $d = $st->fetch();
    return $d ?: null;
}

function can_access_device(array $user, array $device): bool {
    if (is_admin($user)) return true;
    return !empty($device['owner_user_id']) && (string) $device['owner_user_id'] === (string) ($user['id'] ?? '');
}

function device_is_online(array $device, int $thresholdSeconds = 300): bool {
    $lastSeen = $device['last_seen'] ?? null;
    if (!$lastSeen) return false;
    $ts = strtotime((string)$lastSeen);
    if ($ts === false) return false;
    return (time() - $ts) <= $thresholdSeconds;
}

function support_ready(array $device): bool {
    return !empty($device['consent_accepted']) && device_is_online($device);
}

function monthly_subscription_amount_mzn(): int {
    return 1;
}

function normalize_device(array $row): array {
    $row['deviceId'] = $row['device_id'] ?? null;
    $row['owner'] = $row['owner_user_id'] ?? null;
    $row['ownerUserId'] = $row['owner_user_id'] ?? null;
    $row['ownerDisplay'] = trim((string)($row['owner_name'] ?? $row['owner_email'] ?? $row['owner_user_id'] ?? '')) ?: null;
    $row['imei'] = $row['imei'] ?? null;
    $row['model'] = $row['model'] ?? ($row['name'] ?? null);
    $row['lastSeen'] = $row['last_seen'] ?? null;
    $row['consentAccepted'] = isset($row['consent_accepted']) ? (bool) $row['consent_accepted'] : null;
    $row['consentTs'] = $row['consent_ts'] ?? null;
    $row['consentTextVersion'] = $row['consent_text_version'] ?? null;
    $row['inAppTextCaptureEnabled'] = isset($row['in_app_text_capture_enabled']) ? (bool) $row['in_app_text_capture_enabled'] : false;
    $row['inAppTextConsentTs'] = $row['in_app_text_consent_ts'] ?? null;
    $row['inAppTextConsentVersion'] = $row['in_app_text_consent_version'] ?? null;
    $row['inAppTextConsentMode'] = $row['in_app_text_consent_mode'] ?? null;
    $row['inAppTextInstallId'] = $row['in_app_text_install_id'] ?? null;
    $row['inAppTextConsentPermanent'] = isset($row['in_app_text_consent_permanent']) ? (bool) $row['in_app_text_consent_permanent'] : false;
    $row['createdAt'] = $row['created_at'] ?? null;
    $row['subscriptionUntil'] = $row['subscription_until'] ?? null;
    $row['subscriptionStatus'] = $row['subscription_status'] ?? (($row['owner_active'] ?? null) ? 'Ativa' : 'Sem subscrição');
    $row['isOnline'] = device_is_online($row);
    $row['supportReady'] = support_ready($row);
    return $row;
}

function normalize_payment(array $row): array {
    $row['_id'] = $row['id'] ?? null;
    $row['createdAt'] = $row['created_at'] ?? null;
    $row['processedAt'] = $row['processed_at'] ?? null;
    $row['statusCheckedAt'] = $row['status_checked_at'] ?? null;
    $row['mediaFileId'] = $row['media_file_id'] ?? null;
    $row['phoneMsisdn'] = $row['phone_msisdn'] ?? null;
    $row['provider'] = $row['provider'] ?? null;
    $row['providerReference'] = $row['provider_reference'] ?? null;
    $row['providerStatus'] = $row['provider_status'] ?? null;
    $row['debitoReference'] = $row['debito_reference'] ?? null;
    $row['providerPayload'] = safe_json_decode($row['provider_payload_json'] ?? null) ?? null;
    $row['refreshable'] = !empty($row['debito_reference']) && !in_array((string)($row['status'] ?? ''), ['completed', 'rejected'], true);
    if (isset($row['email']) || isset($row['name'])) {
        $row['user'] = [
            'email' => $row['email'] ?? null,
            'name' => $row['name'] ?? null,
        ];
    }
    return $row;
}

function map_debito_payment_status(?string $providerStatus): string {
    $normalized = strtoupper(trim((string)$providerStatus));
    if (in_array($normalized, ['COMPLETED', 'SUCCESS', 'SUCCEEDED', 'PAID'], true)) return 'completed';
    if (in_array($normalized, ['FAILED', 'CANCELLED', 'CANCELED', 'REJECTED', 'EXPIRED'], true)) return 'rejected';
    return 'pending';
}

function sync_payment_with_provider(array $payment): array {
    if (empty($payment['debito_reference'])) return normalize_payment($payment);

    $providerRes = debito_request('GET', '/api/v1/transactions/' . rawurlencode((string)$payment['debito_reference']) . '/status');
    if (!$providerRes['ok']) {
        throw new RuntimeException('debito_status_refresh_failed');
    }
    $body = $providerRes['body'];
    $providerStatus = (string)($body['status'] ?? $payment['provider_status'] ?? 'PENDING');
    $localStatus = map_debito_payment_status($providerStatus);

    db()->beginTransaction();
    try {
        $up = db()->prepare('UPDATE payments SET status = ?, provider_status = ?, provider_reference = ?, provider_payload_json = ?, status_checked_at = NOW(), processed_at = CASE WHEN ? = "completed" THEN COALESCE(processed_at, NOW()) ELSE processed_at END WHERE id = ?');
        $up->execute([
            $localStatus,
            $providerStatus,
            $body['provider_reference'] ?? $payment['provider_reference'] ?? null,
            json_encode($body),
            $localStatus,
            $payment['id'],
        ]);

        if ($localStatus === 'completed') {
            $activate = db()->prepare('UPDATE users SET active = 1 WHERE id = ?');
            $activate->execute([$payment['user_id']]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    $st = db()->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
    $st->execute([$payment['id']]);
    return normalize_payment($st->fetch() ?: $payment);
}

function sync_pending_payments_for_user(string $userId, ?string $paymentId = null, int $limit = 5): void {
    $sql = 'SELECT * FROM payments WHERE user_id = ? AND debito_reference IS NOT NULL AND status NOT IN ("completed", "rejected")';
    $params = [$userId];
    if ($paymentId !== null && $paymentId !== '') {
        $sql .= ' AND id = ?';
        $params[] = $paymentId;
        $limit = 1;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, (int)$limit);
    $st = db()->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll() as $payment) {
        try {
            sync_payment_with_provider($payment);
        } catch (Throwable $ignored) {
        }
    }
}

function find_support_session(string $sessionId): ?array {
    $st = db()->prepare('SELECT * FROM support_sessions WHERE session_id = ? LIMIT 1');
    $st->execute([$sessionId]);
    $row = $st->fetch();
    return $row ?: null;
}

function normalize_support_session(array $row): array {
    $row['sessionId'] = $row['session_id'] ?? null;
    $row['deviceId'] = $row['device_id'] ?? null;
    $row['requestType'] = $row['request_type'] ?? null;
    $row['requestedByUserId'] = $row['requested_by_user_id'] ?? null;
    $row['approvedByUserId'] = $row['approved_by_user_id'] ?? null;
    $row['requestedAt'] = $row['requested_at'] ?? null;
    $row['responseDeadlineAt'] = $row['response_deadline_at'] ?? null;
    $row['respondedAt'] = $row['responded_at'] ?? null;
    $row['sessionExpiresAt'] = $row['session_expires_at'] ?? null;
    $row['stopRequestedAt'] = $row['stop_requested_at'] ?? null;
    $row['stoppedAt'] = $row['stopped_at'] ?? null;
    return $row;
}


function find_media_record(string $fileId): ?array {
    $st = db()->prepare('SELECT * FROM media WHERE file_id = ? LIMIT 1');
    $st->execute([$fileId]);
    $row = $st->fetch();
    return $row ?: null;
}

function find_media_metadata(string $fileId): ?array {
    $st = db()->prepare('SELECT * FROM media_metadata WHERE file_id = ? LIMIT 1');
    $st->execute([$fileId]);
    $row = $st->fetch();
    return $row ?: null;
}

function build_media_urls(array $user, string $fileId): array {
    return [
        'previewUrl' => '/api/media/download/' . rawurlencode($fileId) . '?media_token=' . rawurlencode(signed_media_token($user, $fileId, false)),
        'downloadUrl' => '/api/media/download/' . rawurlencode($fileId) . '?download=1&media_token=' . rawurlencode(signed_media_token($user, $fileId, true)),
    ];
}

function format_media_row(array $row, array $user): array {
    global $config;
    $contentType = (string)($row['contentType'] ?? $row['content_type'] ?? 'application/octet-stream');
    $kind = starts_with($contentType, 'image/')
        ? 'image'
        : (starts_with($contentType, 'audio/')
            ? 'audio'
            : (starts_with($contentType, 'video/') ? 'video' : 'other'));
    $storagePath = (string)($row['storagePath'] ?? $row['storage_path'] ?? '');
    $path = rtrim($config['media_dir'], '/') . '/' . $storagePath;
    $meta = find_media_metadata((string)($row['fileId'] ?? $row['file_id'] ?? ''));
    $metadataJson = safe_json_decode($meta['metadata_json'] ?? null) ?? [];
    $urls = build_media_urls($user, (string)($row['fileId'] ?? $row['file_id'] ?? ''));

    return [
        'fileId' => $row['fileId'] ?? $row['file_id'],
        'filename' => $row['filename'],
        'contentType' => $contentType,
        'uploadDate' => $row['uploadDate'] ?? $row['upload_date'],
        'kind' => $kind,
        'sizeBytes' => is_file($path) ? filesize($path) : null,
        'captureMode' => $meta['capture_mode'] ?? null,
        'captureKind' => $meta['capture_kind'] ?? null,
        'supportSessionId' => $meta['support_session_id'] ?? null,
        'segmentStartedAt' => $meta['segment_started_at'] ?? null,
        'segmentDurationMs' => isset($meta['segment_duration_ms']) ? (int)$meta['segment_duration_ms'] : null,
        'metadata' => array_merge([
            'checksum' => $row['checksum'] ?? null,
            'deviceId' => $row['deviceId'] ?? $row['device_id'] ?? null,
        ], $metadataJson),
        'previewUrl' => $urls['previewUrl'],
        'downloadUrl' => $urls['downloadUrl'],
    ];
}

function latest_metric_summary(string $deviceId, string $metricType): ?array {
    $st = db()->prepare('SELECT * FROM system_metrics WHERE device_id = ? AND metric_type = ? ORDER BY created_at DESC LIMIT 1');
    $st->execute([$deviceId, $metricType]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['context'] = safe_json_decode($row['context_json'] ?? null) ?? [];
    return $row;
}

function panel_item_limit(int $default = 10000, int $max = 50000): int {
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : $default;
    if ($limit < 1) $limit = $default;
    return min($limit, $max);
}

function support_session_live_state(array $session, array $user): array {
    $st = db()->prepare("SELECT m.file_id as fileId, m.filename, m.content_type as contentType, m.upload_date as uploadDate, m.checksum, m.device_id as deviceId, m.storage_path as storagePath
        FROM media m
        JOIN media_metadata mm ON mm.file_id = m.file_id
        WHERE mm.support_session_id = ?
        ORDER BY COALESCE(mm.segment_started_at, m.upload_date) DESC, m.upload_date DESC
        LIMIT 60");
    $st->execute([$session['session_id'] ?? $session['sessionId'] ?? null]);
    $rows = $st->fetchAll();

    $screenFrames = [];
    $audioSegments = [];
    foreach ($rows as $row) {
        $formatted = format_media_row($row, $user);
        if (in_array((string)($formatted['captureKind'] ?? ''), ['screen', 'screen_video', 'camera_front', 'camera_rear'], true) && count($screenFrames) < 12) {
            $screenFrames[] = $formatted;
            continue;
        }
        if (($formatted['captureKind'] ?? null) === 'ambient_audio' && count($audioSegments) < 20) {
            $audioSegments[] = $formatted;
        }
    }
    $screenFrames = array_reverse($screenFrames);
    $audioSegments = array_reverse($audioSegments);
    $screenFrame = $screenFrames ? end($screenFrames) : null;

    return [
        'screenFrame' => $screenFrame,
        'screenFrames' => $screenFrames,
        'audioSegments' => $audioSegments,
        'updatedAt' => $screenFrame['uploadDate'] ?? ($audioSegments ? end($audioSegments)['uploadDate'] : null),
    ];
}

function device_query_sql(string $whereSql = '1=1'): string {
    return "SELECT d.*, u.email AS owner_email, u.name AS owner_name, u.active AS owner_active,
            latest.latest_payment_at,
            CASE
                WHEN latest.latest_payment_at IS NULL THEN NULL
                ELSE DATE_ADD(latest.latest_payment_at, INTERVAL 30 DAY)
            END AS subscription_until,
            CASE
                WHEN latest.latest_payment_at IS NULL THEN 'Sem subscrição'
                WHEN DATE_ADD(latest.latest_payment_at, INTERVAL 30 DAY) >= NOW() THEN 'Ativa'
                ELSE 'Expirada'
            END AS subscription_status
        FROM devices d
        LEFT JOIN users u ON u.id = d.owner_user_id
        LEFT JOIN (
            SELECT user_id, MAX(COALESCE(processed_at, created_at)) AS latest_payment_at
            FROM payments
            WHERE status = 'completed'
            GROUP BY user_id
        ) latest ON latest.user_id = d.owner_user_id
        WHERE {$whereSql}
        ORDER BY d.last_seen DESC";
}

function recent_call_recordings_for_device(string $deviceId, array $user): array {
    $st = db()->prepare("SELECT DISTINCT m.file_id as fileId, m.filename, m.content_type as contentType, m.upload_date as uploadDate, m.checksum, m.device_id as deviceId, m.storage_path as storagePath
        FROM media m
        LEFT JOIN device_media_links dml ON dml.file_id = m.file_id
        LEFT JOIN media_metadata mm ON mm.file_id = m.file_id
        WHERE (m.device_id = ? OR dml.device_id = ?)
          AND (
            m.filename LIKE 'call_%'
            OR mm.capture_kind = 'call_audio'
            OR mm.capture_mode = 'call_recording'
          )
        ORDER BY m.upload_date DESC
        LIMIT 120");
    $st->execute([$deviceId, $deviceId]);
    return array_map(function ($row) use ($user) {
        return format_media_row($row, $user);
    }, $st->fetchAll());
}

function ensure_device_media_link(string $deviceId, string $fileId): void {
    $ins = db()->prepare('INSERT INTO device_media_links(device_id, file_id) VALUES(?,?) ON DUPLICATE KEY UPDATE created_at = created_at');
    $ins->execute([$deviceId, $fileId]);
}

function enrich_call_items(array $items, string $deviceId, array $user): array {
    $recordings = recent_call_recordings_for_device($deviceId, $user);
    $usedRecordingIds = [];

    foreach ($items as &$item) {
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $call = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $item['call'] = [
            'number' => $call['number'] ?? null,
            'direction' => $call['direction'] ?? ($call['typeLabel'] ?? null),
            'duration' => $call['duration'] ?? null,
            'contactName' => $call['contactName'] ?? null,
            'ts' => $call['ts'] ?? $item['ts'] ?? null,
        ];

        $callTsRaw = $item['call']['ts'] ?? $item['ts'] ?? null;
        if (is_numeric($callTsRaw)) {
            $callTs = (int)(((float)$callTsRaw) / 1000);
        } else {
            $callTs = strtotime((string)$callTsRaw);
        }
        if ($callTs === false || $callTs === null) continue;

        foreach ($recordings as $recording) {
            if (in_array((string)$recording['fileId'], $usedRecordingIds, true)) continue;
            $recordingStartedAtMs = $recording['metadata']['callStartedAtMs'] ?? $recording['metadata']['capturedAtMs'] ?? $recording['segmentStartedAt'] ?? null;
            if ($recordingStartedAtMs !== null && is_numeric($recordingStartedAtMs) && isset($item['call']['ts']) && is_numeric($item['call']['ts'])) {
                if (abs(((int)$recordingStartedAtMs / 1000) - ((int)$item['call']['ts'] / 1000)) > 900) {
                    continue;
                }
            }
            $uploadTs = strtotime((string)($recording['uploadDate'] ?? ''));
            if ($uploadTs === false) continue;
            if (abs($uploadTs - $callTs) > 900) continue;

            $item['call']['recording'] = [
                'fileId' => $recording['fileId'] ?? null,
                'filename' => $recording['filename'] ?? null,
                'contentType' => $recording['contentType'] ?? null,
                'previewUrl' => $recording['previewUrl'] ?? null,
                'downloadUrl' => $recording['downloadUrl'] ?? null,
                'uploadDate' => $recording['uploadDate'] ?? null,
            ];
            $usedRecordingIds[] = (string)$recording['fileId'];
            break;
        }
    }
    unset($item);

    return $items;
}

function persist_device_snapshot(string $deviceId, array $payload): void {
    $device = is_array($payload['device'] ?? null) ? $payload['device'] : $payload;
    $model = trim((string)($device['model'] ?? ''));
    $manufacturer = trim((string)($device['manufacturer'] ?? ''));
    $friendlyName = trim((string)($device['name'] ?? ($manufacturer !== '' || $model !== '' ? trim($manufacturer . ' ' . $model) : '')));
    $imei = trim((string)($device['imei'] ?? ''));

    if ($friendlyName === '' && $model === '' && $manufacturer === '' && $imei === '') {
        return;
    }

    $up = db()->prepare('UPDATE devices SET name = COALESCE(NULLIF(?, ""), name), model = COALESCE(NULLIF(?, ""), model), manufacturer = COALESCE(NULLIF(?, ""), manufacturer), imei = COALESCE(NULLIF(?, ""), imei) WHERE device_id = ?');
    $up->execute([$friendlyName, $model, $manufacturer, $imei, $deviceId]);
}

function persist_location_event(string $deviceId, array $payload): void {
    $location = is_array($payload['location'] ?? null) ? $payload['location'] : [];
    $lat = isset($location['lat']) ? (float)$location['lat'] : null;
    $lon = isset($location['lon']) ? (float)$location['lon'] : null;
    if ($lat === null || $lon === null) return;
    $observedAt = json_datetime_from_millis($payload['ts'] ?? null) ?: date('Y-m-d H:i:s');
    $ins = db()->prepare('INSERT INTO device_locations(device_id, lat, lon, accuracy, observed_at) VALUES(?,?,?,?,?)');
    $ins->execute([$deviceId, $lat, $lon, isset($location['accuracy']) ? (float)$location['accuracy'] : null, $observedAt]);
}

function persist_message_event(string $deviceId, array $payload, string $defaultSource = 'sms'): void {
    $observedAtMs = isset($payload['ts']) && is_numeric($payload['ts']) ? (int)$payload['ts'] : null;
    $observedAt = json_datetime_from_millis($observedAtMs) ?: date('Y-m-d H:i:s');
    $sender = trim((string)($payload['from'] ?? $payload['sender'] ?? ''));
    $body = $payload['body'] ?? $payload['text'] ?? null;
    $source = trim((string)($payload['source'] ?? $defaultSource));
    $contactName = trim((string)($payload['contactName'] ?? $payload['senderName'] ?? $payload['title'] ?? ''));
    $appPackage = trim((string)($payload['package'] ?? $payload['appPackage'] ?? ''));
    $direction = trim((string)($payload['direction'] ?? ''));
    $syncKey = trim((string)($payload['syncKey'] ?? ''));
    if ($syncKey === '') {
        $syncKey = sha1(json_encode([
            'source' => $source,
            'sender' => $sender,
            'body' => $body,
            'observedAtMs' => $observedAtMs ?: $observedAt,
            'package' => $appPackage,
        ]));
    }
    $ins = db()->prepare('INSERT INTO device_messages(device_id, source, sender, contact_name, app_package, direction, body, sync_key, observed_at_ms, observed_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source = VALUES(source), sender = VALUES(sender), contact_name = COALESCE(NULLIF(VALUES(contact_name), ""), contact_name), app_package = COALESCE(NULLIF(VALUES(app_package), ""), app_package), direction = COALESCE(NULLIF(VALUES(direction), ""), direction), body = VALUES(body), observed_at_ms = COALESCE(VALUES(observed_at_ms), observed_at_ms), observed_at = VALUES(observed_at)');
    $ins->execute([
        $deviceId,
        $source !== '' ? $source : $defaultSource,
        $sender !== '' ? $sender : null,
        $contactName !== '' ? $contactName : null,
        $appPackage !== '' ? $appPackage : null,
        $direction !== '' ? $direction : null,
        $body,
        $syncKey,
        $observedAtMs,
        $observedAt,
    ]);
}

function persist_notification_event(string $deviceId, array $payload): void {
    $observedAtMs = isset($payload['ts']) && is_numeric($payload['ts']) ? (int)$payload['ts'] : null;
    $observedAt = json_datetime_from_millis($observedAtMs) ?: date('Y-m-d H:i:s');
    $syncKey = trim((string)($payload['syncKey'] ?? ''));
    if ($syncKey === '') {
        $syncKey = sha1(json_encode([
            'package' => $payload['package'] ?? null,
            'title' => $payload['title'] ?? null,
            'text' => $payload['text'] ?? null,
            'observedAtMs' => $observedAtMs ?: $observedAt,
        ]));
    }
    $ins = db()->prepare('INSERT INTO device_notifications(device_id, package_name, title, body, sub_text, conversation_title, self_display_name, sync_key, observed_at_ms, observed_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE package_name = COALESCE(NULLIF(VALUES(package_name), ""), package_name), title = COALESCE(NULLIF(VALUES(title), ""), title), body = VALUES(body), sub_text = VALUES(sub_text), conversation_title = VALUES(conversation_title), self_display_name = VALUES(self_display_name), observed_at_ms = COALESCE(VALUES(observed_at_ms), observed_at_ms), observed_at = VALUES(observed_at)');
    $ins->execute([
        $deviceId,
        trim((string)($payload['package'] ?? '')) ?: null,
        trim((string)($payload['title'] ?? '')) ?: null,
        $payload['text'] ?? null,
        $payload['subText'] ?? null,
        trim((string)($payload['conversationTitle'] ?? '')) ?: null,
        trim((string)($payload['selfDisplayName'] ?? '')) ?: null,
        $syncKey,
        $observedAtMs,
        $observedAt,
    ]);
}

function persist_in_app_text_event(string $deviceId, array $payload): void {
    $observedAtMs = isset($payload['capturedAtMs']) && is_numeric($payload['capturedAtMs']) ? (int)$payload['capturedAtMs'] : (isset($payload['ts']) && is_numeric($payload['ts']) ? (int)$payload['ts'] : null);
    $observedAt = json_datetime_from_millis($observedAtMs) ?: date('Y-m-d H:i:s');
    $syncKey = trim((string)($payload['syncKey'] ?? ''));
    if ($syncKey === '') {
        $syncKey = sha1(json_encode([
            'screenName' => $payload['screenName'] ?? null,
            'fieldName' => $payload['fieldName'] ?? null,
            'text' => $payload['text'] ?? null,
            'observedAtMs' => $observedAtMs ?: $observedAt,
        ]));
    }
    $ins = db()->prepare('INSERT INTO device_text_inputs(device_id, entry_id, screen_name, field_name, text_value, text_length, is_sensitive, capture_scope, package_name, source_package, source_class_name, capture_method, consent_mode, consent_install_id, consent_permanent, sync_key, observed_at_ms, observed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE screen_name = COALESCE(NULLIF(VALUES(screen_name), ""), screen_name), field_name = COALESCE(NULLIF(VALUES(field_name), ""), field_name), text_value = VALUES(text_value), text_length = COALESCE(VALUES(text_length), text_length), is_sensitive = VALUES(is_sensitive), capture_scope = COALESCE(NULLIF(VALUES(capture_scope), ""), capture_scope), package_name = COALESCE(NULLIF(VALUES(package_name), ""), package_name), source_package = COALESCE(NULLIF(VALUES(source_package), ""), source_package), source_class_name = COALESCE(NULLIF(VALUES(source_class_name), ""), source_class_name), capture_method = COALESCE(NULLIF(VALUES(capture_method), ""), capture_method), consent_mode = COALESCE(NULLIF(VALUES(consent_mode), ""), consent_mode), consent_install_id = COALESCE(NULLIF(VALUES(consent_install_id), ""), consent_install_id), consent_permanent = VALUES(consent_permanent), observed_at_ms = COALESCE(VALUES(observed_at_ms), observed_at_ms), observed_at = VALUES(observed_at)');
    $ins->execute([
        $deviceId,
        trim((string)($payload['entryId'] ?? '')) ?: null,
        trim((string)($payload['screenName'] ?? '')) ?: null,
        trim((string)($payload['fieldName'] ?? '')) ?: null,
        $payload['text'] ?? null,
        isset($payload['textLength']) && is_numeric($payload['textLength']) ? (int)$payload['textLength'] : null,
        !empty($payload['isSensitive']) ? 1 : 0,
        trim((string)($payload['captureScope'] ?? '')) ?: null,
        trim((string)($payload['packageName'] ?? '')) ?: null,
        trim((string)($payload['sourcePackage'] ?? '')) ?: null,
        trim((string)($payload['sourceClassName'] ?? '')) ?: null,
        trim((string)($payload['captureMethod'] ?? '')) ?: null,
        trim((string)($payload['consentMode'] ?? '')) ?: null,
        trim((string)($payload['consentInstallId'] ?? '')) ?: null,
        !empty($payload['consentPermanent']) ? 1 : 0,
        $syncKey,
        $observedAtMs,
        $observedAt,
    ]);
}

function persist_call_event(string $deviceId, array $payload): void {
    $observedAtMs = isset($payload['ts']) && is_numeric($payload['ts']) ? (int)$payload['ts'] : null;
    $observedAt = json_datetime_from_millis($observedAtMs) ?: date('Y-m-d H:i:s');
    $syncKey = trim((string)($payload['syncKey'] ?? ''));
    if ($syncKey === '') {
        $syncKey = sha1(json_encode([
            'number' => $payload['number'] ?? null,
            'ts' => $observedAtMs ?: $observedAt,
            'duration' => $payload['duration'] ?? null,
            'direction' => $payload['direction'] ?? ($payload['typeLabel'] ?? null),
        ]));
    }
    $ins = db()->prepare('INSERT INTO device_calls(device_id, number, contact_name, direction, duration_seconds, sync_key, observed_at_ms, observed_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE contact_name = COALESCE(NULLIF(VALUES(contact_name), ""), contact_name), direction = COALESCE(NULLIF(VALUES(direction), ""), direction), duration_seconds = COALESCE(VALUES(duration_seconds), duration_seconds), observed_at_ms = COALESCE(VALUES(observed_at_ms), observed_at_ms), observed_at = VALUES(observed_at)');
    $ins->execute([
        $deviceId,
        $payload['number'] ?? null,
        $payload['contactName'] ?? null,
        $payload['direction'] ?? ($payload['typeLabel'] ?? null),
        isset($payload['duration']) ? (int)$payload['duration'] : null,
        $syncKey,
        $observedAtMs,
        $observedAt,
    ]);
}

function persist_contact_event(string $deviceId, array $payload): void {
    $phone = trim((string)($payload['phoneNumber'] ?? $payload['number'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $name = trim((string)($payload['displayName'] ?? $payload['name'] ?? ''));
    $contactKey = trim((string)($payload['contactKey'] ?? ($phone !== '' ? $phone : ($email !== '' ? $email : $name))));
    if ($contactKey === '') return;
    $observedAt = json_datetime_from_millis($payload['ts'] ?? null) ?: date('Y-m-d H:i:s');
    $ins = db()->prepare('INSERT INTO device_contacts(device_id, contact_key, display_name, phone_number, email, updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), phone_number = VALUES(phone_number), email = VALUES(email), updated_at = VALUES(updated_at)');
    $ins->execute([$deviceId, $contactKey, $name !== '' ? $name : null, $phone !== '' ? $phone : null, $email !== '' ? $email : null, $observedAt]);
}

function persist_app_usage_event(string $deviceId, array $payload): void {
    $apps = is_array($payload['apps'] ?? null) ? $payload['apps'] : [];
    if (!$apps) return;

    $capturedAt = json_datetime_from_millis($payload['capturedAtMs'] ?? null) ?: date('Y-m-d H:i:s');
    $windowStartAt = json_datetime_from_millis($payload['windowStartMs'] ?? null);
    $windowEndAt = json_datetime_from_millis($payload['windowEndMs'] ?? null);

    $ins = db()->prepare('INSERT INTO device_app_usage(device_id, package_name, app_name, is_system_app, first_install_time_ms, last_time_used_ms, last_time_used_at, total_time_foreground_ms, usage_window_start_at, usage_window_end_at, captured_at) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE app_name = COALESCE(NULLIF(VALUES(app_name), ""), app_name), is_system_app = VALUES(is_system_app), first_install_time_ms = COALESCE(VALUES(first_install_time_ms), first_install_time_ms), last_time_used_ms = COALESCE(VALUES(last_time_used_ms), last_time_used_ms), last_time_used_at = COALESCE(VALUES(last_time_used_at), last_time_used_at), total_time_foreground_ms = VALUES(total_time_foreground_ms), usage_window_start_at = COALESCE(VALUES(usage_window_start_at), usage_window_start_at), usage_window_end_at = COALESCE(VALUES(usage_window_end_at), usage_window_end_at), captured_at = VALUES(captured_at)');

    foreach ($apps as $app) {
        if (!is_array($app)) continue;
        $packageName = trim((string)($app['packageName'] ?? ''));
        if ($packageName === '') continue;
        $lastTimeUsedMs = isset($app['lastTimeUsedMs']) && is_numeric($app['lastTimeUsedMs']) ? (int)$app['lastTimeUsedMs'] : null;
        if ($lastTimeUsedMs !== null && $lastTimeUsedMs <= 0) $lastTimeUsedMs = null;
        $ins->execute([
            $deviceId,
            $packageName,
            trim((string)($app['appName'] ?? '')) ?: null,
            !empty($app['isSystemApp']) ? 1 : 0,
            isset($app['firstInstallTimeMs']) && is_numeric($app['firstInstallTimeMs']) ? (int)$app['firstInstallTimeMs'] : null,
            $lastTimeUsedMs,
            json_datetime_from_millis($lastTimeUsedMs),
            isset($app['totalForegroundMs']) && is_numeric($app['totalForegroundMs']) ? (int)$app['totalForegroundMs'] : 0,
            $windowStartAt,
            $windowEndAt,
            $capturedAt,
        ]);
    }
}

function device_app_usage_items(string $deviceId, ?int $limit = null): array {
    $limit = $limit ?? panel_item_limit();
    $st = db()->prepare('SELECT package_name, app_name, is_system_app, first_install_time_ms, last_time_used_ms, last_time_used_at, total_time_foreground_ms, usage_window_start_at, usage_window_end_at, captured_at, updated_at FROM device_app_usage WHERE device_id = ? ORDER BY total_time_foreground_ms DESC, COALESCE(last_time_used_ms, 0) DESC, package_name ASC LIMIT ?');
    $st->bindValue(1, $deviceId);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return array_map(static function (array $row): array {
        return [
            'packageName' => $row['package_name'],
            'appName' => $row['app_name'] ?? $row['package_name'],
            'isSystemApp' => isset($row['is_system_app']) ? (bool)$row['is_system_app'] : false,
            'firstInstallTimeMs' => isset($row['first_install_time_ms']) ? (int)$row['first_install_time_ms'] : null,
            'lastTimeUsedMs' => isset($row['last_time_used_ms']) ? (int)$row['last_time_used_ms'] : null,
            'lastTimeUsedAt' => $row['last_time_used_at'] ?? null,
            'totalForegroundMs' => isset($row['total_time_foreground_ms']) ? (int)$row['total_time_foreground_ms'] : 0,
            'usageWindowStartAt' => $row['usage_window_start_at'] ?? null,
            'usageWindowEndAt' => $row['usage_window_end_at'] ?? null,
            'capturedAt' => $row['captured_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }, $st->fetchAll());
}

function persistent_message_items(string $deviceId, string $source, ?int $limit = null): array {
    $limit = $limit ?? panel_item_limit();
    if ($source === 'sms') {
        $st = db()->prepare('SELECT source, sender, contact_name, app_package, direction, body, observed_at, observed_at_ms FROM device_messages WHERE device_id = ? AND (source = ? OR source IS NULL) ORDER BY COALESCE(observed_at_ms, UNIX_TIMESTAMP(observed_at) * 1000) DESC LIMIT ?');
    } else {
        $st = db()->prepare('SELECT source, sender, contact_name, app_package, direction, body, observed_at, observed_at_ms FROM device_messages WHERE device_id = ? AND source = ? ORDER BY COALESCE(observed_at_ms, UNIX_TIMESTAMP(observed_at) * 1000) DESC LIMIT ?');
    }
    $st->bindValue(1, $deviceId);
    $st->bindValue(2, $source);
    $st->bindValue(3, $limit, PDO::PARAM_INT);
    $st->execute();
    return array_map(static function (array $row): array {
        return [
            'ts' => $row['observed_at'],
            'payload' => [
                'source' => $row['source'] ?? 'sms',
                'from' => $row['sender'],
                'contactName' => $row['contact_name'] ?? null,
                'package' => $row['app_package'] ?? null,
                'direction' => $row['direction'] ?? null,
                'body' => $row['body'],
                'ts' => $row['observed_at_ms'] ?? $row['observed_at'],
            ],
        ];
    }, $st->fetchAll());
}

function persistent_location_items(string $deviceId, ?int $limit = null): array {
    $limit = $limit ?? panel_item_limit();
    $st = db()->prepare('SELECT lat, lon, accuracy, observed_at FROM device_locations WHERE device_id = ? ORDER BY observed_at DESC LIMIT ?');
    $st->bindValue(1, $deviceId);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return array_map(static function (array $row): array {
        return [
            'ts' => $row['observed_at'],
            'payload' => [
                'location' => [
                    'lat' => (float)$row['lat'],
                    'lon' => (float)$row['lon'],
                    'accuracy' => $row['accuracy'] !== null ? (float)$row['accuracy'] : null,
                ],
                'ts' => $row['observed_at'],
            ],
        ];
    }, $st->fetchAll());
}

function persistent_notification_items(string $deviceId, ?int $limit = null): array {
    $limit = $limit ?? panel_item_limit();
    $st = db()->prepare('SELECT package_name, title, body, sub_text, conversation_title, self_display_name, sync_key, observed_at_ms, observed_at FROM device_notifications WHERE device_id = ? ORDER BY COALESCE(observed_at_ms, UNIX_TIMESTAMP(observed_at) * 1000) DESC LIMIT ?');
    $st->bindValue(1, $deviceId);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return array_map(static function (array $row): array {
        return [
            'ts' => $row['observed_at'],
            'payload' => [
                'package' => $row['package_name'] ?? null,
                'title' => $row['title'] ?? null,
                'text' => $row['body'] ?? null,
                'subText' => $row['sub_text'] ?? null,
                'conversationTitle' => $row['conversation_title'] ?? null,
                'selfDisplayName' => $row['self_display_name'] ?? null,
                'syncKey' => $row['sync_key'] ?? null,
                'ts' => $row['observed_at_ms'] ?? $row['observed_at'],
            ],
        ];
    }, $st->fetchAll());
}

function persistent_in_app_text_items(string $deviceId, ?int $limit = null): array {
    $limit = $limit ?? panel_item_limit();
    $st = db()->prepare('SELECT entry_id, screen_name, field_name, text_value, text_length, is_sensitive, capture_scope, package_name, source_package, source_class_name, capture_method, consent_mode, consent_install_id, consent_permanent, sync_key, observed_at_ms, observed_at FROM device_text_inputs WHERE device_id = ? ORDER BY COALESCE(observed_at_ms, UNIX_TIMESTAMP(observed_at) * 1000) DESC LIMIT ?');
    $st->bindValue(1, $deviceId);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return array_map(static function (array $row): array {
        return [
            'ts' => $row['observed_at'],
            'payload' => [
                'entryId' => $row['entry_id'] ?? null,
                'screenName' => $row['screen_name'] ?? null,
                'fieldName' => $row['field_name'] ?? null,
                'text' => $row['text_value'] ?? null,
                'textLength' => $row['text_length'] !== null ? (int) $row['text_length'] : null,
                'isSensitive' => isset($row['is_sensitive']) ? (bool) $row['is_sensitive'] : false,
                'captureScope' => $row['capture_scope'] ?? null,
                'packageName' => $row['package_name'] ?? null,
                'sourcePackage' => $row['source_package'] ?? null,
                'sourceClassName' => $row['source_class_name'] ?? null,
                'captureMethod' => $row['capture_method'] ?? null,
                'consentMode' => $row['consent_mode'] ?? null,
                'consentInstallId' => $row['consent_install_id'] ?? null,
                'consentPermanent' => isset($row['consent_permanent']) ? (bool) $row['consent_permanent'] : false,
                'syncKey' => $row['sync_key'] ?? null,
                'capturedAtMs' => $row['observed_at_ms'] ?? $row['observed_at'],
            ],
        ];
    }, $st->fetchAll());
}

function contact_name_map_for_device(string $deviceId): array {
    $st = db()->prepare('SELECT display_name, phone_number FROM device_contacts WHERE device_id = ?');
    $st->execute([$deviceId]);
    $map = [];
    foreach ($st->fetchAll() as $row) {
        $normalized = normalize_phone_digits($row['phone_number'] ?? null);
        if ($normalized === '' || empty($row['display_name'])) continue;
        $map[$normalized] = $row['display_name'];
        if (strlen($normalized) > 9) $map[substr($normalized, -9)] = $row['display_name'];
    }
    return $map;
}

function persistent_call_items(string $deviceId, array $user, ?int $limit = null): array {
    $limit = $limit ?? panel_item_limit();
    $st = db()->prepare('SELECT number, contact_name, direction, duration_seconds, sync_key, observed_at_ms, observed_at FROM device_calls WHERE device_id = ? ORDER BY COALESCE(observed_at_ms, UNIX_TIMESTAMP(observed_at) * 1000) DESC LIMIT ?');
    $st->bindValue(1, $deviceId);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    $contactMap = contact_name_map_for_device($deviceId);
    $items = array_map(static function (array $row) use ($contactMap): array {
        $number = $row['number'];
        $normalized = normalize_phone_digits($number);
        $contactName = $row['contact_name'];
        if ((!$contactName || trim((string)$contactName) === '') && $normalized !== '') {
            $contactName = $contactMap[$normalized] ?? ($contactMap[strlen($normalized) > 9 ? substr($normalized, -9) : $normalized] ?? null);
        }
        return [
            'ts' => $row['observed_at'],
            'payload' => [
                'number' => $number,
                'contactName' => $contactName,
                'direction' => $row['direction'],
                'duration' => $row['duration_seconds'] !== null ? (int)$row['duration_seconds'] : null,
                'syncKey' => $row['sync_key'] ?? null,
                'ts' => $row['observed_at_ms'] ?? $row['observed_at'],
            ],
        ];
    }, $st->fetchAll());

    return enrich_call_items($items, $deviceId, $user);
}

function device_observability_summary(string $deviceId): array {
    $summary = [
        'media' => [
            'recentUploads' => 0,
            'avgUploadMs' => null,
            'failedUploads' => 0,
            'latestUploadAt' => null,
            'partialDownloads24h' => 0,
            'downloadErrors24h' => 0,
        ],
        'remoteSync' => [
            'latestStatus' => null,
            'latestSyncAt' => null,
            'lastLatencyMs' => null,
            'activeSessionId' => null,
            'activeRequestType' => null,
            'streamCapability' => null,
        ],
    ];

    $st = db()->prepare("SELECT metric_name, status, value_ms, context_json, created_at FROM system_metrics WHERE device_id = ? AND metric_type = 'media_pipeline' AND created_at >= (NOW() - INTERVAL 1 DAY) ORDER BY created_at DESC");
    $st->execute([$deviceId]);
    foreach ($st->fetchAll() as $row) {
        if ($summary['media']['latestUploadAt'] === null && $row['metric_name'] === 'upload') {
            $summary['media']['latestUploadAt'] = $row['created_at'];
        }
        if ($row['metric_name'] === 'upload') {
            if (($row['status'] ?? '') === 'ok') {
                $summary['media']['recentUploads']++;
                if ($row['value_ms'] !== null) {
                    $summary['media']['avgUploadMs'] = $summary['media']['avgUploadMs'] === null
                        ? (int)$row['value_ms']
                        : (int)round(($summary['media']['avgUploadMs'] + (int)$row['value_ms']) / 2);
                }
            } else {
                $summary['media']['failedUploads']++;
            }
        }
        if ($row['metric_name'] === 'download' && ($row['status'] ?? '') === 'partial') {
            $summary['media']['partialDownloads24h']++;
        }
        if ($row['metric_name'] === 'download' && ($row['status'] ?? '') === 'error') {
            $summary['media']['downloadErrors24h']++;
        }
    }

    $sync = latest_metric_summary($deviceId, 'remote_sync');
    if ($sync) {
        $summary['remoteSync']['latestStatus'] = $sync['status'] ?? null;
        $summary['remoteSync']['latestSyncAt'] = $sync['created_at'] ?? null;
        $summary['remoteSync']['lastLatencyMs'] = isset($sync['value_ms']) ? (int)$sync['value_ms'] : null;
        $summary['remoteSync']['activeSessionId'] = $sync['context']['activeSessionId'] ?? null;
        $summary['remoteSync']['activeRequestType'] = $sync['context']['requestType'] ?? null;
    }

    $cap = latest_metric_summary($deviceId, 'remote_stream');
    if ($cap) {
        $summary['remoteSync']['streamCapability'] = [
            'status' => $cap['status'] ?? null,
            'updatedAt' => $cap['created_at'] ?? null,
            'context' => $cap['context'] ?? [],
        ];
    }

    return $summary;
}

if (!starts_with($uri, '/api')) {
    $target = $uri === '/' ? '/index.html' : $uri;
    $file = realpath(__DIR__ . $target);
    $base = realpath(__DIR__);

    if ($file && $base && starts_with($file, $base) && is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $types = [
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
        ];
        if (isset($types[$ext])) header('Content-Type: ' . $types[$ext]);
        readfile($file);
        exit;
    }

    json_response(['error' => 'not_found'], 404);
}

try {
    ensure_schema();
    $body = get_json_body();

    if ($method === 'GET' && $uri === '/api/health') {
        json_response(['ok' => true, 'service' => 'sistema_web', 'db' => 'up']);
    }

    if ($method === 'GET' && $uri === '/api/realtime/config') {
        $u = auth_user();
        $deviceId = trim((string)($_GET['deviceId'] ?? ''));
        if ($deviceId === '') json_response(['ok' => false, 'error' => 'missing_device'], 400);
        $d = find_device($deviceId);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        $cfg = realtime_config();
        json_response([
            'ok' => true,
            'mode' => !empty($cfg['enabled']) ? 'sse' : 'polling',
            'streamUrl' => !empty($cfg['enabled'])
                ? ('/api/realtime/stream?deviceId=' . rawurlencode($deviceId) . '&stream_token=' . rawurlencode(signed_realtime_token($u, $deviceId, (int)($cfg['stream_ttl'] ?? 45))))
                : null,
        ]);
    }

    if ($method === 'GET' && $uri === '/api/realtime/stream') {
        $deviceId = trim((string)($_GET['deviceId'] ?? ''));
        $streamToken = (string)($_GET['stream_token'] ?? '');
        if ($deviceId === '' || $streamToken === '') json_response(['ok' => false, 'error' => 'invalid_token'], 401);
        $u = verify_signed_realtime_token($streamToken, $deviceId);
        if (!$u) json_response(['ok' => false, 'error' => 'invalid_token'], 401);
        $d = find_device($deviceId);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) { @ob_end_flush(); }
        ignore_user_abort(true);
        set_time_limit(0);

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : (int)($_GET['lastEventId'] ?? 0);
        $startedAt = time();
        $cfg = realtime_config();
        $maxDuration = max(10, (int)($cfg['stream_max_duration'] ?? 20));

        do {
            $st = db()->prepare('SELECT id, event_name, payload_json FROM realtime_events WHERE device_id = ? AND id > ? ORDER BY id ASC LIMIT 50');
            $st->execute([$deviceId, $lastEventId]);
            $rows = $st->fetchAll();
            foreach ($rows as $row) {
                $lastEventId = (int)$row['id'];
                echo 'id: ' . $lastEventId . "\n";
                echo 'event: ' . ($row['event_name'] ?? 'message') . "\n";
                echo 'data: ' . ($row['payload_json'] ?? '{}') . "\n\n";
            }
            if (!$rows) {
                echo "event: ping\n";
                echo 'data: ' . json_encode(['ok' => true, 'ts' => time()]) . "\n\n";
            }
            @ob_flush();
            flush();
            if (connection_aborted()) exit;
            if ((time() - $startedAt) >= $maxDuration) exit;
            sleep(1);
        } while (true);
    }

    // Auth
    if ($method === 'POST' && $uri === '/api/auth/register') {
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $password === '') json_response(['error' => 'missing_fields'], 400);

        $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) json_response(['error' => 'exists'], 400);

        $ins = db()->prepare('INSERT INTO users(email, password_hash, name) VALUES(?,?,?)');
        $ins->execute([$email, password_hash($password, PASSWORD_BCRYPT), $body['name'] ?? null]);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $uri === '/api/auth/login') {
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $password === '') json_response(['error' => 'missing_fields'], 400);

        $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();

        if (!$u || !password_verify($password, $u['password_hash'])) json_response(['error' => 'invalid_credentials'], 401);

        $payload = [
            'id' => (string) $u['id'],
            'role' => $u['role'],
            'email' => $u['email'],
            'exp' => time() + (60 * 60 * 24 * 30),
        ];
        json_response([
            'token' => jwt_sign($payload),
            'userId' => (string) $u['id'],
            'role' => $u['role'],
            'active' => (bool) $u['active'],
        ]);
    }

    if ($method === 'GET' && $uri === '/api/auth/me') {
        $user = auth_user();
        $st = db()->prepare("SELECT u.id, u.email, u.name, u.role, u.active, u.created_at,
                latest.latest_payment_at AS activated_at,
                CASE
                    WHEN latest.latest_payment_at IS NULL THEN NULL
                    ELSE DATE_ADD(latest.latest_payment_at, INTERVAL 30 DAY)
                END AS expires_at,
                CASE
                    WHEN u.role = 'admin' THEN u.active
                    WHEN latest.latest_payment_at IS NULL THEN 0
                    WHEN DATE_ADD(latest.latest_payment_at, INTERVAL 30 DAY) >= NOW() THEN 1
                    ELSE 0
                END AS subscription_active
            FROM users u
            LEFT JOIN (
                SELECT user_id, MAX(COALESCE(processed_at, created_at)) AS latest_payment_at
                FROM payments
                WHERE status = 'completed'
                GROUP BY user_id
            ) latest ON latest.user_id = u.id
            WHERE u.id = ?
            LIMIT 1");
        $st->execute([$user['id']]);
        $u = $st->fetch();
        if (!$u) json_response(['ok' => false, 'error' => 'not_found'], 404);
        $u['accountActiveFlag'] = (bool) $u['active'];
        $u['subscriptionActive'] = (bool) ($u['subscription_active'] ?? false);
        $u['active'] = $u['subscriptionActive'];
        unset($u['subscription_active']);
        json_response(['ok' => true, 'user' => $u]);
    }

    if ($method === 'POST' && $uri === '/api/auth/register-admin') {
        global $config;
        $allowed = false;
        $secret = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? ($body['adminSecret'] ?? ''));

        if (!empty($config['admin_registration_secret']) && hash_equals($config['admin_registration_secret'], $secret)) {
            $allowed = true;
        }

        if (!$allowed) {
            $caller = auth_user(false);
            if ($caller && is_admin($caller)) $allowed = true;
        }

        if (!$allowed) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $password === '') json_response(['ok' => false, 'error' => 'missing_fields'], 400);

        $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) json_response(['ok' => false, 'error' => 'exists'], 400);

        $ins = db()->prepare('INSERT INTO users(email, password_hash, name, role, active) VALUES(?,?,?,?,1)');
        $ins->execute([$email, password_hash($password, PASSWORD_BCRYPT), $body['name'] ?? '', 'admin']);

        json_response(['ok' => true, 'userId' => (string) db()->lastInsertId(), 'email' => $email]);
    }


    if ($method === 'POST' && $uri === '/api/auth/forgot-password') {
        $email = trim((string)($body['email'] ?? ''));
        if ($email !== '') {
            $st = db()->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            $u = $st->fetch();
            if ($u) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                $ins = db()->prepare('INSERT INTO password_resets(user_id, token_hash, expires_at) VALUES(?,?,?)');
                $ins->execute([$u['id'], $tokenHash, $expiresAt]);

                $baseUrl = rtrim((string)(getenv('APP_BASE_URL') ?: ''), '/');
                if ($baseUrl === '') {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseUrl = $scheme . '://' . $host;
                }

                $resetLink = $baseUrl . '/reset-password.html?token=' . urlencode($rawToken);
                $html = '<p>Recebemos um pedido para recuperar sua senha.</p>'
                    . '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES) . '">Clique aqui para redefinir sua senha</a></p>'
                    . '<p>Se você não solicitou, ignore este email.</p>';

                send_mail($u['email'], 'Recuperação de senha', $html);
            }
        }

        json_response(['ok' => true, 'message' => 'Se o email existir, enviaremos instruções.']);
    }

    if ($method === 'POST' && $uri === '/api/auth/reset-password') {
        $token = (string)($body['token'] ?? '');
        $newPassword = (string)($body['password'] ?? '');
        if ($token === '' || $newPassword === '') json_response(['ok' => false, 'error' => 'missing_fields'], 400);
        if (strlen($newPassword) < 6) json_response(['ok' => false, 'error' => 'weak_password'], 400);

        $tokenHash = hash('sha256', $token);
        $st = db()->prepare('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
        $st->execute([$tokenHash]);
        $row = $st->fetch();
        if (!$row) json_response(['ok' => false, 'error' => 'invalid_or_expired_token'], 400);

        db()->beginTransaction();
        try {
            $upUser = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upUser->execute([password_hash($newPassword, PASSWORD_BCRYPT), $row['user_id']]);

            $upReset = db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
            $upReset->execute([$row['id']]);

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        json_response(['ok' => true]);
    }

    // Devices
    if ($method === 'GET' && $uri === '/api/admin/overview') {
        require_admin();

        $summary = [
            'users' => (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
            'devices' => (int)db()->query('SELECT COUNT(*) FROM devices')->fetchColumn(),
            'onlineDevices' => (int)db()->query('SELECT COUNT(*) FROM devices WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)')->fetchColumn(),
            'activeSubscriptions' => (int)db()->query("SELECT COUNT(*) FROM (
                SELECT user_id, MAX(COALESCE(processed_at, created_at)) AS latest_payment_at
                FROM payments
                WHERE status = 'completed'
                GROUP BY user_id
            ) latest WHERE DATE_ADD(latest.latest_payment_at, INTERVAL 30 DAY) >= NOW()")->fetchColumn(),
            'monthlyRevenueMzn' => (int)db()->query("SELECT COUNT(*) * " . monthly_subscription_amount_mzn() . " FROM payments WHERE status = 'completed' AND created_at >= (NOW() - INTERVAL 30 DAY)")->fetchColumn(),
        ];

        $expiringSt = db()->query("SELECT d.device_id, COALESCE(d.name, 'Sem nome') AS model, DATE_ADD(latest.latest_payment_at, INTERVAL 30 DAY) AS subscription_until
            FROM devices d
            JOIN (
                SELECT user_id, MAX(COALESCE(processed_at, created_at)) AS latest_payment_at
                FROM payments
                WHERE status = 'completed'
                GROUP BY user_id
            ) latest ON latest.user_id = d.owner_user_id
            WHERE DATE_ADD(latest.latest_payment_at, INTERVAL 30 DAY) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
            ORDER BY subscription_until ASC
            LIMIT 10");

        json_response([
            'ok' => true,
            'summary' => $summary,
            'expiringSoon' => $expiringSt->fetchAll(),
        ]);
    }

    if ($method === 'GET' && $uri === '/api/devices/public') {
        $rows = array_map('normalize_device', db()->query(device_query_sql())->fetchAll());
        json_response(['ok' => true, 'devices' => $rows]);
    }

    if ($method === 'GET' && $uri === '/api/devices') {
        require_admin();
        $rows = array_map('normalize_device', db()->query(device_query_sql())->fetchAll());
        json_response(['ok' => true, 'devices' => $rows]);
    }

    if ($method === 'GET' && $uri === '/api/devices/my') {
        $u = auth_user();
        $st = db()->prepare(device_query_sql('d.owner_user_id = ?'));
        $st->execute([$u['id']]);
        json_response(['ok' => true, 'devices' => array_map('normalize_device', $st->fetchAll())]);
    }

    if ($method === 'POST' && $uri === '/api/devices/auto-assign') {
        $u = auth_user();
        $deviceId = trim((string)($body['deviceId'] ?? ''));
        if ($deviceId === '') json_response(['ok' => false, 'error' => 'missing_device'], 400);
        $deviceSnapshot = [
            'name' => $body['name'] ?? null,
            'model' => $body['model'] ?? null,
            'manufacturer' => $body['manufacturer'] ?? null,
            'imei' => $body['imei'] ?? null,
        ];

        $d = find_device($deviceId);
        if ($d) {
            if (!empty($d['owner_user_id']) && (string)$d['owner_user_id'] !== (string)$u['id']) {
                json_response(['ok' => false, 'error' => 'already_claimed'], 403);
            }
            $up = db()->prepare('UPDATE devices SET owner_user_id = ?, last_seen = COALESCE(last_seen, NOW()) WHERE device_id = ?');
            $up->execute([$u['id'], $deviceId]);
        } else {
            $ins = db()->prepare('INSERT INTO devices(device_id, owner_user_id, last_seen) VALUES(?,?,NOW())');
            $ins->execute([$deviceId, $u['id']]);
        }

        persist_device_snapshot($deviceId, ['device' => $deviceSnapshot]);
        $device = find_device($deviceId);
        json_response(['ok' => true, 'device' => $device ? normalize_device($device) : null]);
    }


    if ($method === 'POST' && ($m = route_match('/api/devices/:deviceId/in-app-text-consent', $uri))) {
        $u = auth_user();
        $deviceId = $m['deviceId'];
        $d = find_device($deviceId);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        $accepted = isset($body['accepted']) ? (bool)$body['accepted'] : false;
        $version = trim((string)($body['consentTextVersion'] ?? 'in-app-text-v2'));
        $consentMode = trim((string)($body['consentMode'] ?? 'install_lifetime'));
        $installId = trim((string)($body['installId'] ?? ''));
        $isPermanent = isset($body['isPermanent']) ? (bool)$body['isPermanent'] : true;

        $existingTs = $d['in_app_text_consent_ts'] ?? null;
        $existingInstallId = trim((string)($d['in_app_text_install_id'] ?? ''));
        $existingPermanent = !empty($d['in_app_text_consent_permanent']);

        $effectiveAccepted = $accepted;
        if (!$accepted && $existingPermanent && $existingInstallId !== '' && $installId !== '' && $existingInstallId === $installId) {
            $effectiveAccepted = true;
        }

        $consentTs = null;
        if ($effectiveAccepted) {
            $consentTs = ($existingInstallId !== '' && $existingInstallId === $installId && !empty($existingTs))
                ? $existingTs
                : date('Y-m-d H:i:s');
        }

        $up = db()->prepare('UPDATE devices SET in_app_text_capture_enabled = ?, in_app_text_consent_ts = ?, in_app_text_consent_version = ?, in_app_text_consent_mode = ?, in_app_text_install_id = ?, in_app_text_consent_permanent = ? WHERE device_id = ?');
        $up->execute([
            $effectiveAccepted ? 1 : 0,
            $consentTs,
            $version !== '' ? $version : null,
            $consentMode !== '' ? $consentMode : null,
            $installId !== '' ? $installId : null,
            $effectiveAccepted && $isPermanent ? 1 : 0,
            $deviceId
        ]);

        $device = find_device($deviceId);
        json_response(['ok' => true, 'device' => $device ? normalize_device($device) : null]);
    }

    if ($method === 'POST' && ($m = route_match('/api/devices/:deviceId/support-consent', $uri))) {
        $u = auth_user();
        $deviceId = $m['deviceId'];
        $d = find_device($deviceId);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        $accepted = isset($body['accepted']) ? (bool)$body['accepted'] : false;
        $version = trim((string)($body['consentTextVersion'] ?? 'support-session-v2'));
        $consentTs = $accepted ? date('Y-m-d H:i:s') : null;
        $up = db()->prepare('UPDATE devices SET consent_accepted = ?, consent_ts = ?, consent_text_version = ? WHERE device_id = ?');
        $up->execute([$accepted ? 1 : 0, $consentTs, $version !== '' ? $version : null, $deviceId]);

        $device = find_device($deviceId);
        json_response(['ok' => true, 'device' => $device ? normalize_device($device) : null]);
    }

    if ($method === 'GET' && ($m = route_match('/api/devices/:deviceId', $uri))) {
        $u = auth_user();
        $st = db()->prepare(device_query_sql('d.device_id = ?') . ' LIMIT 1');
        $st->execute([$m['deviceId']]);
        $d = $st->fetch();
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);
        json_response(['ok' => true, 'device' => normalize_device($d)]);
    }


    if ($method === 'GET' && ($m = route_match('/api/devices/:deviceId/observability', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);
        json_response(['ok' => true, 'deviceId' => $m['deviceId'], 'observability' => device_observability_summary($m['deviceId'])]);
    }

    if ($method === 'POST' && ($m = route_match('/api/devices/:deviceId/claim', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);

        if (!empty($d['owner_user_id']) && (string)$d['owner_user_id'] !== (string)$u['id']) {
            json_response(['ok' => false, 'error' => 'already_claimed'], 403);
        }

        $up = db()->prepare('UPDATE devices SET owner_user_id = ? WHERE device_id = ?');
        $up->execute([$u['id'], $m['deviceId']]);
        json_response(['ok' => true, 'deviceId' => $m['deviceId'], 'owner' => (string) $u['id']]);
    }

    // Support sessions
    if ($method === 'POST' && $uri === '/api/support-sessions/request') {
        $u = auth_user();
        $deviceId = trim((string)($body['deviceId'] ?? ''));
        $requestType = (string)($body['requestType'] ?? '');
        $note = trim((string)($body['note'] ?? ''));
        if ($deviceId === '' || !in_array($requestType, ['screen', 'ambient_audio', 'camera_front', 'camera_rear'], true)) {
            json_response(['ok' => false, 'error' => 'invalid_request'], 400);
        }

        $d = find_device($deviceId);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        if (empty($d['consent_accepted'])) {
            json_response(['ok' => false, 'error' => 'consent_required'], 409);
        }
        if (!device_is_online($d)) {
            json_response(['ok' => false, 'error' => 'device_offline'], 409);
        }

        $open = db()->prepare("SELECT session_id FROM support_sessions WHERE device_id = ? AND status IN ('pending','approved') ORDER BY requested_at DESC LIMIT 1");
        $open->execute([$deviceId]);
        $existing = $open->fetch();
        if ($existing) json_response(['ok' => false, 'error' => 'session_already_open', 'sessionId' => $existing['session_id']], 409);

        $sessionId = bin2hex(random_bytes(16));

        $ins = db()->prepare("INSERT INTO support_sessions(session_id, device_id, request_type, requested_by_user_id, approved_by_user_id, status, note, response_deadline_at, responded_at, session_expires_at) VALUES(?,?,?,?,?,'approved',?,?,?,NULL)");
        $ins->execute([$sessionId, $deviceId, $requestType, $u['id'], $u['id'], $note !== '' ? $note : null, null, date('Y-m-d H:i:s')]);

        $session = find_support_session($sessionId);
            publish_realtime_event($deviceId, 'support_session_changed', [
            'sessionId' => $sessionId,
            'status' => 'approved',
            'requestType' => $requestType,
        ]);
        json_response(['ok' => true, 'session' => $session ? normalize_support_session($session) : null]);
    }

    if ($method === 'GET' && ($m = route_match('/api/support-sessions/device/:deviceId/active', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        $st = db()->prepare("SELECT * FROM support_sessions WHERE device_id = ? AND status = 'approved' ORDER BY responded_at DESC LIMIT 1");
        $st->execute([$m['deviceId']]);
        $session = $st->fetch();
        $normalized = $session ? normalize_support_session($session) : null;
        if ($normalized) {
            $normalized['stream'] = [
                'mode' => ($normalized['requestType'] ?? null) === 'ambient_audio'
                    ? 'audio_sequence'
                    : (in_array(($normalized['requestType'] ?? null), ['camera_front', 'camera_rear'], true) ? 'camera_sequence' : 'screen_sequence'),
                'pollIntervalMs' => 10000,
                'frameIntervalMs' => 60000,
                'segmentDurationMs' => 60000,
            ];
        }
        json_response(['ok' => true, 'session' => $normalized]);
    }

    if ($method === 'GET' && ($m = route_match('/api/support-sessions/:sessionId/live-state', $uri))) {
        $u = auth_user();
        $session = find_support_session($m['sessionId']);
        if (!$session) json_response(['ok' => false, 'error' => 'not_found'], 404);
        $d = find_device($session['device_id']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        json_response([
            'ok' => true,
            'session' => normalize_support_session($session),
            'live' => support_session_live_state($session, $u),
        ]);
    }

    if ($method === 'GET' && ($m = route_match('/api/support-sessions/device/:deviceId/list', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        $st = db()->prepare('SELECT * FROM support_sessions WHERE device_id = ? ORDER BY requested_at DESC LIMIT 20');
        $st->execute([$m['deviceId']]);
        json_response(['ok' => true, 'sessions' => array_map('normalize_support_session', $st->fetchAll())]);
    }

    if ($method === 'POST' && ($m = route_match('/api/support-sessions/:sessionId/stop', $uri))) {
        $u = auth_user();
        $session = find_support_session($m['sessionId']);
        if (!$session) json_response(['ok' => false, 'error' => 'not_found'], 404);

        $d = find_device($session['device_id']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        if (!in_array($session['status'], ['pending', 'approved'], true)) json_response(['ok' => false, 'error' => 'invalid_status'], 409);

        $status = $session['status'] === 'pending' ? 'cancelled' : 'stopped';
        $up = db()->prepare('UPDATE support_sessions SET status = ?, stop_requested_at = NOW(), stopped_at = NOW() WHERE session_id = ?');
        $up->execute([$status, $m['sessionId']]);
        $updated = find_support_session($m['sessionId']);
        publish_realtime_event($session['device_id'], 'support_session_changed', [
            'sessionId' => $m['sessionId'],
            'status' => $status,
            'requestType' => $session['request_type'] ?? null,
        ]);
        json_response(['ok' => true, 'session' => $updated ? normalize_support_session($updated) : null]);
    }

    if ($method === 'GET' && ($m = route_match('/api/devices/:deviceId/contacts', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        $st = db()->prepare('SELECT display_name, phone_number, email, updated_at FROM device_contacts WHERE device_id = ? ORDER BY COALESCE(display_name, phone_number, email) ASC LIMIT 1000');
        $st->execute([$m['deviceId']]);
        json_response(['ok' => true, 'contacts' => $st->fetchAll()]);
    }

    if ($method === 'GET' && ($m = route_match('/api/devices/:deviceId/app-usage', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        json_response(['ok' => true, 'apps' => device_app_usage_items($m['deviceId'])]);
    }

    // Telemetry
    if ($method === 'POST' && ($m = route_match('/api/telemetry/:deviceId', $uri))) {
        $u = auth_user();
        $deviceId = $m['deviceId'];
        if ($deviceId === '') json_response(['ok' => false, 'error' => 'missing_device'], 400);
        $d = find_device($deviceId);
        if ($d) {
            if (empty($d['owner_user_id'])) {
                $claim = db()->prepare('UPDATE devices SET owner_user_id = COALESCE(owner_user_id, ?), last_seen = ? WHERE device_id = ?');
                $claim->execute([$u['id'], date('Y-m-d H:i:s'), $deviceId]);
                $d = find_device($deviceId);
            }
            if ($d && !can_access_device($u, $d)) {
                json_response(['ok' => false, 'error' => 'forbidden'], 403);
            }
        }

        $payload = $body;
        $ts = date('Y-m-d H:i:s');
        $ins = db()->prepare('INSERT INTO telemetry(device_id, payload, ts) VALUES(?,?,?)');
        $ins->execute([$deviceId, json_encode($payload), $ts]);

        $eventType = $payload['type'] ?? null;
        $eventPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        if ($eventType === 'metric') {
            record_metric(
                $deviceId,
                (string)($eventPayload['metricType'] ?? 'device'),
                (string)($eventPayload['metricName'] ?? 'unknown'),
                isset($eventPayload['status']) ? (string)$eventPayload['status'] : null,
                $eventPayload['valueMs'] ?? null,
                $eventPayload['valueNum'] ?? null,
                $eventPayload['context'] ?? []
            );
        }

        $up = db()->prepare('INSERT INTO devices(device_id, owner_user_id, last_seen) VALUES(?,?,?) ON DUPLICATE KEY UPDATE owner_user_id = COALESCE(owner_user_id, VALUES(owner_user_id)), last_seen = VALUES(last_seen)');
        $up->execute([$deviceId, $u['id'], $ts]);
        persist_device_snapshot($deviceId, $eventPayload);

        if ($eventType === 'telemetry') {
            persist_location_event($deviceId, $eventPayload);
            publish_realtime_event($deviceId, 'telemetry', $eventPayload);
        } elseif ($eventType === 'in_app_text_input') {
            persist_in_app_text_event($deviceId, $eventPayload);
            publish_realtime_event($deviceId, 'in_app_text_input', $eventPayload);
            record_metric($deviceId, 'in_app_text', 'capture_sync', 'ok', null, isset($eventPayload['textLength']) ? (int)$eventPayload['textLength'] : null, [
                'screenName' => $eventPayload['screenName'] ?? null,
                'fieldName' => $eventPayload['fieldName'] ?? null,
                'captureScope' => $eventPayload['captureScope'] ?? null,
            ]);
        } elseif ($eventType === 'sms') {
            persist_message_event($deviceId, $eventPayload, 'sms');
        } elseif ($eventType === 'whatsapp') {
            persist_message_event($deviceId, $eventPayload, 'whatsapp');
        } elseif ($eventType === 'notification') {
            persist_notification_event($deviceId, $eventPayload);
        } elseif ($eventType === 'call') {
            persist_call_event($deviceId, $eventPayload);
        } elseif ($eventType === 'contact') {
            persist_contact_event($deviceId, $eventPayload);
        } elseif ($eventType === 'app_usage_snapshot') {
            persist_app_usage_event($deviceId, $eventPayload);
        }

        json_response(['ok' => true, 'id' => (string) db()->lastInsertId()]);
    }

    if ($method === 'GET' && ($m = route_match('/api/telemetry/:deviceId/history', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        $limit = panel_item_limit(5000);
        $st = db()->prepare('SELECT * FROM telemetry WHERE device_id = ? ORDER BY ts DESC LIMIT ?');
        $st->bindValue(1, $m['deviceId']);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        $items = $st->fetchAll();
        foreach ($items as &$r) $r['payload'] = safe_json_decode($r['payload']) ?? [];
        json_response(['ok' => true, 'total' => count($items), 'items' => $items]);
    }

    if ($method === 'GET' && ($m = route_match('/api/telemetry/:deviceId/items', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        $type = $_GET['type'] ?? null;
        $limit = panel_item_limit();
        if ($type === 'call') {
            $items = persistent_call_items($m['deviceId'], $u, $limit);
        } elseif ($type === 'sms') {
            $items = persistent_message_items($m['deviceId'], 'sms', $limit);
        } elseif ($type === 'whatsapp') {
            $items = persistent_message_items($m['deviceId'], 'whatsapp', $limit);
        } elseif ($type === 'notification') {
            $items = persistent_notification_items($m['deviceId'], $limit);
        } elseif ($type === 'in_app_text_input') {
            $items = persistent_in_app_text_items($m['deviceId'], $limit);
        } elseif ($type === 'telemetry') {
            $items = persistent_location_items($m['deviceId'], $limit);
        } else {
            $st = db()->prepare('SELECT * FROM telemetry WHERE device_id = ? ORDER BY ts DESC LIMIT ?');
            $st->bindValue(1, $m['deviceId']);
            $st->bindValue(2, $limit, PDO::PARAM_INT);
            $st->execute();

            $items = [];
            foreach ($st->fetchAll() as $r) {
                $r['payload'] = safe_json_decode($r['payload']) ?? [];
                if (!$type || (($r['payload']['type'] ?? null) === $type)) $items[] = $r;
            }
        }

        json_response(['ok' => true, 'total' => count($items), 'items' => $items]);
    }

    // Payments
    if ($method === 'POST' && ($uri === '/api/payments/mpesa/initiate' || ($uri === '/api/payments' && strtolower(trim((string)($body['method'] ?? ''))) === 'mpesa'))) {
        $u = auth_user();
        if (!debito_is_configured()) json_response(['ok' => false, 'error' => 'debito_not_configured'], 503);

        $msisdn = preg_replace('/\D+/', '', (string)($body['msisdn'] ?? ''));
        $amount = (float)monthly_subscription_amount_mzn();
        $referenceDescription = trim((string)($body['referenceDescription'] ?? $body['reference_description'] ?? ('Pagamento mensal ' . monthly_subscription_amount_mzn() . ' MZN')));
        $note = trim((string)($body['note'] ?? ''));
        if ($msisdn === '' || $referenceDescription === '') {
            json_response(['ok' => false, 'error' => 'invalid_request'], 400);
        }

        $cfg = debito_config();
        $payload = [
            'msisdn' => $msisdn,
            'amount' => $amount,
            'reference_description' => substr($referenceDescription, 0, 100),
        ];
        if (!empty($cfg['callback_url'])) $payload['callback_url'] = $cfg['callback_url'];

        $providerRes = debito_request('POST', '/api/v1/wallets/' . rawurlencode((string)$cfg['wallet_id']) . '/c2b/mpesa', $payload);
        if (!$providerRes['ok']) {
            json_response(['ok' => false, 'error' => 'debito_request_failed', 'provider' => $providerRes['body']], 502);
        }

        $providerBody = $providerRes['body'];
        $providerStatus = (string)($providerBody['status'] ?? 'PENDING');
        $localStatus = map_debito_payment_status($providerStatus);
        $ins = db()->prepare('INSERT INTO payments(user_id, amount, currency, method, note, status, phone_msisdn, provider, provider_reference, provider_status, provider_payload_json, debito_reference) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $u['id'],
            $amount,
            'MZN',
            'mpesa',
            $note !== '' ? $note : ('Pagamento mensal fixo de ' . monthly_subscription_amount_mzn() . ' MZN'),
            $localStatus,
            $msisdn,
            'debito',
            $providerBody['provider_reference'] ?? null,
            $providerStatus,
            json_encode($providerBody),
            $providerBody['debito_reference'] ?? null,
        ]);

        if ($localStatus === 'completed') {
            $activate = db()->prepare('UPDATE users SET active = 1 WHERE id = ?');
            $activate->execute([$u['id']]);
        }

        $paymentId = (string)db()->lastInsertId();
        $st = db()->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $st->execute([$paymentId]);
        json_response(['ok' => true, 'payment' => normalize_payment($st->fetch() ?: ['id' => $paymentId]), 'provider' => $providerBody]);
    }

    if ($method === 'GET' && $uri === '/api/payments') {
        require_admin();
        $rows = db()->query('SELECT p.*, u.email, u.name FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.created_at DESC')->fetchAll();
        json_response(['ok' => true, 'payments' => array_map('normalize_payment', $rows)]);
    }

    if ($method === 'GET' && $uri === '/api/payments/mine') {
        $u = auth_user();
        if (!empty($_GET['syncPending']) || !empty($_GET['sync_pending'])) {
            sync_pending_payments_for_user((string)$u['id']);
        }
        $st = db()->prepare('SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC');
        $st->execute([$u['id']]);
        json_response(['ok' => true, 'payments' => array_map('normalize_payment', $st->fetchAll())]);
    }

    if ($method === 'POST' && ($m = route_match('/api/payments/:id/refresh-status', $uri))) {
        $u = auth_user();
        $st = db()->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $st->execute([$m['id']]);
        $payment = $st->fetch();
        if (!$payment) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!is_admin($u) && (string)$payment['user_id'] !== (string)$u['id']) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        $synced = sync_payment_with_provider($payment);
        json_response(['ok' => true, 'payment' => $synced]);
    }

    if ($method === 'POST' && ($m = route_match('/api/payments/:id/process', $uri))) {
        $u = require_admin();
        $action = (string)($body['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true)) json_response(['ok' => false, 'error' => 'invalid_action'], 400);

        $status = $action === 'approve' ? 'completed' : 'rejected';

        db()->beginTransaction();
        try {
            $up = db()->prepare('UPDATE payments SET status=?, processed_at=?, processed_by=? WHERE id=?');
            $up->execute([$status, date('Y-m-d H:i:s'), $u['id'], $m['id']]);

            if ($up->rowCount() === 0) {
                db()->rollBack();
                json_response(['ok' => false, 'error' => 'not_found'], 404);
            }

            if ($action === 'approve') {
                $q = db()->prepare('UPDATE users u JOIN payments p ON p.user_id = u.id SET u.active=1 WHERE p.id=?');
                $q->execute([$m['id']]);
            }

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        json_response(['ok' => true]);
    }


    if ($method === 'POST' && $uri === '/api/media/payment/upload') {
        $u = auth_user();
        $virtualDeviceId = '__payment__user_' . $u['id'];
        $upsert = db()->prepare('INSERT INTO devices(device_id, owner_user_id, name, last_seen) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE owner_user_id = VALUES(owner_user_id), last_seen = VALUES(last_seen)');
        $upsert->execute([$virtualDeviceId, $u['id'], 'Payment uploads']);

        $uri = '/api/media/' . $virtualDeviceId . '/upload';
    }

    // Media
    if ($method === 'GET' && ($m = route_match('/api/media/list/:deviceId', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(60, (int)($_GET['pageSize'] ?? 24)));
        $offset = ($page - 1) * $pageSize;

        $countSt = db()->prepare('SELECT COUNT(DISTINCT m.file_id) FROM media m LEFT JOIN device_media_links dml ON dml.file_id = m.file_id WHERE m.device_id = ? OR dml.device_id = ?');
        $countSt->execute([$m['deviceId'], $m['deviceId']]);
        $total = (int)$countSt->fetchColumn();

        $st = db()->prepare('SELECT DISTINCT m.file_id as fileId, m.filename, m.content_type as contentType, m.upload_date as uploadDate, m.checksum, m.device_id as deviceId, m.storage_path as storagePath FROM media m LEFT JOIN device_media_links dml ON dml.file_id = m.file_id WHERE m.device_id = ? OR dml.device_id = ? ORDER BY m.upload_date DESC LIMIT ? OFFSET ?');
        $st->bindValue(1, $m['deviceId']);
        $st->bindValue(2, $m['deviceId']);
        $st->bindValue(3, $pageSize, PDO::PARAM_INT);
        $st->bindValue(4, $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        $files = array_map(function ($row) use ($u) {
            return format_media_row($row, $u);
        }, $rows);

        json_response([
            'ok' => true,
            'files' => $files,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int)max(1, ceil($total / max(1, $pageSize))),
                'hasMore' => ($offset + count($files)) < $total,
            ],
        ]);
    }

    if ($method === 'POST' && $uri === '/api/media/checksum') {
        auth_user();
        $checksum = $body['checksum'] ?? null;
        if (!$checksum) json_response(['ok' => false, 'error' => 'missing_checksum'], 400);
        $st = db()->prepare('SELECT file_id FROM media WHERE checksum = ? LIMIT 1');
        $st->execute([$checksum]);
        $f = $st->fetch();
        json_response(['ok' => true, 'exists' => (bool)$f, 'fileId' => $f['file_id'] ?? null]);
    }

    if ($method === 'POST' && ($m = route_match('/api/media/:deviceId/upload', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) {
            $claim = db()->prepare('INSERT INTO devices(device_id, owner_user_id, last_seen) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE owner_user_id = COALESCE(owner_user_id, VALUES(owner_user_id)), last_seen = VALUES(last_seen)');
            $claim->execute([$m['deviceId'], $u['id']]);
            $d = find_device($m['deviceId']);
        } elseif (empty($d['owner_user_id'])) {
            $claim = db()->prepare('UPDATE devices SET owner_user_id = COALESCE(owner_user_id, ?), last_seen = NOW() WHERE device_id = ?');
            $claim->execute([$u['id'], $m['deviceId']]);
            $d = find_device($m['deviceId']);
        }
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        $startedAt = microtime(true);
        if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
            record_metric($m['deviceId'], 'media_pipeline', 'upload', 'error', (int)round((microtime(true) - $startedAt) * 1000), null, ['reason' => 'no_file']);
            json_response(['ok' => false, 'error' => 'no_file'], 400);
        }

        $tmp = $_FILES['media']['tmp_name'];
        $checksum = hash_file('sha256', $tmp);

        $st = db()->prepare('SELECT file_id FROM media WHERE checksum = ? LIMIT 1');
        $st->execute([$checksum]);
        $existing = $st->fetch();
        if ($existing) {
            ensure_device_media_link($m['deviceId'], (string)$existing['file_id']);
            record_metric($m['deviceId'], 'media_pipeline', 'upload', 'deduplicated', (int)round((microtime(true) - $startedAt) * 1000), null, ['fileId' => $existing['file_id']]);
            json_response(['ok' => true, 'exists' => true, 'fileId' => $existing['file_id']]);
        }

        $fileId = bin2hex(random_bytes(16));
        $safeName = $fileId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['media']['name']));

        global $config;
        if (!is_dir($config['media_dir'])) mkdir($config['media_dir'], 0775, true);

        $dest = rtrim($config['media_dir'], '/') . '/' . $safeName;
        if (!move_uploaded_file($tmp, $dest)) {
            record_metric($m['deviceId'], 'media_pipeline', 'upload', 'error', (int)round((microtime(true) - $startedAt) * 1000), null, ['reason' => 'upload_failed']);
            json_response(['ok' => false, 'error' => 'upload_failed'], 500);
        }

        $ins = db()->prepare('INSERT INTO media(file_id, device_id, filename, content_type, checksum, storage_path) VALUES(?,?,?,?,?,?)');
        $ins->execute([
            $fileId,
            $m['deviceId'],
            $_FILES['media']['name'],
            $_FILES['media']['type'] ?: 'application/octet-stream',
            $checksum,
            $safeName,
        ]);
        ensure_device_media_link($m['deviceId'], $fileId);

        $metaBody = [
            'captureMode' => trim((string)($_POST['captureMode'] ?? '')),
            'captureKind' => trim((string)($_POST['captureKind'] ?? '')),
            'supportSessionId' => trim((string)($_POST['supportSessionId'] ?? '')),
            'segmentStartedAt' => json_datetime_from_millis($_POST['segmentStartedAtMs'] ?? null),
            'segmentDurationMs' => isset($_POST['segmentDurationMs']) && is_numeric($_POST['segmentDurationMs']) ? (int)$_POST['segmentDurationMs'] : null,
            'metadataJson' => safe_json_decode($_POST['metadataJson'] ?? null),
        ];
        if ($metaBody['captureMode'] !== '' || $metaBody['captureKind'] !== '' || $metaBody['supportSessionId'] !== '' || $metaBody['segmentStartedAt'] !== null || $metaBody['segmentDurationMs'] !== null || $metaBody['metadataJson']) {
            $metaIns = db()->prepare('INSERT INTO media_metadata(file_id, capture_mode, capture_kind, support_session_id, segment_started_at, segment_duration_ms, metadata_json) VALUES(?,?,?,?,?,?,?)');
            $metaIns->execute([
                $fileId,
                $metaBody['captureMode'] !== '' ? $metaBody['captureMode'] : null,
                $metaBody['captureKind'] !== '' ? $metaBody['captureKind'] : null,
                $metaBody['supportSessionId'] !== '' ? $metaBody['supportSessionId'] : null,
                $metaBody['segmentStartedAt'],
                $metaBody['segmentDurationMs'],
                $metaBody['metadataJson'] ? json_encode($metaBody['metadataJson']) : null,
            ]);
        }

        if (($metaBody['captureMode'] ?? null) === 'remote_live') {
            publish_realtime_event($m['deviceId'], 'support_live_refresh', [
                'sessionId' => $metaBody['supportSessionId'] !== '' ? $metaBody['supportSessionId'] : null,
                'captureKind' => $metaBody['captureKind'] !== '' ? $metaBody['captureKind'] : null,
                'fileId' => $fileId,
            ]);
        }

        record_metric($m['deviceId'], 'media_pipeline', 'upload', 'ok', (int)round((microtime(true) - $startedAt) * 1000), isset($_FILES['media']['size']) ? ((float)$_FILES['media']['size'] / 1024) : null, [
            'fileId' => $fileId,
            'contentType' => $_FILES['media']['type'] ?: 'application/octet-stream',
            'captureMode' => $metaBody['captureMode'] !== '' ? $metaBody['captureMode'] : null,
            'captureKind' => $metaBody['captureKind'] !== '' ? $metaBody['captureKind'] : null,
            'supportSessionId' => $metaBody['supportSessionId'] !== '' ? $metaBody['supportSessionId'] : null,
        ]);

        json_response(['ok' => true, 'fileId' => $fileId, 'checksum' => $checksum]);
    }

    if ($method === 'GET' && ($m = route_match('/api/media/download/:fileId', $uri))) {
        $download = isset($_GET['download']) && $_GET['download'] === '1';
        $u = auth_user(false);
        if (!$u && !empty($_GET['media_token'])) {
            $u = verify_signed_media_token((string)$_GET['media_token'], $m['fileId'], $download);
        }
        if (!$u) json_response(['error' => 'invalid_token'], 401);

        $f = find_media_record($m['fileId']);
        if (!$f) json_response(['ok' => false, 'error' => 'not_found'], 404);

        $d = find_device($f['device_id']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        global $config;
        $path = rtrim($config['media_dir'], '/') . '/' . $f['storage_path'];
        if (!is_file($path)) {
            record_metric($f['device_id'], 'media_pipeline', 'download', 'error', null, null, ['fileId' => $m['fileId'], 'reason' => 'missing_file']);
            json_response(['ok' => false, 'error' => 'not_found'], 404);
        }

        $mime = $f['content_type'] ?: 'application/octet-stream';
        $size = filesize($path) ?: 0;
        $filename = basename((string)$f['filename']);
        $start = 0;
        $end = max(0, $size - 1);
        $status = 'full';

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=300');

        if ($size > 0 && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $matches)) {
            if ($matches[1] !== '') $start = (int)$matches[1];
            if ($matches[2] !== '') $end = (int)$matches[2];
            $start = max(0, min($start, $size - 1));
            $end = max($start, min($end, $size - 1));
            $status = 'partial';
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . (($end - $start) + 1));
        } else {
            header('Content-Length: ' . $size);
        }

        record_metric($f['device_id'], 'media_pipeline', 'download', $status, null, $size > 0 ? (($end - $start) + 1) : 0, [
            'fileId' => $m['fileId'],
            'download' => $download,
            'contentType' => $mime,
        ]);

        $fp = fopen($path, 'rb');
        if (!$fp) {
            record_metric($f['device_id'], 'media_pipeline', 'download', 'error', null, null, ['fileId' => $m['fileId'], 'reason' => 'open_failed']);
            json_response(['ok' => false, 'error' => 'not_found'], 404);
        }
        if ($start > 0) fseek($fp, $start);

        $remaining = ($end - $start) + 1;
        while (!feof($fp) && $remaining > 0) {
            $chunkSize = (int)min(8192, $remaining);
            $buffer = fread($fp, $chunkSize);
            if ($buffer === false) break;
            echo $buffer;
            $remaining -= strlen($buffer);
            if (function_exists('fastcgi_finish_request')) { flush(); } else { @ob_flush(); flush(); }
        }
        fclose($fp);
        exit;
    }

    json_response(['error' => 'not_found'], 404);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'db_unavailable') json_response(['ok' => false, 'error' => 'db_unavailable'], 503);
    if ($e->getMessage() === 'debito_not_configured') json_response(['ok' => false, 'error' => 'debito_not_configured'], 503);
    if ($e->getMessage() === 'debito_status_refresh_failed') json_response(['ok' => false, 'error' => 'debito_status_refresh_failed'], 502);
    if (starts_with($e->getMessage(), 'debito_request_failed:')) {
        json_response(['ok' => false, 'error' => 'debito_request_failed', 'details' => substr($e->getMessage(), strlen('debito_request_failed:'))], 502);
    }
    json_response(['ok' => false, 'error' => 'server_error'], 500);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'server_error'], 500);
}
