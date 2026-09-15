<?php
declare(strict_types=1);

require_once __DIR__ . '/../Models/LoginModel.php';
require_once __DIR__ . '/../Services/CaptchaService.php';
require_once __DIR__ . '/../Services/SessionGuard.php';
require_once __DIR__ . '/../Services/DeviceInfo.php';
require_once __DIR__ . '/../Services/LoginLogger.php';
$loginDeviceTrackerPath = __DIR__ . '/../Services/LoginDeviceTracker.php';
if (is_file($loginDeviceTrackerPath)) {
    require_once $loginDeviceTrackerPath;
}
unset($loginDeviceTrackerPath);
$loginEmailAlertServicePath = __DIR__ . '/../Services/LoginEmailAlert.php';
if (is_file($loginEmailAlertServicePath)) {
    require_once $loginEmailAlertServicePath;
}
unset($loginEmailAlertServicePath);

enum LoginResultType: string {
    case Success        = 'success';
    case WrongPassword  = 'wrong_password';
    case AccountLocked  = 'account_locked';
    case AccountBlocked = 'account_blocked';
    case UserNotFound   = 'user_not_found';
    case SetupRequired  = 'setup_required';
    case CsrfInvalid    = 'csrf_invalid';
    case CaptchaInvalid = 'captcha_invalid';
    case SystemError    = 'system_error';
}

readonly class LoginResult {
    public function __construct(
        public LoginResultType $type,
        public string          $htmlMessage,
        public ?array          $userData = null,
    ) {}
    public function isSuccess(): bool       { return $this->type === LoginResultType::Success; }
    public function isSetupRequired(): bool { return $this->type === LoginResultType::SetupRequired; }
}

class LoginController {

    private \PDO                    $db;
    private LoginModelInterface     $loginModel;
    private CaptchaServiceInterface $captchaService;
    private LoginLogger             $loginLogger;
    private DeviceInfo              $deviceInfo;

    public function __construct(\PDO $db) {
        $this->db             = $db;
        $this->loginModel     = new LoginModel($db);
        $this->captchaService = new CaptchaService();
        $this->loginLogger    = new LoginLogger();
        $this->deviceInfo     = DeviceInfo::fromRequest();
        $this->bootSession();
        date_default_timezone_set('Asia/Dhaka');
    }

    private function bootSession(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    public function redirectIfLoggedIn(): void {
        if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
            header('Location: dashboard.php'); exit;
        }
    }

    public function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_login_token'])) {
            $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_login_token'];
    }

    private function verifyCsrfToken(): bool {
        $in  = trim((string)($_POST['csrf_token'] ?? ''));
        $str = (string)($_SESSION['csrf_login_token'] ?? '');
        return $str !== '' && $in !== '' && hash_equals($str, $in);
    }

    private function fireLoginAlert(string $alertType, array $u, string $note): void {
        if (!class_exists('LoginEmailAlert')) return;
        $accountName = (string)($u['username'] ?? ($u['id'] ?? ''));
        $userEmail   = (string)($u['email']    ?? '');
        $phoneNumber = (string)($u['phone']    ?? ($u['mobile'] ?? ''));
        match ($alertType) {
            'success' => LoginEmailAlert::success($accountName, $userEmail, $phoneNumber, $note),
            'failed'  => LoginEmailAlert::failed($accountName, $userEmail, $phoneNumber, $note),
            'blocked' => LoginEmailAlert::blocked($accountName, $userEmail, $phoneNumber, $note),
            default   => null,
        };
    }

    private function trackDevice(string $identifier, string $status, string $note = ''): void {
        if (!class_exists('LoginDeviceTracker')) return;
        LoginDeviceTracker::record($this->db, $identifier, $status, $note);
    }

    public function getViewData(): array {
        return [
            'siteNoticeText' => $this->loginModel->getSiteNotice(),
            'sliderPosts'    => $this->loginModel->getSliderPosts(),
            'csrfToken'      => $this->generateCsrfToken(),
        ];
    }

    public function handlePost(): ?LoginResult {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['login_btn'])) return null;

        if (!$this->verifyCsrfToken()) {
            return new LoginResult(LoginResultType::CsrfInvalid,
                $this->errHtml('পেজ রিফ্রেশ করুন 🏄'));
        }

        if (!$this->captchaService->verify()) {
            return new LoginResult(LoginResultType::CaptchaInvalid,
                $this->errHtml($this->captchaService->getErrorMessage()));
        }

        $id   = trim((string)($_POST['username'] ?? ''));
        $pass = trim((string)($_POST['password'] ?? ''));
        if ($id === '' || $pass === '') {
            return new LoginResult(LoginResultType::UserNotFound,
                $this->errHtml('ইউজার আইডি ও পাসওয়ার্ড ভুল 🫡'));
        }

        try {
            $user = $this->loginModel->findByIdentifier($id);
            if ($user === null) {
                $this->loginLogger->record('LOGIN_FAILED', $id, $this->deviceInfo, 'User not found');
                $this->trackDevice($id, 'Fail', 'User not found');
                $this->fireLoginAlert('failed', ['username' => $id], 'wrong identifier');
                return new LoginResult(LoginResultType::UserNotFound,
                    $this->errHtml('🤐 সিস্টেম এ তোমাকে পাওয়া যাচ্ছে না Sorry 🫡'));
            }

            $lockResult = $this->checkLock($user);
            if ($lockResult !== null) return $lockResult;

            $stored = (string)($user['password'] ?? '');
            $passOk = $stored !== '' && password_verify($pass, $stored);
            if (!$passOk) return $this->processFailedAttempt($user);

            $blockResult = $this->checkBlock($user);
            if ($blockResult !== null) return $blockResult;

            return $this->processSuccess($user);

        } catch (\Throwable $e) {
            $this->loginModel->logError("handlePost failed: {$id}", $e);
            return new LoginResult(LoginResultType::SystemError,
                $this->errHtml('\u274c \u09b8\u09bf\u09b8\u09cd\u099f\u09c7\u09ae \u098f\u09b0\u09b0! \u0995\u09bf\u099b\u09c1\u0995\u09cd\u09b7\u09a3 \u09aa\u09b0 \u099a\u09c7\u09b7\u09cd\u099f\u09be \u0995\u09b0\u09c1\u09a8\u0964'));
        }
    }

    private function checkLock(array $u): ?LoginResult {
        if (empty($u['lock_until'])) return null;
        $ts = strtotime((string)$u['lock_until']);
        if ($ts === false || $ts <= time()) return null;
        $until = date('h:i A', $ts);
        $this->fireLoginAlert('blocked', $u, 'locked account');
        $this->trackDevice((string)($u['username'] ?? $u['id'] ?? ''), 'Fail', 'Account locked');
        return new LoginResult(LoginResultType::AccountLocked, $this->lockHtml($until), $u);
    }

    private function checkBlock(array $u): ?LoginResult {
        if (($u['status'] ?? 'active') !== 'blocked') return null;
        $end = (string)($u['block_end'] ?? '');
        if ($end !== '') {
            if ($this->loginModel->autoUnblockIfExpired((int)$u['id'], $end)) return null;
            $t = date('d M, h:i A', strtotime($end));
            $this->fireLoginAlert('blocked', $u, 'blocked account');
            $this->trackDevice((string)($u['username'] ?? $u['id'] ?? ''), 'Fail', 'Account blocked');
            $html = $this->blockHtml('🧨 সার্ভার এর মেরামত চলছে, প্লিজ 🌏', "<br>Timer  {$t}");
            return new LoginResult(LoginResultType::AccountBlocked, $html, $u);
        }
        $this->fireLoginAlert('blocked', $u, 'admin blocked');
        $this->trackDevice((string)($u['username'] ?? $u['id'] ?? ''), 'Fail', 'Account blocked by admin');
        return new LoginResult(LoginResultType::AccountBlocked,
            $this->blockHtml('\u274c \u0985\u09cd\u09af\u09be\u0995\u09cd\u09b8\u09c7\u09b8 \u09b8\u09cd\u09a5\u0997\u09bf\u09a4\u0964', ''), $u);
    }

    private function processFailedAttempt(array $u): LoginResult {
        $userId  = (int)$u['id'];
        $account = (string)($u['username'] ?? $u['id']);
        $cur     = (int)($u['failed_attempts'] ?? 0) + 1;
        $max     = LoginModel::getMaxFailedAttempts();
        if ($cur >= $max) {
            $lockUntil = date('Y-m-d H:i:s', strtotime('+' . LoginModel::getLockDurationMinutes() . ' minutes'));
            $this->loginModel->lockAccount($userId, $lockUntil);
            $this->loginLogger->record('LOGIN_LOCKED', $account, $this->deviceInfo, 'Too many wrong passwords');
            $this->trackDevice($account, 'Fail', 'Too many wrong passwords');
            $this->fireLoginAlert('blocked', $u, 'too many wrong passwords');
            return new LoginResult(LoginResultType::AccountLocked,
                $this->lockHtml(date('h:i A', strtotime($lockUntil))), $u);
        }
        $this->loginModel->incrementFailedAttempts($userId);
        $this->loginLogger->record('LOGIN_FAILED', $account, $this->deviceInfo, "Wrong password (attempt {$cur})");
        $this->trackDevice($account, 'Fail', "Wrong password (attempt {$cur})");
        $this->fireLoginAlert('failed', $u, 'wrong password');
        $left = $max - $cur;
        $html = "<div style='padding:12px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;color:#ef4444;font-weight:bold;font-size:13px;text-align:center;'>\u274c {$left}</div>";
        return new LoginResult(LoginResultType::WrongPassword, $html, $u);
    }

    private function processSuccess(array $u): LoginResult {
        $userId = (int)$u['id'];
        $this->loginModel->resetFailedAttempts($userId);
        if (isset($u['is_verified']) && (int)$u['is_verified'] === 0) {
            $_SESSION['setup_id']       = $userId;
            $_SESSION['setup_username'] = (string)($u['username'] ?? '');
            return new LoginResult(LoginResultType::SetupRequired, '', $u);
        }

        session_regenerate_id(true);
        $_SESSION['loggedin']    = true;
        $_SESSION['user_id']     = $userId;
        $_SESSION['role']        = (string)($u['role']        ?? 'user');
        $_SESSION['username']    = (string)($u['username']    ?? '');
        $_SESSION['email']       = (string)($u['email']       ?? '');
        $_SESSION['profile_pic'] = (string)($u['profile_pic'] ?? 'default_user.png');
        SessionGuard::issueToken($this->db, $userId);
        $this->loginModel->updateLastLogin($userId);
        $this->loginLogger->record('LOGIN_SUCCESS', (string)($u['username'] ?? $userId), $this->deviceInfo, 'Password login');
        $this->trackDevice((string)($u['username'] ?? $userId), 'Success', 'Password login');
        $this->fireLoginAlert('success', $u, 'password login');
        unset($_SESSION['csrf_login_token']);
        return new LoginResult(LoginResultType::Success, '', $u);
    }

    private function errHtml(string $msg): string {
        $s = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        return "<div style='padding:12px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;color:#ef4444;font-weight:bold;font-size:13px;text-align:center;'>{$s}</div>";
    }

    private function lockHtml(string $unlockTime): string {
        $t = htmlspecialchars($unlockTime, ENT_QUOTES, 'UTF-8');
        return "<div style='background:#fef2f2;border:2px solid #ef4444;border-radius:14px;padding:18px;text-align:center;'>locked until {$t} \u2014 <a href='Auth/otp_auth.php'>reset</a></div>";
    }

    private function blockHtml(string $mainMsg, string $extra): string {
        return "<div style='padding:12px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;font-size:13px;font-weight:bold;'>{$mainMsg}{$extra}</div>";
    }
}
