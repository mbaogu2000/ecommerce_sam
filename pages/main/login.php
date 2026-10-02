<?php
include __DIR__ . "/config.php";   // starts session, provides $conn (mysqli) and sendMail()

if (isset($user_profile) && $user_profile !== null) {
    header('location:/member');
    exit;
}

/* ===================== HELPERS ===================== */
function jout($ok, $msg, $extra = [])
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra));
    exit;
}

function newToken()
{
    return bin2hex(random_bytes(32));
}

function cleanPhone($p)
{
    return preg_replace('/[^\d+]/', '', trim($p));
}

// 6 mixed letters+digits, checked against the DB so it never repeats
function makeUid($conn)
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $uid = '';
        for ($i = 0; $i < 6; $i++) {
            $uid .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $st = $conn->prepare("SELECT 1 FROM users WHERE uid=? LIMIT 1");
        $st->bind_param("s", $uid);
        $st->execute();
        $st->store_result();
        $taken = $st->num_rows > 0;
        $st->close();
    } while ($taken);
    return $uid;
}

function loginUser($conn, $uid)
{
    $token = newToken();
    $st = $conn->prepare("UPDATE users SET token=? WHERE uid=?");
    $st->bind_param("ss", $token, $uid);
    $st->execute();
    $st->close();
    session_regenerate_id(true);
    $_SESSION['suid'] = $uid;
    $_SESSION['tid']  = $token;
}

/* ===================== AJAX HANDLER ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    /* ---------- SIGN IN ---------- */
    if ($action === 'signin') {
        $user = trim($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        if ($user === '' || $pass === '') jout(false, 'Enter your email/phone and password.');

        $phone = cleanPhone($user);
        $st = $conn->prepare("SELECT uid, password FROM users WHERE email=? OR phone=? LIMIT 1");
        $st->bind_param("ss", $user, $phone);
        $st->execute();
        $res = $st->get_result();
        $row = $res->fetch_assoc();
        $st->close();

        if (!$row || !password_verify($pass, $row['password'])) {
            jout(false, 'Invalid login details.');
        }
        loginUser($conn, $row['uid']);
        jout(true, 'Welcome back!', ['redirect' => '/member']);
    }

    /* ---------- SIGN UP ---------- */
    if ($action === 'signup') {
        $name  = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = cleanPhone($_POST['phone'] ?? '');
        $pass  = $_POST['pass'] ?? '';
        $pass2 = $_POST['pass2'] ?? '';

        $parts = explode(' ', $name, 2);
        $firstname = $parts[0] ?? '';
        $lastname  = $parts[1] ?? '';

        if ($firstname === '' || $lastname === '')            jout(false, 'Enter your first and last name.');
        if (!preg_match("/^[\p{L}'\- ]{2,60}$/u", $name))     jout(false, 'Name contains invalid characters.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))       jout(false, 'Enter a valid email address.');
        if (!preg_match('/^\+?\d{7,15}$/', $phone))           jout(false, 'Enter a valid phone number.');
        if (strlen($pass) < 8)                                jout(false, 'Password must be at least 8 characters.');
        if ($pass !== $pass2)                                 jout(false, 'Passwords do not match.');

        $st = $conn->prepare("SELECT email, phone FROM users WHERE email=? OR phone=? LIMIT 1");
        $st->bind_param("ss", $email, $phone);
        $st->execute();
        $dup = $st->get_result()->fetch_assoc();
        $st->close();
        if ($dup) {
            jout(false, $dup['email'] === $email ? 'Email is already registered.' : 'Phone number is already registered.');
        }

        $uid   = makeUid($conn);
        $token = newToken();
        $hash  = password_hash($pass, PASSWORD_DEFAULT);

        $st = $conn->prepare("INSERT INTO users (uid, firstname, lastname, email, phone, password, token) VALUES (?,?,?,?,?,?,?)");
        $st->bind_param("sssssss", $uid, $firstname, $lastname, $email, $phone, $hash, $token);
        if (!$st->execute()) jout(false, 'Could not create account. Try again.');
        $st->close();

        session_regenerate_id(true);
        $_SESSION['suid'] = $uid;
        $_SESSION['tid']  = $token;
        jout(true, 'Account created!', ['redirect' => '/member']);
    }

    /* ---------- FORGOT 1: send 4 digit code ---------- */
    if ($action === 'forgot_send') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jout(false, 'Enter a valid email address.');

        $st = $conn->prepare("SELECT uid, firstname FROM users WHERE email=? LIMIT 1");
        $st->bind_param("s", $email);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) jout(false, 'No account found with that email.');

        $code = (string) random_int(1000, 9999);
        $st = $conn->prepare("UPDATE users SET token=? WHERE uid=?");
        $st->bind_param("ss", $code, $row['uid']);
        $st->execute();
        $st->close();

        $_SESSION['fp_email']    = $email;
        $_SESSION['fp_time']     = time();
        $_SESSION['fp_tries']    = 0;
        $_SESSION['fp_verified'] = false;

        $subject = "Your Velora password reset code";
        $html = "<div style='font-family:Arial,sans-serif;max-width:420px;margin:auto;padding:24px;border:1px solid #eee;border-radius:12px'>
                 <h2 style='color:#7b1d8e'>Password Reset</h2>
                 <p>Hi " . htmlspecialchars($row['firstname']) . ", use this code to reset your password:</p>
                 <p style='font-size:32px;letter-spacing:8px;font-weight:bold;color:#e11d48'>$code</p>
                 <p style='font-size:12px;color:#888'>Valid for 15 minutes. If you didn't request this, ignore this email.</p></div>";
        sendMail($email, $subject, $html);
        jout(true, 'A 4-digit code has been sent to your email.');
    }

    /* ---------- FORGOT 2: verify code ---------- */
    if ($action === 'forgot_verify') {
        $code  = trim($_POST['code'] ?? '');
        $email = $_SESSION['fp_email'] ?? '';
        if ($email === '') jout(false, 'Session expired. Start again.');
        if (time() - ($_SESSION['fp_time'] ?? 0) > 900) jout(false, 'Code expired. Request a new one.');
        if (($_SESSION['fp_tries'] ?? 0) >= 5) jout(false, 'Too many attempts. Request a new code.');
        if (!preg_match('/^\d{4}$/', $code)) jout(false, 'Enter the 4-digit code.');

        $st = $conn->prepare("SELECT uid FROM users WHERE email=? AND token=? LIMIT 1");
        $st->bind_param("ss", $email, $code);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row) {
            $_SESSION['fp_tries'] = ($_SESSION['fp_tries'] ?? 0) + 1;
            jout(false, 'Incorrect code.');
        }
        $_SESSION['fp_verified'] = true;
        jout(true, 'Code verified. Enter your new password.');
    }

    /* ---------- FORGOT 3: new password + welcome mail ---------- */
    if ($action === 'forgot_reset') {
        $email = $_SESSION['fp_email'] ?? '';
        if ($email === '' || empty($_SESSION['fp_verified'])) jout(false, 'Verification required.');
        if (time() - ($_SESSION['fp_time'] ?? 0) > 900)      jout(false, 'Session expired. Start again.');

        $pass  = $_POST['pass'] ?? '';
        $pass2 = $_POST['pass2'] ?? '';
        if (strlen($pass) < 8) jout(false, 'Password must be at least 8 characters.');
        if ($pass !== $pass2)  jout(false, 'Passwords do not match.');

        $hash  = password_hash($pass, PASSWORD_DEFAULT);
        $token = newToken(); // invalidates the 4-digit code
        $st = $conn->prepare("UPDATE users SET password=?, token=? WHERE email=?");
        $st->bind_param("sss", $hash, $token, $email);
        $st->execute();
        $st->close();

        $st = $conn->prepare("SELECT firstname FROM users WHERE email=? LIMIT 1");
        $st->bind_param("s", $email);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        $st->close();

        $subject = "Welcome back to Velora";
        $html = "<div style='font-family:Arial,sans-serif;max-width:420px;margin:auto;padding:24px;border:1px solid #eee;border-radius:12px'>
                 <h2 style='color:#7b1d8e'>Welcome back, " . htmlspecialchars($u['firstname'] ?? '') . "!</h2>
                 <p>Your password was changed successfully. You can now sign in to Velora.</p></div>";
        sendMail($email, $subject, $html);

        unset($_SESSION['fp_email'], $_SESSION['fp_time'], $_SESSION['fp_tries'], $_SESSION['fp_verified']);
        jout(true, 'Password updated. Please sign in.');
    }

    jout(false, 'Invalid request.');
}
?>
<!doctype html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Velora - Sign In / Register</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="./src/output.css" rel="stylesheet">
    <style>
        .hero-gradient { background: linear-gradient(135deg, #2b1158 0%, #7b1d8e 35%, #e11d48 70%, #fb923c 100%); }
        .brand-button-gradient { background: linear-gradient(to right, #7b1d8e, #e11d48, #fb923c); background-size: 200% auto; transition: all .3s cubic-bezier(.4,0,.2,1); }
        .brand-button-gradient:hover { background-position: right center; box-shadow: 0 10px 20px -10px rgba(225,29,72,.5); }
        .brand-button-gradient:disabled { opacity: .6; cursor: not-allowed; }
        .glass-card { background: rgba(255,255,255,.85); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.5); }
        @keyframes float { 0%,100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-8px) rotate(1deg); } }
        .animate-float { animation: float 5s ease-in-out infinite; }
        .tab-content { transition: opacity .3s, transform .3s; }
        .tab-content.hidden { display: none; opacity: 0; transform: translateY(10px); }
        .tab-content.active { display: block; opacity: 1; transform: translateY(0); }
        .inp { width: 100%; padding: .65rem 2.5rem; font-size: .875rem; border-radius: .75rem; border: 1px solid #e5e7eb; background: rgba(249,250,251,.5); outline: none; transition: all .2s; }
        .inp:focus { background: #fff; border-color: #ec4899; box-shadow: 0 0 0 3px rgba(236,72,153,.15); }
        .lbl { display: block; font-size: .75rem; font-weight: 600; color: #374151; text-transform: uppercase; margin-bottom: .25rem; }
        .ico { position: absolute; top: 0; bottom: 0; left: 0; padding-left: .9rem; display: flex; align-items: center; color: #9ca3af; }
        .eye { position: absolute; top: 0; bottom: 0; right: 0; padding-right: .9rem; display: flex; align-items: center; color: #9ca3af; }
        .eye:hover { color: #4b5563; }
        .sbtn { display: flex; align-items: center; justify-content: center; padding: .6rem .75rem; border: 1px solid #e5e7eb; border-radius: .75rem; transition: background .2s; }
        .sbtn:hover { background: #f9fafb; }
        .otp { letter-spacing: .6em; text-align: center; font-size: 1.5rem; font-weight: 700; padding-left: 1rem; padding-right: 1rem; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans min-h-screen flex flex-col justify-between antialiased">
    <div id="toast-container" class="fixed top-5 right-5 z-50 flex flex-col gap-3 pointer-events-none"></div>

    <?php include __DIR__ . "/head.php"; ?>

    <main class="flex-grow flex items-center justify-center py-8 px-4 sm:px-6 lg:px-8 bg-gradient-to-b from-gray-50 via-purple-50/20 to-pink-50/30">
        <div class="max-w-5xl w-full bg-white rounded-3xl shadow-2xl overflow-hidden grid grid-cols-1 lg:grid-cols-12 border border-gray-100">

            <!-- Hero side -->
            <div class="lg:col-span-5 hero-gradient text-white p-8 lg:p-12 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute -top-16 -left-16 w-64 h-64 bg-pink-500/30 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-16 -right-16 w-64 h-64 bg-indigo-500/40 rounded-full blur-3xl pointer-events-none"></div>
                <div class="relative z-10">
                    <span class="inline-block px-3 py-1 bg-white/10 backdrop-blur-md rounded-full text-xs font-semibold tracking-wider uppercase mb-4 text-pink-200 border border-white/15">New Season Collection</span>
                    <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight leading-tight">Elevate Your Everyday Style.</h2>
                    <p class="mt-3 text-sm text-pink-100/90 leading-relaxed">Join the Velora fashion community to unlock custom recommendations, priority sale updates, and seamless order tracking.</p>
                </div>
                <div class="my-8 relative z-10 hidden sm:block">
                    <div class="glass-card rounded-2xl p-4 shadow-xl border border-white/30 animate-float text-gray-800">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-tr from-pink-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm"><i class="bi bi-bag-check"></i></div>
                            <div>
                                <p class="text-xs font-bold text-gray-900">Exclusive Member Perks</p>
                                <p class="text-[11px] text-gray-600">Get <span class="text-pink-600 font-bold">15% OFF</span> your first purchase!</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="relative z-10 space-y-3 pt-4 border-t border-white/15 text-xs text-pink-100">
                    <div class="flex items-center gap-2"><i class="bi bi-shield-check text-pink-300 text-base"></i><span>100% Encrypted &amp; Safe Checkout</span></div>
                    <div class="flex items-center gap-2"><i class="bi bi-truck text-pink-300 text-base"></i><span>Free Shipping on Orders Above $99</span></div>
                    <div class="flex items-center gap-2"><i class="bi bi-arrow-counterclockwise text-pink-300 text-base"></i><span>Hassle-Free 30-Day Express Returns</span></div>
                </div>
            </div>

            <!-- Form side -->
            <div class="lg:col-span-7 p-6 sm:p-10 flex flex-col justify-center">
                <div class="flex border-b border-gray-200 mb-8">
                    <button id="tab-signin-btn" onclick="switchTab('signin')" class="flex-1 py-3 text-center text-sm font-bold border-b-2 transition-all border-pink-600 text-pink-600"><i class="bi bi-box-arrow-in-right mr-1.5"></i> Sign In</button>
                    <button id="tab-signup-btn" onclick="switchTab('signup')" class="flex-1 py-3 text-center text-sm font-bold border-b-2 transition-all border-transparent text-gray-400 hover:text-gray-600"><i class="bi bi-person-plus mr-1.5"></i> Create Account</button>
                </div>

                <!-- SIGN IN -->
                <div id="signin-tab" class="tab-content active">
                    <div class="mb-6">
                        <h3 class="text-2xl font-bold text-gray-900">Welcome Back</h3>
                        <p class="text-xs text-gray-500 mt-1">Please enter your details to access your account.</p>
                    </div>
                    <form onsubmit="handleSignIn(event)" class="space-y-4">
                        <div>
                            <label class="lbl">Email or Phone Number</label>
                            <div class="relative">
                                <span class="ico"><i class="bi bi-envelope"></i></span>
                                <input type="text" id="signin-email" required placeholder="name@example.com" class="inp" autocomplete="username">
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="lbl" style="margin:0">Password</label>
                                <button type="button" onclick="openForgotModal()" class="text-xs text-pink-600 hover:text-pink-700 font-semibold">Forgot Password?</button>
                            </div>
                            <div class="relative">
                                <span class="ico"><i class="bi bi-lock"></i></span>
                                <input type="password" id="signin-password" required placeholder="••••••••" class="inp" autocomplete="current-password">
                                <button type="button" onclick="togglePasswordVisibility('signin-password','signin-eye-icon')" class="eye"><i id="signin-eye-icon" class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <button id="signin-btn" type="submit" class="w-full py-3 px-4 text-white font-bold text-sm rounded-xl brand-button-gradient shadow-md flex items-center justify-center gap-2 mt-2">
                            <span>Sign In To Velora</span><i class="bi bi-arrow-right"></i>
                        </button>
                    </form>
                    <div class="relative my-6 text-center">
                        <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-100"></div></div>
                        <span class="relative bg-white px-3 text-[11px] uppercase font-bold text-gray-400 tracking-wider">Or Continue With</span>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <button onclick="handleSocialAuth('Google')" class="sbtn"><i class="bi bi-google text-red-500"></i><span class="hidden sm:inline text-xs font-semibold text-gray-700 ml-2">Google</span></button>
                        <button onclick="handleSocialAuth('Apple')" class="sbtn"><i class="bi bi-apple text-gray-900"></i><span class="hidden sm:inline text-xs font-semibold text-gray-700 ml-2">Apple</span></button>
                        <button onclick="handleSocialAuth('Facebook')" class="sbtn"><i class="bi bi-facebook text-blue-600"></i><span class="hidden sm:inline text-xs font-semibold text-gray-700 ml-2">Facebook</span></button>
                    </div>
                </div>

                <!-- SIGN UP -->
                <div id="signup-tab" class="tab-content hidden">
                    <div class="mb-5">
                        <h3 class="text-2xl font-bold text-gray-900">Join Velora</h3>
                        <p class="text-xs text-gray-500 mt-1">Create an account to get access to exclusive drops and rewards.</p>
                    </div>
                    <form onsubmit="handleSignUp(event)" class="space-y-3.5">
                        <div>
                            <label class="lbl">Full Name</label>
                            <div class="relative">
                                <span class="ico"><i class="bi bi-person"></i></span>
                                <input type="text" id="signup-name" required placeholder="Jane Doe" class="inp" autocomplete="name">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="lbl">Email Address</label>
                                <div class="relative">
                                    <span class="ico"><i class="bi bi-envelope"></i></span>
                                    <input type="email" id="signup-email" required placeholder="jane@example.com" class="inp" autocomplete="email">
                                </div>
                            </div>
                            <div>
                                <label class="lbl">Phone Number</label>
                                <div class="relative">
                                    <span class="ico"><i class="bi bi-telephone"></i></span>
                                    <input type="tel" id="signup-phone" required placeholder="+234 800 000 0000" class="inp" autocomplete="tel">
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="lbl">Password</label>
                                <div class="relative">
                                    <span class="ico"><i class="bi bi-shield-lock"></i></span>
                                    <input type="password" id="signup-password" required placeholder="At least 8 chars" class="inp" autocomplete="new-password">
                                    <button type="button" onclick="togglePasswordVisibility('signup-password','signup-eye1')" class="eye"><i id="signup-eye1" class="bi bi-eye"></i></button>
                                </div>
                            </div>
                            <div>
                                <label class="lbl">Confirm Password</label>
                                <div class="relative">
                                    <span class="ico"><i class="bi bi-check2-circle"></i></span>
                                    <input type="password" id="signup-confirm-password" required placeholder="Repeat password" class="inp" autocomplete="new-password">
                                    <button type="button" onclick="togglePasswordVisibility('signup-confirm-password','signup-eye2')" class="eye"><i id="signup-eye2" class="bi bi-eye"></i></button>
                                </div>
                            </div>
                        </div>
                        <label class="flex items-start text-xs text-gray-600 cursor-pointer select-none pt-1">
                            <input type="checkbox" required class="mt-0.5 w-4 h-4 rounded border-gray-300 cursor-pointer">
                            <span class="ml-2">I agree to the <a href="#" class="text-pink-600 underline">Terms of Service</a> and <a href="#" class="text-pink-600 underline">Privacy Policy</a>.</span>
                        </label>
                        <button id="signup-btn" type="submit" class="w-full py-3 px-4 text-white font-bold text-sm rounded-xl brand-button-gradient shadow-md flex items-center justify-center gap-2 mt-2">
                            <span>Create Account &amp; Get 15% Off</span><i class="bi bi-stars"></i>
                        </button>
                    </form>
                    <div class="relative my-4 text-center">
                        <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-100"></div></div>
                        <span class="relative bg-white px-3 text-[10px] uppercase font-bold text-gray-400 tracking-wider">Fast Registration</span>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <button onclick="handleSocialAuth('Google')" class="sbtn"><i class="bi bi-google text-red-500 text-xs"></i><span class="hidden sm:inline text-xs font-semibold text-gray-700 ml-1.5">Google</span></button>
                        <button onclick="handleSocialAuth('Apple')" class="sbtn"><i class="bi bi-apple text-gray-900 text-xs"></i><span class="hidden sm:inline text-xs font-semibold text-gray-700 ml-1.5">Apple</span></button>
                        <button onclick="handleSocialAuth('Facebook')" class="sbtn"><i class="bi bi-facebook text-blue-600 text-xs"></i><span class="hidden sm:inline text-xs font-semibold text-gray-700 ml-1.5">Facebook</span></button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- FORGOT PASSWORD MODAL (3 steps) -->
    <div id="forgot-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/60 backdrop-blur-sm hidden">
        <div class="bg-white w-full max-w-md rounded-2xl p-6 sm:p-8 shadow-2xl border border-gray-100 relative transition-all scale-95 opacity-0" id="forgot-modal-content">
            <button onclick="closeForgotModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100"><i class="bi bi-x-lg text-sm"></i></button>
            <div class="w-12 h-12 rounded-full bg-pink-100 text-pink-600 flex items-center justify-center text-xl mb-4"><i class="bi bi-key"></i></div>

            <!-- Step 1: email -->
            <div id="fp-step1">
                <h3 class="text-xl font-bold text-gray-900">Reset Your Password</h3>
                <p class="text-xs text-gray-500 mt-1 mb-6">Enter your account email and we'll send a 4-digit code.</p>
                <form onsubmit="fpSend(event)" class="space-y-4">
                    <div>
                        <label class="lbl">Email Address</label>
                        <div class="relative"><span class="ico"><i class="bi bi-envelope"></i></span>
                            <input type="email" id="forgot-email" required placeholder="your.email@domain.com" class="inp"></div>
                    </div>
                    <div class="flex items-center gap-3 pt-2">
                        <button type="button" onclick="closeForgotModal()" class="flex-1 py-2.5 text-xs font-bold text-gray-600 border border-gray-200 rounded-xl hover:bg-gray-50">Cancel</button>
                        <button id="fp-send-btn" type="submit" class="flex-1 py-2.5 text-xs font-bold text-white rounded-xl brand-button-gradient">Send Code</button>
                    </div>
                </form>
            </div>

            <!-- Step 2: code -->
            <div id="fp-step2" class="hidden">
                <h3 class="text-xl font-bold text-gray-900">Enter Code</h3>
                <p class="text-xs text-gray-500 mt-1 mb-6">We sent a 4-digit code to <b id="fp-email-label"></b>.</p>
                <form onsubmit="fpVerify(event)" class="space-y-4">
                    <input type="text" id="fp-code" inputmode="numeric" maxlength="4" required placeholder="••••" class="inp otp">
                    <button id="fp-verify-btn" type="submit" class="w-full py-2.5 text-xs font-bold text-white rounded-xl brand-button-gradient">Verify Code</button>
                    <button type="button" onclick="fpStep(1)" class="w-full text-xs text-pink-600 font-semibold">Use a different email / resend</button>
                </form>
            </div>

            <!-- Step 3: new password -->
            <div id="fp-step3" class="hidden">
                <h3 class="text-xl font-bold text-gray-900">New Password</h3>
                <p class="text-xs text-gray-500 mt-1 mb-6">Choose a new password (at least 8 characters).</p>
                <form onsubmit="fpReset(event)" class="space-y-4">
                    <div class="relative"><span class="ico"><i class="bi bi-lock"></i></span>
                        <input type="password" id="fp-pass" required minlength="8" placeholder="New password" class="inp" autocomplete="new-password">
                        <button type="button" onclick="togglePasswordVisibility('fp-pass','fp-eye')" class="eye"><i id="fp-eye" class="bi bi-eye"></i></button></div>
                    <div class="relative"><span class="ico"><i class="bi bi-check2-circle"></i></span>
                        <input type="password" id="fp-pass2" required minlength="8" placeholder="Confirm new password" class="inp" autocomplete="new-password"></div>
                    <button id="fp-reset-btn" type="submit" class="w-full py-2.5 text-xs font-bold text-white rounded-xl brand-button-gradient">Update Password</button>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . "/footer.php"; ?>

    <script>
        /* ---------- Preloader (safe if element missing) ---------- */
        window.addEventListener('load', () => {
            const pre = document.getElementById('preloader');
            if (!pre) return;
            setTimeout(() => { pre.classList.add('opacity-0'); setTimeout(() => pre.style.display = 'none', 500); }, 600);
        });

        /* ---------- Server call helper ---------- */
        async function api(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            try {
                const r = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
                return await r.json();
            } catch (e) {
                return { ok: false, msg: 'Network or server error. Try again.' };
            }
        }
        function busy(id, on) { const b = document.getElementById(id); if (b) b.disabled = on; }

        /* ---------- Tabs ---------- */
        const ON  = "flex-1 py-3 text-center text-sm font-bold border-b-2 transition-all border-pink-600 text-pink-600";
        const OFF = "flex-1 py-3 text-center text-sm font-bold border-b-2 transition-all border-transparent text-gray-400 hover:text-gray-600";
        function switchTab(tab) {
            const a = document.getElementById('signin-tab'), b = document.getElementById('signup-tab');
            const show = tab === 'signin' ? a : b, hide = tab === 'signin' ? b : a;
            hide.classList.remove('active'); hide.classList.add('hidden');
            show.classList.remove('hidden'); setTimeout(() => show.classList.add('active'), 50);
            document.getElementById('tab-signin-btn').className = tab === 'signin' ? ON : OFF;
            document.getElementById('tab-signup-btn').className = tab === 'signup' ? ON : OFF;
        }

        function togglePasswordVisibility(inputId, iconId) {
            const i = document.getElementById(inputId), ic = document.getElementById(iconId);
            const show = i.type === 'password';
            i.type = show ? 'text' : 'password';
            ic.classList.toggle('bi-eye', !show);
            ic.classList.toggle('bi-eye-slash', show);
        }

        /* ---------- Sign in ---------- */
        async function handleSignIn(e) {
            e.preventDefault();
            const user = document.getElementById('signin-email').value.trim();
            const pass = document.getElementById('signin-password').value;
            if (!user || !pass) return showToast('Enter your email/phone and password.', 'error');
            busy('signin-btn', true);
            const r = await api({ action: 'signin', user, pass });
            if (r.ok) { showToast(r.msg, 'success'); setTimeout(() => location.href = r.redirect, 600); }
            else { showToast(r.msg, 'error'); busy('signin-btn', false); }
        }

        /* ---------- Sign up ---------- */
        async function handleSignUp(e) {
            e.preventDefault();
            const name  = document.getElementById('signup-name').value.trim().replace(/\s+/g, ' ');
            const email = document.getElementById('signup-email').value.trim();
            const phone = document.getElementById('signup-phone').value.trim();
            const pass  = document.getElementById('signup-password').value;
            const pass2 = document.getElementById('signup-confirm-password').value;

            if (name.split(' ').length < 2)               return showToast('Enter your first and last name.', 'error');
            if (!/^\+?\d{7,15}$/.test(phone.replace(/[^\d+]/g, ''))) return showToast('Enter a valid phone number.', 'error');
            if (pass.length < 8)                          return showToast('Password must be at least 8 characters.', 'error');
            if (pass !== pass2)                           return showToast('Passwords do not match.', 'error');

            busy('signup-btn', true);
            const r = await api({ action: 'signup', name, email, phone, pass, pass2 });
            if (r.ok) { showToast(r.msg, 'success'); setTimeout(() => location.href = r.redirect, 600); }
            else { showToast(r.msg, 'error'); busy('signup-btn', false); }
        }

        /* ---------- Forgot password ---------- */
        function openForgotModal() {
            const m = document.getElementById('forgot-modal'), c = document.getElementById('forgot-modal-content');
            fpStep(1);
            m.classList.remove('hidden');
            setTimeout(() => { c.classList.remove('scale-95', 'opacity-0'); c.classList.add('scale-100', 'opacity-100'); }, 10);
        }
        function closeForgotModal() {
            const m = document.getElementById('forgot-modal'), c = document.getElementById('forgot-modal-content');
            c.classList.remove('scale-100', 'opacity-100'); c.classList.add('scale-95', 'opacity-0');
            setTimeout(() => m.classList.add('hidden'), 200);
        }
        function fpStep(n) {
            [1, 2, 3].forEach(i => document.getElementById('fp-step' + i).classList.toggle('hidden', i !== n));
        }

        async function fpSend(e) {
            e.preventDefault();
            const email = document.getElementById('forgot-email').value.trim();
            busy('fp-send-btn', true);
            const r = await api({ action: 'forgot_send', email });
            busy('fp-send-btn', false);
            if (r.ok) {
                document.getElementById('fp-email-label').textContent = email;
                document.getElementById('fp-code').value = '';
                fpStep(2); showToast(r.msg, 'info');
            } else showToast(r.msg, 'error');
        }
        async function fpVerify(e) {
            e.preventDefault();
            const code = document.getElementById('fp-code').value.trim();
            if (!/^\d{4}$/.test(code)) return showToast('Enter the 4-digit code.', 'error');
            busy('fp-verify-btn', true);
            const r = await api({ action: 'forgot_verify', code });
            busy('fp-verify-btn', false);
            if (r.ok) { fpStep(3); showToast(r.msg, 'success'); } else showToast(r.msg, 'error');
        }
        async function fpReset(e) {
            e.preventDefault();
            const pass = document.getElementById('fp-pass').value, pass2 = document.getElementById('fp-pass2').value;
            if (pass.length < 8) return showToast('Password must be at least 8 characters.', 'error');
            if (pass !== pass2)  return showToast('Passwords do not match.', 'error');
            busy('fp-reset-btn', true);
            const r = await api({ action: 'forgot_reset', pass, pass2 });
            busy('fp-reset-btn', false);
            if (r.ok) { closeForgotModal(); switchTab('signin'); showToast(r.msg, 'success'); }
            else showToast(r.msg, 'error');
        }

        function handleSocialAuth(p) { showToast(`Connecting with ${p}...`, 'info'); }

        /* ---------- Toast (text escaped) ---------- */
        function showToast(message, type = 'info') {
            const box = document.getElementById('toast-container'), t = document.createElement('div');
            const map = { success: ['bg-emerald-600', 'bi-check-circle'], error: ['bg-rose-600', 'bi-exclamation-triangle'], info: ['bg-purple-700', 'bi-bell'] };
            const [bg, ic] = map[type] || ['bg-gray-900', 'bi-info-circle'];
            t.className = `${bg} text-white text-xs font-semibold px-4 py-3 rounded-xl shadow-xl flex items-center gap-2.5 transition-all duration-300 opacity-0 translate-y-2 pointer-events-auto max-w-xs`;
            const i = document.createElement('i'); i.className = `bi ${ic} text-base`;
            const s = document.createElement('span'); s.className = 'flex-1'; s.textContent = message;
            t.append(i, s); box.appendChild(t);
            setTimeout(() => { t.classList.remove('opacity-0', 'translate-y-2'); t.classList.add('opacity-100', 'translate-y-0'); }, 10);
            setTimeout(() => { t.classList.add('opacity-0', 'translate-y-2'); setTimeout(() => t.remove(), 300); }, 4000);
        }
    </script>
</body>
</html>