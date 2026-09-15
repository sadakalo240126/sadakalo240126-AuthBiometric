<?php
declare(strict_types=1);

require_once __DIR__ . '/../Models/BiometricModel.php';
require_once __DIR__ . '/../Services/WebAuthnService.php';
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

class WebAuthnController
{
    private \PDO            $db;
    private BiometricModel  $model;
    private WebAuthnService $svc;
    private LoginLogger     $loginLogger;
    private DeviceInfo      $deviceInfo;
    private string          $rpId;
    private string          $rpName = 'Sada Kalo Fashion';

    public function __construct(\PDO $db)
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }

        $host        = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $this->rpId  = preg_replace('/:\d+$/', '', $host);
        $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $origins     = [$scheme . '://' . $host];

        $this->db          = $db;
        $this->model       = new BiometricModel($db);
        $this->svc         = new WebAuthnService($this->rpId, $origins);
        $this->loginLogger = new LoginLogger();
        $this->deviceInfo  = DeviceInfo::fromRequest();
    }

    public function handle(): bool
    {
        $action = $_POST['webauthn'] ?? $_GET['webauthn'] ?? null;
        if ($action === null) { return false; }

        header('Content-Type: application/json; charset=utf-8');
        if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }

        try {
            switch ($action) {
                case 'reg_options':  $this->regOptions();  break;
                case 'reg_verify':   $this->regVerify();   break;
                case 'auth_options': $this->authOptions(); break;
                case 'auth_verify':  $this->authVerify();  break;
                case 'list':         $this->listDevices(); break;
                case 'delete':       $this->deleteDevice();break;
                default: $this->out(['ok' => false, 'msg' => 'Unknown action']);
            }
        } catch (\Throwable $e) {
            $this->out(['ok' => false, 'msg' => $e->getMessage()]);
        }
        return true;
    }

    private function out(array $data): void { echo json_encode($data); exit; }

    private function requireLogin(): int
    {
        if (empty($_SESSION['loggedin']) || empty($_SESSION['user_id'])) {
            $this->out(['ok' => false, 'msg' => 'Login required']);
        }
        return (int) $_SESSION['user_id'];
    }

    private function fireLoginAlert(string $alertType, array $u, string $note): void
    {
        if (!class_exists('LoginEmailAlert')) { return; }
        $accountName = (string) ($u['username'] ?? ($u['id'] ?? ''));
        $userEmail   = (string) ($u['email']    ?? '');
        $phoneNumber = (string) ($u['phone']    ?? ($u['mobile'] ?? ''));
        match ($alertType) {
            'success' => LoginEmailAlert::success($accountName, $userEmail, $phoneNumber, $note),
            'blocked' => LoginEmailAlert::blocked($accountName, $userEmail, $phoneNumber, $note),
            default   => null,
        };
    }

    private function trackDevice(string $identifier, string $status, string $note = ''): void
    {
        if (!class_exists('LoginDeviceTracker')) { return; }
        LoginDeviceTracker::record($this->db, $identifier, $status, $note);
    }

    private function regOptions(): void
    {
        $userId = $this->requireLogin();
        $user   = $this->model->findUserById($userId);
        if ($user === null) { $this->out(['ok' => false, 'msg' => 'User not found']); }

        $challenge = WebAuthnService::newChallenge();
        $_SESSION['webauthn_reg_challenge'] = $challenge;

        $exclude = [];
        foreach ($this->model->getCredentialsByUser($userId) as $c) {
            $exclude[] = ['type' => 'public-key', 'id' => $c['credential_id']];
        }

        $this->out([
            'ok'        => true,
            'challenge' => $challenge,
            'rp'        => ['id' => $this->rpId, 'name' => $this->rpName],
            'user'      => [
                'id'          => WebAuthnService::b64uEnc((string) $userId),
                'name'        => (string) ($user['username'] ?? 'user'),
                'displayName' => (string) ($user['username'] ?? 'user'),
            ],
            'pubKeyCredParams'     => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
            'excludeCredentials'   => $exclude,
            'timeout'              => 60000,
            'attestation'          => 'none',
        ]);
    }

    private function regVerify(): void
    {
        $userId    = $this->requireLogin();
        $challenge = (string) ($_SESSION['webauthn_reg_challenge'] ?? '');
        if ($challenge === '') { $this->out(['ok' => false, 'msg' => 'Session expired']); }
        unset($_SESSION['webauthn_reg_challenge']);

        $clientDataJSON    = WebAuthnService::b64uDec((string) ($_POST['clientDataJSON'] ?? ''));
        $attestationObject = WebAuthnService::b64uDec((string) ($_POST['attestationObject'] ?? ''));
        $label             = trim((string) ($_POST['label'] ?? 'My device'));
        if ($label === '') { $label = 'My device'; }

        $res = $this->svc->verifyRegistration($clientDataJSON, $attestationObject, $challenge);

        if ($this->model->getByCredentialId($res['credentialId']) !== null) {
            $this->out(['ok' => false, 'msg' => 'Device already registered']);
        }
        $saved = $this->model->saveCredential($userId, $res['credentialId'], $res['publicKey'], (int) $res['signCount'], $label);

        if ($saved) {
            $this->loginLogger->record(
                'BIOMETRIC_ENROLL',
                (string) ($_SESSION['username'] ?? $userId),
                $this->deviceInfo,
                'Device: ' . $label
            );
        }

        $this->out($saved
            ? ['ok' => true, 'msg' => 'Biometric enabled']
            : ['ok' => false, 'msg' => 'Save failed']);
    }

    private function authOptions(): void
    {
        $challenge = WebAuthnService::newChallenge();
        $_SESSION['webauthn_auth_challenge'] = $challenge;

        $allow    = [];
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($username !== '') {
            $user = $this->model->findUserByUsername($username);
            if ($user !== null) {
                foreach ($this->model->getCredentialsByUser((int) $user['id']) as $c) {
                    $allow[] = ['type' => 'public-key', 'id' => $c['credential_id']];
                }
            }
        }

        $this->out([
            'ok'               => true,
            'challenge'        => $challenge,
            'rpId'             => $this->rpId,
            'userVerification' => 'required',
            'timeout'          => 60000,
            'allowCredentials' => $allow,
        ]);
    }

    private function authVerify(): void
    {
        $challenge = (string) ($_SESSION['webauthn_auth_challenge'] ?? '');
        if ($challenge === '') { $this->out(['ok' => false, 'msg' => 'Session expired']); }
        unset($_SESSION['webauthn_auth_challenge']);

        $credId            = (string) ($_POST['id'] ?? '');
        $clientDataJSON    = WebAuthnService::b64uDec((string) ($_POST['clientDataJSON'] ?? ''));
        $authenticatorData = WebAuthnService::b64uDec((string) ($_POST['authenticatorData'] ?? ''));
        $signature         = WebAuthnService::b64uDec((string) ($_POST['signature'] ?? ''));
        if ($credId === '') { $this->out(['ok' => false, 'msg' => 'Missing credential']); }

        $cred = $this->model->getByCredentialId($credId);
        if ($cred === null) {
            $this->trackDevice(trim((string) ($_POST['id'] ?? 'unknown')), 'Fail', 'Biometric device not registered');
            $this->out(['ok' => false, 'msg' => 'Device not registered']);
        }

        $newCount = $this->svc->verifyAuthentication(
            $clientDataJSON, $authenticatorData, $signature, (string) $cred['public_key'], $challenge
        );

        $old = (int) $cred['sign_count'];
        if ($newCount > 0 && $old > 0 && $newCount <= $old) {
            $this->out(['ok' => false, 'msg' => 'Security warning']);
        }
        $this->model->updateSignCount($credId, $newCount);
        $this->model->touchLastUsed($credId);

        $user = $this->model->findUserById((int) $cred['user_id']);
        if ($user === null) { $this->out(['ok' => false, 'msg' => 'User not found']); }

        if (($user['status'] ?? 'active') === 'blocked') {
            $this->fireLoginAlert('blocked', $user, 'biometric blocked');
            $this->trackDevice((string) ($user['username'] ?? $user['id'] ?? ''), 'Fail', 'Biometric login blocked');
            $this->out(['ok' => false, 'msg' => 'Account blocked']);
        }

        session_regenerate_id(true);
        $_SESSION['loggedin']    = true;
        $_SESSION['user_id']     = (int) $user['id'];
        $_SESSION['role']        = (string) ($user['role']        ?? 'user');
        $_SESSION['username']    = (string) ($user['username']    ?? '');
        $_SESSION['email']       = (string) ($user['email']       ?? '');
        $_SESSION['profile_pic'] = (string) ($user['profile_pic'] ?? 'default_user.png');

        SessionGuard::issueToken($this->db, (int) $user['id']);

        $this->loginLogger->record(
            'BIOMETRIC_LOGIN',
            (string) ($user['username'] ?? $user['id']),
            $this->deviceInfo,
            'Fingerprint/Face login'
        );
        $this->trackDevice((string) ($user['username'] ?? $user['id']), 'Success', 'Fingerprint/Face login');
        $this->fireLoginAlert('success', $user, 'biometric login');

        $this->out(['ok' => true, 'msg' => 'Login success', 'redirect' => 'dashboard.php']);
    }

    private function listDevices(): void
    {
        $userId = $this->requireLogin();
        $this->out(['ok' => true, 'devices' => $this->model->getCredentialsByUser($userId)]);
    }

    private function deleteDevice(): void
    {
        $userId = $this->requireLogin();
        $id     = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) { $this->out(['ok' => false, 'msg' => 'Invalid id']); }
        $ok = $this->model->deleteCredential($id, $userId);
        $this->out(['ok' => $ok, 'msg' => $ok ? 'Deleted' : 'Delete failed']);
    }
}
