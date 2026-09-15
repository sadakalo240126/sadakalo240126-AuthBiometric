<?php
declare(strict_types=1);

/**
 * LoginDeviceTracker — server-side login device/network/GPS record.
 * Public webhook nay. ip-api fail hole login thame na.
 * note column na thakleo INSERT kaj kore.
 */
final class LoginDeviceTracker
{
    private const TABLE = 'login_device_logs';

    public function __construct(private \PDO $db)
    {
        date_default_timezone_set('Asia/Dhaka');
    }

    public static function record(\PDO $db, string $userIdentifier, string $status = 'Success', string $note = ''): ?string
    {
        try {
            return (new self($db))->track($userIdentifier, $status, $note);
        } catch (\Throwable $e) {
            self::debug('TRACK_WRAPPER: ' . $e->getMessage());
            return null;
        }
    }

    public function track(string $userIdentifier, string $status = 'Success', string $note = ''): ?string
    {
        $status = ($status === 'Success') ? 'Success' : 'Fail';
        $username = $this->resolveUsername($userIdentifier);

        $device = $this->collectDeviceInfo();
        $gps    = $this->getGpsData();

        $logId = 'LOG_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));

        $mapsLink = null;
        if ($gps['latitude'] !== '' && $gps['longitude'] !== '') {
            $mapsLink = 'https://maps.google.com/?q=' . rawurlencode($gps['latitude'] . ',' . $gps['longitude']);
        }

        $columns = [
            'login_time', 'log_id', 'user_id', 'login_status', 'connection_type',
            'provider_isp', 'gps_status', 'browser', 'device_model', 'operating_system',
            'ip_address', 'ip_location', 'gps_latitude', 'gps_longitude', 'user_agent',
            'google_maps_link',
        ];
        $values = [
            date('Y-m-d H:i:s'),
            $logId,
            $username,
            $status,
            $device['connection_type'],
            $device['provider_isp'],
            $gps['status'],
            $device['browser'],
            $device['device_model'],
            $device['operating_system'],
            $device['ip_address'],
            $device['ip_location'],
            $gps['latitude'] !== '' ? $gps['latitude'] : null,
            $gps['longitude'] !== '' ? $gps['longitude'] : null,
            $device['user_agent'],
            $mapsLink,
        ];

        if ($this->hasColumn('note')) {
            $columns[] = 'note';
            $values[]  = $note !== '' ? $note : null;
        }

        try {
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = 'INSERT INTO ' . self::TABLE . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            self::debug("saved=1, log_id={$logId}, user={$username}, status={$status}");
            return $logId;
        } catch (\Throwable $e) {
            self::debug('SQL_ERROR: ' . $e->getMessage());
            return null;
        }
    }

    private function hasColumn(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([self::TABLE, $column]);
            $cache[$column] = ((int) $stmt->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            $cache[$column] = false;
        }
        return $cache[$column];
    }

    private function collectDeviceInfo(): array
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = $this->getClientIp();

        $browser = 'Unknown';
        $os      = 'Unknown';
        $model   = 'Unknown';

        if (class_exists('DeviceInfo')) {
            try {
                $info    = DeviceInfo::fromRequest();
                $browser = $info->browser !== '' ? $info->browser : 'Unknown';
                $os      = $info->operatingSystem !== '' ? $info->operatingSystem : 'Unknown';
                $model   = $info->deviceModel !== '' ? $info->deviceModel : 'Unknown';
                $ua      = $info->userAgent !== '' ? $info->userAgent : $ua;
                $ip      = $info->ipAddress !== '' ? $info->ipAddress : $ip;
            } catch (\Throwable $e) {
            }
        }

        $postModel = trim((string) ($_POST['client_model'] ?? ''));
        $postOs    = trim((string) ($_POST['client_os'] ?? ''));
        $postOsVer = trim((string) ($_POST['client_os_version'] ?? ''));
        $postMobile = trim((string) ($_POST['client_mobile'] ?? ''));

        if ($postModel !== '' && strtolower($postModel) !== 'unknown') {
            $model = $postModel;
        }
        if ($postOs !== '') {
            $os = $postOs;
            if ($postOsVer !== '' && stripos($os, $postOsVer) === false) {
                $os .= ' ' . $postOsVer;
            }
        } elseif ($postOsVer !== '' && $os !== '' && stripos($os, $postOsVer) === false) {
            $os .= ' ' . $postOsVer;
        }

        $geo = $this->getIpGeolocation($ip);
        $isMobile = $geo['mobile'] || $postMobile === '1';

        return [
            'user_agent'       => $ua,
            'ip_address'       => $ip,
            'ip_location'      => trim($geo['city'] . ', ' . $geo['country'], ' ,'),
            'provider_isp'     => $geo['isp'],
            'connection_type'  => $isMobile ? 'SIM Data' : 'Broadband / Wi-Fi',
            'browser'          => $browser,
            'operating_system' => $os,
            'device_model'     => $model,
        ];
    }

    private function getClientIp(): string
    {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            foreach (explode(',', $forwarded) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return $remote;
        }
        return '0.0.0.0';
    }

    private function getIpGeolocation(string $ip): array
    {
        $fallback = ['city' => 'Unknown', 'country' => 'Unknown', 'isp' => 'Unknown', 'mobile' => false];

        if ($ip === '' || $ip === '0.0.0.0' || $ip === 'Unknown') {
            return $fallback;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['city' => 'Local', 'country' => 'Private', 'isp' => 'Local Network', 'mobile' => false];
        }
        if (!function_exists('curl_init')) {
            return $fallback;
        }

        try {
            $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,city,country,isp,mobile';
            $ch  = curl_init($url);
            if ($ch === false) {
                return $fallback;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            curl_close($ch);

            if ($errno !== 0 || !is_string($response) || $response === '') {
                return $fallback;
            }
            $data = json_decode($response, true);
            if (!is_array($data) || ($data['status'] ?? '') === 'fail') {
                return $fallback;
            }
            return [
                'city'    => (string) ($data['city'] ?? 'Unknown'),
                'country' => (string) ($data['country'] ?? 'Unknown'),
                'isp'     => (string) ($data['isp'] ?? 'Unknown'),
                'mobile'  => (bool) ($data['mobile'] ?? false),
            ];
        } catch (\Throwable $e) {
            self::debug('GEO_SKIP: ' . $e->getMessage());
            return $fallback;
        }
    }

    private function getGpsData(): array
    {
        $lat    = trim((string) ($_POST['gps_latitude'] ?? ''));
        $lng    = trim((string) ($_POST['gps_longitude'] ?? ''));
        $status = trim((string) ($_POST['gps_status'] ?? 'Unavailable'));
        if ($status === '') {
            $status = 'Unavailable';
        }
        if ($lat !== '' && !is_numeric($lat)) {
            $lat = '';
        }
        if ($lng !== '' && !is_numeric($lng)) {
            $lng = '';
        }
        return [
            'latitude'  => $lat,
            'longitude' => $lng,
            'status'    => $status,
        ];
    }

    private function resolveUsername(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            return 'unknown';
        }
        if (!ctype_digit($userId)) {
            return $userId;
        }
        try {
            $stmt = $this->db->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row['username'])) {
                return (string) $row['username'];
            }
        } catch (\Throwable $e) {
        }
        return $userId;
    }

    private static function debug(string $message): void
    {
        $dir = dirname(__DIR__) . '/Logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($dir . '/tracker_debug.log', $line, FILE_APPEND | LOCK_EX);
    }
}
