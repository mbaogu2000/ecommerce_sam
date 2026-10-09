<?php
include __DIR__ . "/../main/config.php";
/* ---- local helpers (this page is self-contained) ---- */
if (session_status() === PHP_SESSION_NONE) @session_start();
if (!defined('SHIPPING_FEE'))     define('SHIPPING_FEE', 99);      // charged below the free-shipping minimum
if (!defined('FREE_SHIP_MIN'))    define('FREE_SHIP_MIN', 999);    // "Free shipping above ₹999"
if (!defined('PREPAID_DISCOUNT')) define('PREPAID_DISCOUNT', 10);  // "Extra 10% off on prepaid orders"
if (!function_exists('e')) { function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($n) { return '₹' . number_format((float)$n, 0); } }
if (!function_exists('rows')) {
    function rows($conn, $sql, $types = '', $params = []) {
        $s = $conn->prepare($sql);
        if (!$s) return [];
        if ($params) $s->bind_param($types, ...$params);
        $s->execute();
        return $s->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
if (!function_exists('run')) {
    // INSERT / UPDATE / DELETE. Returns ['ok'=>bool,'rows'=>int,'errno'=>int]
    function run($conn, $sql, $types = '', $params = []) {
        try {
            $s = $conn->prepare($sql);
            if (!$s) return ['ok' => false, 'rows' => 0, 'errno' => $conn->errno];
            if ($params) $s->bind_param($types, ...$params);
            $ok = $s->execute();
            return ['ok' => $ok, 'rows' => $s->affected_rows, 'errno' => $s->errno];
        } catch (Throwable $t) {
            return ['ok' => false, 'rows' => 0, 'errno' => (int)$t->getCode()];
        }
    }
}
if (!function_exists('first_image')) {
    function first_image($json) {
        $fb = 'https://placehold.co/300x400/f3e8ff/7b1d8e?text=No+Image';
        $s = json_decode($json ?? '', true);
        return is_array($s) ? ($s['image'] ?? ($s['images'][0] ?? $fb)) : $fb;
    }
}
// Expects your login to set $_SESSION['uid']
if (!function_exists('current_uid')) { function current_uid() { return $_SESSION['suid'] ?? null; } }
if (!function_exists('require_login')) {
    function require_login() {
        $uid = current_uid();
        if (!$uid) { header('Location: /login'); exit; }
        return $uid;
    }
}
if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return $_SESSION['csrf'];
    }
}
if (!function_exists('csrf_ok')) {
    function csrf_ok() { return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']); }
}
if (!function_exists('cart_count')) {
    function cart_count($conn, $uid) {
        return (int)(rows($conn, "SELECT COALESCE(SUM(quantity),0) c FROM cart_items WHERE uid = ?", 's', [$uid])[0]['c'] ?? 0);
    }
}

/* =====================================================================
   members  -  My Account
   members?tab=overview | orders | wishlist | reviews | profile | security
   Writes only: users.firstname/lastname/phone/password (own row) and
   wishlist rows (remove). No schema changes.
   ===================================================================== */

$uid = require_login();
$user = rows($conn, "SELECT uid, username, firstname, lastname, email, phone, email_is_verified, phone_is_verified, status, is_admin, is_vendor, created_at
                       FROM users WHERE uid = ?", 's', [$uid])[0] ?? null;
if (!$user) { session_destroy(); header('Location: /login'); exit; }

$tabs = [
  'overview' => ['Overview', 'bi-grid'],
  'orders'   => ['My Orders', 'bi-box-seam'],
  'wishlist' => ['Wishlist', 'bi-heart'],
  'reviews'  => ['My Reviews', 'bi-star'],
  'profile'  => ['Profile', 'bi-person'],
  'security' => ['Password', 'bi-shield-lock'],
];
$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$tab])) $tab = 'overview';

/* ---------------- POST actions (PRG) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flash = ['err', 'Something went wrong. Please try again.']; $back = $tab;

    if (!csrf_ok()) {
        $flash = ['err', 'Session expired. Please try again.'];
    } elseif ($action === 'profile') {
        $back = 'profile';
        $fn = trim($_POST['firstname'] ?? ''); $ln = trim($_POST['lastname'] ?? ''); $ph = trim($_POST['phone'] ?? '');
        if ($fn === '' || $ln === '' || mb_strlen($fn) > 50 || mb_strlen($ln) > 50) $flash = ['err', 'Please enter your first and last name.'];
        elseif ($ph !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $ph)) $flash = ['err', 'Please enter a valid phone number.'];
        else {
            $phoneChanged = ($ph !== (string)$user['phone']);
            $r = run($conn, "UPDATE users SET firstname = ?, lastname = ?, phone = ?" . ($phoneChanged ? ", phone_is_verified = 0" : "") . " WHERE uid = ?",
                     'ssss', [$fn, $ln, $ph === '' ? null : $ph, $uid]);
            $flash = $r['ok'] ? ['ok', 'Profile updated.'] : ($r['errno'] == 1062 ? ['err', 'That phone number is already used by another account.'] : $flash);
        }
    } elseif ($action === 'password') {
        $back = 'security';
        $cur = rows($conn, "SELECT password FROM users WHERE uid = ?", 's', [$uid])[0]['password'] ?? '';
        $old = $_POST['old'] ?? ''; $new = $_POST['new'] ?? ''; $conf = $_POST['confirm'] ?? '';
        if (!password_verify($old, $cur)) $flash = ['err', 'Your current password is incorrect.'];
        elseif (strlen($new) < 8) $flash = ['err', 'New password must be at least 8 characters.'];
        elseif ($new !== $conf) $flash = ['err', 'New passwords do not match.'];
        else {
            $r = run($conn, "UPDATE users SET password = ? WHERE uid = ?", 'ss', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            $flash = $r['ok'] ? ['ok', 'Password changed.'] : $flash;
        }
    } elseif ($action === 'remove_wishlist') {
        $back = 'wishlist';
        run($conn, "DELETE FROM wishlist WHERE uid = ? AND product_id = ?", 'si', [$uid, (int)($_POST['product_id'] ?? 0)]);
        $flash = ['ok', 'Removed from wishlist.'];
    }
    $_SESSION['flash'] = $flash;
    header('Location: members?tab=' . $back); exit;
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ---------------- data ---------------- */
$one = fn($sql, $t = 's', $p = null) => (int)(rows($conn, $sql, $t, $p ?? [$uid])[0]['c'] ?? 0);
$nWish = $one("SELECT COUNT(*) c FROM wishlist WHERE uid = ?");
$nRev  = $one("SELECT COUNT(*) c FROM products_reviews WHERE uid = ? AND parent_id IS NULL");
$nCart = cart_count($conn, $uid);
$nProd = $user['is_vendor'] ? $one("SELECT COUNT(*) c FROM products WHERE uid = ?") : 0;

$per = 8; $pg = max(1, (int)($_GET['page'] ?? 1)); $list = []; $pages = 1;
if ($tab === 'wishlist' || $tab === 'reviews') {
    $total = $tab === 'wishlist' ? $nWish : $nRev;
    $pages = max(1, (int)ceil($total / $per)); $pg = min($pg, $pages); $off = ($pg - 1) * $per;
    $list = $tab === 'wishlist'
      ? rows($conn, "SELECT p.id, p.slug, p.product_name, p.price, p.off_price, p.final_price, p.instock, p.specification
                       FROM wishlist w JOIN products p ON p.id = w.product_id WHERE w.uid = ? ORDER BY w.created_at DESC LIMIT ? OFFSET ?", 'sii', [$uid, $per, $off])
      : rows($conn, "SELECT r.star, r.msg, r.created_at, p.slug, p.product_name, p.specification
                       FROM products_reviews r JOIN products p ON p.id = r.product_id
                      WHERE r.uid = ? AND r.parent_id IS NULL ORDER BY r.created_at DESC LIMIT ? OFFSET ?", 'sii', [$uid, $per, $off]);
}
$recentWish = $tab === 'overview' ? rows($conn, "SELECT p.slug, p.product_name, p.final_price, p.specification FROM wishlist w JOIN products p ON p.id = w.product_id WHERE w.uid = ? ORDER BY w.created_at DESC LIMIT 4", 's', [$uid]) : [];

$initial = strtoupper(mb_substr($user['firstname'], 0, 1));
$btn = 'bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:from-amber-600 hover:to-purple-700 shadow-lg shadow-pink-500/30';
$input = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-pink-500 focus:ring-1 focus:ring-pink-200';
function pager($tab, $pg, $pages) {
    if ($pages < 2) return;
    echo '<nav class="flex items-center justify-center gap-1.5 mt-6">';
    for ($i = 1; $i <= $pages; $i++)
        echo '<a href="members?tab=' . $tab . '&page=' . $i . '" class="min-w-[2.25rem] h-9 inline-flex items-center justify-center rounded-lg text-xs font-bold border transition-colors '
           . ($i === $pg ? 'bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 border-transparent text-white shadow-md' : 'bg-white border-gray-200 text-gray-700 hover:border-pink-400 hover:text-pink-600') . '">' . $i . '</a>';
    echo '</nav>';
}
function stars($n) { $h = '<span class="inline-flex gap-0.5 text-amber-400 text-xs">'; for ($i = 1; $i <= 5; $i++) $h .= '<i class="bi ' . ($i <= $n ? 'bi-star-fill' : 'bi-star') . '"></i>'; return $h . '</span>'; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Account | <?= e($company['title']) ?></title>
  <link href="./src/output.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<?php include __DIR__ . "/../main/head.php"; ?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
  <nav class="text-xs text-gray-500 mb-3 flex items-center gap-1.5">
    <a href="/" class="hover:text-pink-600">Home</a><i class="bi bi-chevron-right text-[9px]"></i><span class="font-semibold text-gray-800">My Account</span>
  </nav>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

    <!-- Sidebar -->
    <aside class="lg:col-span-3">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden lg:sticky lg:top-4">
        <div class="bg-gradient-to-r from-indigo-900 via-purple-700 to-pink-500 text-white p-5 flex items-center gap-3">
          <span class="w-12 h-12 rounded-full bg-white/20 border border-white/30 backdrop-blur-md flex items-center justify-center text-lg font-extrabold"><?= e($initial) ?></span>
          <div class="min-w-0">
            <p class="font-bold truncate"><?= e($user['firstname'] . ' ' . $user['lastname']) ?></p>
            <p class="text-[11px] text-white/80 truncate">@<?= e($user['username']) ?></p>
            <?php if ($user['is_vendor']): ?><span class="inline-block mt-1 text-[9px] font-extrabold uppercase bg-white/20 border border-white/30 rounded-full px-2 py-0.5">Vendor</span><?php endif; ?>
          </div>
        </div>
        <ul class="p-2 flex lg:block overflow-x-auto">
          <?php foreach ($tabs as $k => [$lbl, $ico]): ?>
            <li class="shrink-0"><a href="members?tab=<?= $k ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-semibold whitespace-nowrap transition-colors <?= $tab === $k ? 'bg-pink-50 text-pink-600' : 'text-gray-700 hover:bg-gray-50 hover:text-pink-600' ?>"><i class="bi <?= $ico ?>"></i> <?= $lbl ?></a></li>
          <?php endforeach; ?>
          <li class="shrink-0 lg:border-t lg:border-gray-100 lg:mt-1 lg:pt-1"><a href="/logout" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-semibold text-gray-500 hover:text-red-600 whitespace-nowrap"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
        </ul>
      </div>
    </aside>

    <!-- Content -->
    <section class="lg:col-span-9 space-y-5">
      <?php if ($flash): ?>
        <div class="rounded-xl border text-sm font-semibold px-4 py-3 <?= $flash[0] === 'ok' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700' ?>"><i class="bi <?= $flash[0] === 'ok' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i> <?= e($flash[1]) ?></div>
      <?php endif; ?>

      <?php if ($tab === 'overview'): ?>
        <?php if (!$user['email_is_verified'] || $user['status'] === 'pending'): ?>
          <div class="rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3"><i class="bi bi-envelope-exclamation"></i> Your email is not verified yet. Please verify <span class="font-bold"><?= e($user['email']) ?></span> to secure your account.</div>
        <?php endif; ?>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <?php foreach ([['bi-bag', 'In Cart', $nCart, 'cart', 'from-orange-500 via-pink-500 to-purple-600'],
                          ['bi-heart', 'Wishlist', $nWish, 'members?tab=wishlist', 'from-pink-500 via-rose-500 to-purple-600'],
                          ['bi-star', 'Reviews', $nRev, 'members?tab=reviews', 'from-purple-600 via-indigo-500 to-cyan-500'],
                          $user['is_vendor'] ? ['bi-shop', 'My Products', $nProd, 'category', 'from-indigo-700 via-blue-600 to-cyan-500'] : ['bi-calendar-check', 'Member Since', date('M Y', strtotime($user['created_at'])), '#', 'from-indigo-700 via-blue-600 to-cyan-500']] as $s): ?>
            <a href="<?= $s[3] ?>" class="rounded-2xl p-4 bg-gradient-to-r <?= $s[4] ?> text-white shadow-md hover:shadow-xl hover:-translate-y-0.5 transition-all">
              <i class="bi <?= $s[0] ?> text-xl"></i>
              <p class="text-2xl font-extrabold leading-none mt-2"><?= e($s[2]) ?></p>
              <p class="text-[11px] font-bold uppercase tracking-wider text-white/80 mt-1"><?= $s[1] ?></p>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-900 mb-3">Account details</h2>
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
            <div><dt class="text-xs text-gray-500">Name</dt><dd class="font-semibold"><?= e($user['firstname'] . ' ' . $user['lastname']) ?></dd></div>
            <div><dt class="text-xs text-gray-500">Email</dt><dd class="font-semibold"><?= e($user['email']) ?> <?= $user['email_is_verified'] ? '<i class="bi bi-patch-check-fill text-green-600" title="Verified"></i>' : '' ?></dd></div>
            <div><dt class="text-xs text-gray-500">Phone</dt><dd class="font-semibold"><?= $user['phone'] ? e($user['phone']) : '<span class="text-gray-400">Not added</span>' ?> <?= $user['phone_is_verified'] ? '<i class="bi bi-patch-check-fill text-green-600"></i>' : '' ?></dd></div>
            <div><dt class="text-xs text-gray-500">Member since</dt><dd class="font-semibold"><?= e(date('d M Y', strtotime($user['created_at']))) ?></dd></div>
          </dl>
          <a href="members?tab=profile" class="inline-block mt-4 text-xs font-bold uppercase tracking-wider text-pink-600 hover:underline">Edit profile <i class="bi bi-arrow-right"></i></a>
        </div>

        <?php if ($recentWish): ?>
        <div>
          <div class="flex items-center justify-between mb-3"><h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-900">From your wishlist</h2>
            <a href="members?tab=wishlist" class="text-xs font-semibold text-gray-700 hover:text-pink-600 uppercase tracking-wider">View all <i class="bi bi-arrow-right"></i></a></div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <?php foreach ($recentWish as $x): ?>
              <a href="product?slug=<?= e($x['slug']) ?>" class="bg-white rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow p-2">
                <div class="rounded-lg overflow-hidden bg-rose-50/40 aspect-[3/4]"><img src="<?= e(first_image($x['specification'])) ?>" alt="" class="w-full h-full object-cover object-top"></div>
                <p class="mt-2 text-[11px] font-medium text-gray-700 truncate text-center"><?= e($x['product_name']) ?></p>
                <p class="text-xs font-bold text-gray-900 text-center"><?= money($x['final_price']) ?></p>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      <?php elseif ($tab === 'orders'): ?>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm py-16 px-6 text-center">
          <div class="mx-auto w-16 h-16 rounded-full bg-gradient-to-tr from-purple-100 to-pink-100 flex items-center justify-center text-pink-500 mb-4"><i class="bi bi-box-seam text-2xl"></i></div>
          <h2 class="text-lg font-bold text-gray-900">Order history</h2>
          <p class="text-sm text-gray-500 mt-1 max-w-sm mx-auto">Your orders will be listed here once order tracking is switched on for this store.</p>
          <a href="category" class="inline-block mt-5 px-8 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-white <?= $btn ?>">Continue shopping</a>
        </div>

      <?php elseif ($tab === 'wishlist'): ?>
        <h2 class="text-lg font-bold uppercase tracking-tight text-gray-900">My Wishlist <span class="text-sm font-normal text-gray-400 normal-case">(<?= $nWish ?>)</span></h2>
        <?php if (!$list): ?>
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm py-14 text-center text-sm text-gray-500">Nothing saved yet. Tap the heart on any product to save it here.</div>
        <?php else: ?>
          <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3 sm:gap-4">
            <?php foreach ($list as $x): $out = (int)$x['instock'] <= 0; ?>
            <div class="bg-white rounded-xl overflow-hidden border border-gray-100 shadow-sm hover:shadow-md transition-shadow p-2 flex flex-col justify-between">
              <div class="relative rounded-lg overflow-hidden bg-pink-50/50 aspect-[3/4]">
                <?php if ($x['off_price'] > 0): ?><span class="absolute top-2 left-2 z-10 bg-pink-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded">-<?= (int)$x['off_price'] ?>%</span><?php endif; ?>
                <form method="post" class="absolute top-2 right-2 z-10"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_wishlist"><input type="hidden" name="product_id" value="<?= (int)$x['id'] ?>">
                  <button title="Remove" class="w-7 h-7 bg-white/90 rounded-full flex items-center justify-center text-pink-600 hover:bg-pink-600 hover:text-white transition-colors"><i class="bi bi-x-lg text-xs"></i></button></form>
                <a href="product?slug=<?= e($x['slug']) ?>"><img src="<?= e(first_image($x['specification'])) ?>" alt="<?= e($x['product_name']) ?>" loading="lazy" class="w-full h-full object-cover object-top <?= $out ? 'opacity-50' : '' ?>"></a>
              </div>
              <div class="mt-2">
                <a href="product?slug=<?= e($x['slug']) ?>" class="block text-[12px] font-semibold text-gray-700 hover:text-pink-600 truncate"><?= e($x['product_name']) ?></a>
                <div class="flex items-center gap-1.5 mt-1"><span class="text-sm font-bold text-gray-900"><?= money($x['final_price']) ?></span><?php if ($x['off_price'] > 0): ?><span class="text-[11px] text-gray-400 line-through"><?= money($x['price']) ?></span><?php endif; ?></div>
                <button <?= $out ? 'disabled' : '' ?> onclick="addToCart(<?= (int)$x['id'] ?>, this)" class="w-full mt-3 py-1.5 border rounded-md text-[10px] font-bold uppercase transition-colors <?= $out ? 'border-gray-200 text-gray-400 cursor-not-allowed' : 'border-pink-500 text-pink-600 hover:bg-pink-600 hover:text-white' ?>"><?= $out ? 'Out of stock' : 'Add to Cart' ?></button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php pager('wishlist', $pg, $pages); ?>
        <?php endif; ?>

      <?php elseif ($tab === 'reviews'): ?>
        <h2 class="text-lg font-bold uppercase tracking-tight text-gray-900">My Reviews <span class="text-sm font-normal text-gray-400 normal-case">(<?= $nRev ?>)</span></h2>
        <?php if (!$list): ?>
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm py-14 text-center text-sm text-gray-500">You haven't reviewed any products yet.</div>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach ($list as $r): ?>
            <article class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex gap-4">
              <img src="<?= e(first_image($r['specification'])) ?>" alt="" class="w-16 h-20 rounded-lg object-cover object-top bg-pink-50">
              <div class="flex-1 min-w-0">
                <a href="product?slug=<?= e($r['slug']) ?>#reviews" class="text-sm font-bold text-gray-800 hover:text-pink-600 truncate block"><?= e($r['product_name']) ?></a>
                <div class="flex items-center gap-2 mt-1"><?= stars((int)$r['star']) ?><span class="text-[11px] text-gray-400"><?= e(date('d M Y', strtotime($r['created_at']))) ?></span></div>
                <p class="text-sm text-gray-600 mt-1.5"><?= nl2br(e($r['msg'])) ?></p>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php pager('reviews', $pg, $pages); ?>
        <?php endif; ?>

      <?php elseif ($tab === 'profile'): ?>
        <form method="post" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6 space-y-4 max-w-2xl">
          <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-900">Edit Profile</h2>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block"><span class="text-xs font-bold text-gray-600">First name</span><input name="firstname" required maxlength="50" value="<?= e($user['firstname']) ?>" class="<?= $input ?> mt-1"></label>
            <label class="block"><span class="text-xs font-bold text-gray-600">Last name</span><input name="lastname" required maxlength="50" value="<?= e($user['lastname']) ?>" class="<?= $input ?> mt-1"></label>
            <label class="block"><span class="text-xs font-bold text-gray-600">Username</span><input value="<?= e($user['username']) ?>" disabled class="<?= $input ?> mt-1 bg-gray-50 text-gray-500"></label>
            <label class="block"><span class="text-xs font-bold text-gray-600">Email</span><input value="<?= e($user['email']) ?>" disabled class="<?= $input ?> mt-1 bg-gray-50 text-gray-500"></label>
            <label class="block sm:col-span-2"><span class="text-xs font-bold text-gray-600">Phone</span><input name="phone" inputmode="tel" value="<?= e($user['phone']) ?>" class="<?= $input ?> mt-1"></label>
          </div>
          <button class="px-8 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-white <?= $btn ?>">Save changes</button>
        </form>

      <?php elseif ($tab === 'security'): ?>
        <form method="post" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6 space-y-4 max-w-md">
          <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-900">Change Password</h2>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password">
          <label class="block"><span class="text-xs font-bold text-gray-600">Current password</span><input type="password" name="old" required autocomplete="current-password" class="<?= $input ?> mt-1"></label>
          <label class="block"><span class="text-xs font-bold text-gray-600">New password (min 8 characters)</span><input type="password" name="new" required minlength="8" autocomplete="new-password" class="<?= $input ?> mt-1"></label>
          <label class="block"><span class="text-xs font-bold text-gray-600">Confirm new password</span><input type="password" name="confirm" required minlength="8" autocomplete="new-password" class="<?= $input ?> mt-1"></label>
          <button class="px-8 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-white <?= $btn ?>">Update password</button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</main>

<?php include __DIR__ . "/../main/footer.php"; ?>
<script>
  function addToCart(pid, btn) {
    fetch('cart', { method: 'POST', body: new URLSearchParams({ product_id: pid, quantity: 1 }) })
      .then(r => r.json())
      .then(d => {
        if (d.login) { location.href = '/login'; return; }
        btn.textContent = d.ok ? 'Added ✓' : (d.message || 'Unavailable');
      });
  }
</script>
</body>
</html>