<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../Controllers/AuthController.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$msg = '';
$step = (int)($_SESSION['otp_step'] ?? 1);
$is_setup = isset($_GET['setup']) && isset($_SESSION['setup_id']);
$setup_id = $is_setup ? (int)$_SESSION['setup_id'] : null;
$delivery = strtoupper((string)($_SESSION['otp_delivery_method'] ?? ''));
$authController = new AuthController($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $msg = "<div class='alert error'>❌ সিকিউরিটি টোকেন ইনভ্যালিড! পেজটি রিফ্রেশ করে আবার চেষ্টা করুন।</div>";
    } elseif (isset($_POST['send_otp'])) {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $delivery = strtoupper((string)($_POST['delivery_method'] ?? 'SMS'));
        if (!in_array($delivery, ['EMAIL','SMS'], true)) $delivery = 'SMS';
        $response = $authController->processOtpRequest($identifier, $is_setup, $setup_id, $delivery);
        $msg = $response['message'] ?? '';
        $step = !empty($response['success']) ? 2 : 1;
        if (!empty($response['success'])) $_SESSION['otp_step'] = 2;
    } elseif (isset($_POST['verify_otp'])) {
        $identifier = (string)($_SESSION['auth_email'] ?? '');
        $otp = trim((string)($_POST['otp'] ?? ''));
        $response = $authController->verifyAndReset($identifier, $otp);
        if (!empty($response['success'])) { $step = 3; $msg = "<div class='alert success'>✅ ভেরিফিকেশন সফল! এখন নতুন পাসওয়ার্ড দিন।</div>"; }
        else { $step = 2; $msg = $response['message'] ?? "<div class='alert error'>❌ OTP যাচাই করা যায়নি।</div>"; }
    } elseif (isset($_POST['reset_password'])) {
        $identifier = (string)($_SESSION['auth_email'] ?? '');
        $new_pass = (string)($_POST['new_password'] ?? '');
        $confirm_pass = (string)($_POST['confirm_password'] ?? '');
        if ($new_pass !== $confirm_pass || strlen($new_pass) < 4 || strlen($new_pass) > 8) {
            $step = 3; $msg = "<div class='alert error'>❌ পাসওয়ার্ড দুটি মিলেনি অথবা ৪-৮ অক্ষরের মধ্যে নেই!</div>";
        } else {
            $response = $authController->verifyAndReset($identifier, 'forced_skip', $new_pass);
            if (!empty($response['success'])) {
                $step=1; $msg="<div class='alert success'>✅ পাসওয়ার্ড সফলভাবে আপডেট হয়েছে!<br><br><a href='index.php' class='login-link'>লগইন করতে এখানে ক্লিক করুন</a></div>";
            } else { $step=3; $msg=$response['message'] ?? "<div class='alert error'>❌ পাসওয়ার্ড আপডেট করা যায়নি।</div>"; }
        }
    }
}

$remaining = 0;
if ($step === 2 && !empty($_SESSION['otp_sent_at'])) {
    $validity = 300;
    try { $st=$conn->query("SELECT otp_validity_seconds FROM Global_Sms_Settings WHERE id=1 LIMIT 1"); $v=$st?$st->fetchColumn():null; if($v!==false && $v!==null) $validity=max(60,(int)$v); } catch(Throwable $e) {}
    $remaining=max(0,$validity-(time()-(int)$_SESSION['otp_sent_at']));
}
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#000000">
<link rel="manifest" href="/manifest.json">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_assets.php'; ?>
<title>Forgot Password — SADA KALO FASHION</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at top,#172554 0,#020617 55%,#000 100%);color:#f8fafc;font-family:Inter,system-ui,sans-serif;display:flex;align-items:center;justify-content:center;padding:24px 14px}.card{width:100%;max-width:430px;background:rgba(15,23,42,.94);border:1px solid #334155;border-radius:26px;padding:28px 22px;box-shadow:0 22px 60px rgba(0,0,0,.55);text-align:center}.logo{width:78px;height:78px;border-radius:50%;object-fit:cover;border:3px solid #d4af37;box-shadow:0 0 25px rgba(212,175,55,.18)}h1{font-size:21px;margin:12px 0 4px;font-weight:900}.sub{font-size:12px;color:#38bdf8;font-weight:800;margin:0 0 20px}.home{display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;color:#fff;background:#111827;border:1px solid #475569;border-radius:12px;padding:11px;margin-bottom:18px;font-size:13px;font-weight:800}.methods{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}.method{position:relative}.method input{position:absolute;opacity:0}.method label{display:block;padding:15px 8px;border:1px solid #334155;border-radius:14px;background:#0b1220;cursor:pointer;font-weight:900;font-size:13px}.method input:checked+label{border-color:#38bdf8;background:#082f49;box-shadow:0 0 0 2px rgba(56,189,248,.12)}.hint{font-size:11px;color:#94a3b8;margin:-6px 0 15px}.field{position:relative;margin-bottom:14px}.field i{position:absolute;left:13px;top:13px;color:#64748b}.field input{width:100%;padding:13px 13px 13px 38px;background:#020617;border:1px solid #334155;color:#fff;border-radius:12px;outline:none}.field input:focus{border-color:#38bdf8}.btn{width:100%;border:0;border-radius:12px;padding:13px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:900;cursor:pointer}.alert{padding:12px;border-radius:12px;margin-bottom:15px;font-size:12px;font-weight:800;text-align:left}.success{background:#064e3b;color:#6ee7b7;border:1px solid #059669}.error{background:#7f1d1d;color:#fecaca;border:1px solid #ef4444}.timer{margin:4px 0 15px;padding:10px;border-radius:12px;background:#111827;border:1px solid #334155;font-size:13px;font-weight:900}.timer b{color:#38bdf8;font-size:18px}.small{font-size:11px;color:#94a3b8;margin-top:10px}.login-link{color:#fff;font-weight:900;text-decoration:underline}.resend{display:none;margin-top:12px}.resend button{background:transparent;border:1px solid #475569;color:#cbd5e1;border-radius:10px;padding:10px;width:100%;font-weight:800}
</style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_shell.php'; ?>
<div class="card">
    <a class="home" href="/index.php"><i class="fas fa-home"></i> হোম / লগইন পেজে ফিরে যান</a>
    <img src="/logo.png" class="logo" alt="SADA KALO FASHION">
    <h1>SADA KALO FASHION</h1>
    <p class="sub"><?= $is_setup ? 'Account Security Setup' : 'Forgot Password & Verification' ?></p>
    <?= $msg ?>

    <?php if ($step === 1 && strpos($msg,'পাসওয়ার্ড সফলভাবে') === false): ?>
    <form method="post" id="sendForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>">
        <div class="methods">
            <div class="method"><input id="email" type="radio" name="delivery_method" value="EMAIL" <?= $delivery==='EMAIL'?'checked':'' ?>><label for="email"><i class="fas fa-envelope"></i><br>Email</label></div>
            <div class="method"><input id="sms" type="radio" name="delivery_method" value="SMS" <?= $delivery!=='EMAIL'?'checked':'' ?>><label for="sms"><i class="fas fa-comment-sms"></i><br>SMS + Email</label></div>
        </div>
        <div class="hint" id="methodHint">Email নির্বাচন করলে শুধু Email-এ OTP যাবে। SMS নির্বাচন করলে SMS এবং valid Email থাকলে একই OTP Email-এও যাবে।</div>
        <div class="field"><i class="fas fa-user"></i><input type="text" name="identifier" placeholder="ইমেইল অথবা মোবাইল নম্বর" required autocomplete="off"></div>
        <button class="btn" name="send_otp" type="submit"><i class="fas fa-paper-plane"></i> OTP পাঠান</button>
    </form>
    <?php elseif ($step === 2): ?>
    <div class="timer">OTP-এর সময় বাকি: <b id="timer">--:--</b></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>">
        <div class="field"><i class="fas fa-key"></i><input type="text" name="otp" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="৬-ডিজিট OTP" required autocomplete="one-time-code" style="text-align:center;font-size:20px;letter-spacing:5px"></div>
        <button class="btn" name="verify_otp" type="submit"><i class="fas fa-check-circle"></i> OTP যাচাই করুন</button>
    </form>
    <div class="small">ভুল কোড দিলে সর্বোচ্চ ৫ বার চেষ্টা করা যাবে।</div>
    <?php elseif ($step === 3): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>">
        <div class="field"><i class="fas fa-lock"></i><input type="password" name="new_password" minlength="4" maxlength="8" placeholder="নতুন পাসওয়ার্ড (৪-৮ অক্ষর)" required></div>
        <div class="field"><i class="fas fa-check-double"></i><input type="password" name="confirm_password" minlength="4" maxlength="8" placeholder="পুনরায় পাসওয়ার্ড দিন" required></div>
        <button class="btn" name="reset_password" type="submit"><i class="fas fa-save"></i> পাসওয়ার্ড সেভ করুন</button>
    </form>
    <?php endif; ?>
</div>
<script>
(function(){
 const hint=document.getElementById('methodHint');
 document.querySelectorAll('input[name="delivery_method"]').forEach(r=>r.addEventListener('change',()=>{hint.textContent=r.value==='EMAIL'?'Email নির্বাচন করলে শুধু Email-এ OTP যাবে।':'SMS নির্বাচন করলে SMS এবং valid Email থাকলে একই OTP Email-এও যাবে.'}));
 let left=<?= (int)$remaining ?>, el=document.getElementById('timer');
 if(el){ const tick=()=>{left=Math.max(0,left); const m=Math.floor(left/60),s=left%60; el.textContent=String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); if(left===0){el.textContent='00:00';} else {left--;setTimeout(tick,1000)}}; tick(); }
})();
</script>
</body>
</html>
