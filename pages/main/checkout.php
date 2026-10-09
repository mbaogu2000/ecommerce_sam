<?php
include __DIR__ . "/config.php";
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
if (!function_exists('first_image')) {
    function first_image($json) {
        $fb = 'https://placehold.co/300x400/f3e8ff/7b1d8e?text=No+Image';
        $s = json_decode($json ?? '', true);
        return is_array($s) ? ($s['image'] ?? ($s['images'][0] ?? $fb)) : $fb;
    }
}
// Expects your login to set $_SESSION['uid']
if (!function_exists('current_uid')) { function current_uid() { return $_SESSION['uid'] ?? null; } }
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
if (!function_exists('cart_items')) {
    function cart_items($conn, $uid) {
        $items = rows($conn,
            "SELECT ci.quantity, p.id AS pid, p.slug, p.product_name, p.price, p.off_price, p.final_price, p.instock, p.specification
               FROM cart_items ci JOIN products p ON p.id = ci.product_id
              WHERE ci.uid = ? ORDER BY ci.updated_at DESC, ci.id DESC", 's', [$uid]);
        foreach ($items as &$i) {
            $i['quantity'] = (int)$i['quantity'];
            $i['available'] = ((int)$i['instock'] >= $i['quantity'] && (int)$i['instock'] > 0);
            $i['line_total'] = $i['quantity'] * (float)$i['final_price'];
            $i['line_mrp']   = $i['quantity'] * (float)$i['price'];
        }
        return $items;
    }
}
if (!function_exists('cart_totals')) {
    function cart_totals($items, $prepaid = false) {
        $count = 0; $mrp = 0; $sell = 0;
        foreach ($items as $i) { $count += $i['quantity']; $mrp += $i['line_mrp']; $sell += $i['line_total']; }
        $shipping = ($items && $sell < FREE_SHIP_MIN) ? SHIPPING_FEE : 0;
        $prepaidOff = $prepaid ? round($sell * PREPAID_DISCOUNT / 100, 2) : 0;
        return [
            'count' => $count, 'mrp' => $mrp, 'sell' => $sell, 'savings' => $mrp - $sell,
            'shipping' => $shipping, 'prepaid_off' => $prepaidOff,
            'total' => $sell + $shipping - $prepaidOff,
            'free_left' => max(0, FREE_SHIP_MIN - $sell),
        ];
    }
}

/* =====================================================================
   checkout.php
   Shows address, payment method and the order summary, then POSTs to
   place_order.php. Your schema has no orders / addresses tables, so this
   page does NOT write anything: place_order.php is the hook you connect
   to your own order storage / payment gateway.
   POST fields: csrf, full_name, phone, address, landmark, city, state,
                pincode, payment (cod | online), notes
   ===================================================================== */

$uid = require_login();
$items = cart_items($conn, $uid);
if (!$items) { header('Location: cart.php'); exit; }
if (array_filter($items, fn($i) => !$i['available'])) { header('Location: cart.php'); exit; }

$user = rows($conn, "SELECT firstname, lastname, phone, email FROM users WHERE uid = ?", 's', [$uid])[0] ?? [];
$t = cart_totals($items);
$prepaid = cart_totals($items, true);
$old = $_SESSION['checkout_old'] ?? []; unset($_SESSION['checkout_old']);
$err = $_SESSION['checkout_error'] ?? ''; unset($_SESSION['checkout_error']);
$val = fn($k, $d = '') => e($old[$k] ?? $d);
$etaFrom = date('D, d M', strtotime('+3 days')); $etaTo = date('D, d M', strtotime('+6 days'));
$btn = 'bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:from-amber-600 hover:to-purple-700 shadow-lg shadow-pink-500/30';
$input = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-pink-500 focus:ring-1 focus:ring-pink-200';
$states = ['Andhra Pradesh','Assam','Bihar','Chhattisgarh','Delhi','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Odisha','Punjab','Rajasthan','Tamil Nadu','Telangana','Uttar Pradesh','Uttarakhand','West Bengal'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout | <?= e($company['title']) ?></title>
  <link href="./src/output.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<?php include __DIR__ . "/head.php"; ?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
  <nav class="text-xs text-gray-500 mb-3 flex items-center gap-1.5">
    <a href="/" class="hover:text-pink-600">Home</a><i class="bi bi-chevron-right text-[9px]"></i>
    <a href="cart.php" class="hover:text-pink-600">Cart</a><i class="bi bi-chevron-right text-[9px]"></i><span class="font-semibold text-gray-800">Checkout</span>
  </nav>

  <div class="rounded-2xl bg-gradient-to-r from-indigo-900 via-purple-700 to-pink-500 text-white px-6 py-5 mb-5 shadow-md">
    <h1 class="text-xl sm:text-2xl font-extrabold uppercase tracking-wide">Secure Checkout</h1>
    <div class="flex items-center gap-2 mt-2 text-[11px] font-bold uppercase tracking-wider text-white/80">
      <span>Cart</span><i class="bi bi-chevron-right text-[9px]"></i><span class="text-white underline underline-offset-4">Details &amp; Payment</span><i class="bi bi-chevron-right text-[9px]"></i><span>Confirmation</span>
    </div>
  </div>

  <?php if ($err): ?><div class="mb-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-semibold px-4 py-3"><i class="bi bi-exclamation-circle"></i> <?= e($err) ?></div><?php endif; ?>

  <form id="checkoutForm" method="post" action="place_order.php" class="grid grid-cols-1 lg:grid-cols-12 gap-6" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="lg:col-span-8 space-y-5">

      <!-- 1. Address -->
      <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
        <h2 class="flex items-center gap-3 text-sm font-extrabold uppercase tracking-wider text-gray-900 mb-4">
          <span class="w-7 h-7 rounded-full bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600 text-white text-xs flex items-center justify-center">1</span> Delivery Address
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label class="block"><span class="text-xs font-bold text-gray-600">Full name *</span>
            <input name="full_name" required value="<?= $val('full_name', trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''))) ?>" class="<?= $input ?> mt-1"></label>
          <label class="block"><span class="text-xs font-bold text-gray-600">Mobile number *</span>
            <input name="phone" required inputmode="tel" pattern="[0-9+\-\s]{10,15}" value="<?= $val('phone', $user['phone'] ?? '') ?>" class="<?= $input ?> mt-1"></label>
          <label class="block sm:col-span-2"><span class="text-xs font-bold text-gray-600">Address (house no, building, street) *</span>
            <textarea name="address" required rows="2" class="<?= $input ?> mt-1"><?= $val('address') ?></textarea></label>
          <label class="block sm:col-span-2"><span class="text-xs font-bold text-gray-600">Landmark (optional)</span>
            <input name="landmark" value="<?= $val('landmark') ?>" class="<?= $input ?> mt-1"></label>
          <label class="block"><span class="text-xs font-bold text-gray-600">City *</span>
            <input name="city" required value="<?= $val('city') ?>" class="<?= $input ?> mt-1"></label>
          <label class="block"><span class="text-xs font-bold text-gray-600">PIN code *</span>
            <input name="pincode" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" value="<?= $val('pincode') ?>" class="<?= $input ?> mt-1"></label>
          <label class="block sm:col-span-2"><span class="text-xs font-bold text-gray-600">State *</span>
            <select name="state" required class="<?= $input ?> mt-1 bg-white">
              <option value="">Select state</option>
              <?php foreach ($states as $s): ?><option <?= ($old['state'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
            </select></label>
        </div>
      </section>

      <!-- 2. Delivery -->
      <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
        <h2 class="flex items-center gap-3 text-sm font-extrabold uppercase tracking-wider text-gray-900 mb-4">
          <span class="w-7 h-7 rounded-full bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600 text-white text-xs flex items-center justify-center">2</span> Delivery
        </h2>
        <div class="flex items-center gap-3 rounded-xl border-2 border-pink-500 bg-pink-50/40 p-4">
          <i class="bi bi-truck text-xl text-pink-600"></i>
          <div class="text-sm"><p class="font-bold text-gray-900">Standard delivery · <?= $t['shipping'] ? money($t['shipping']) : '<span class="text-green-700">FREE</span>' ?></p>
            <p class="text-xs text-gray-600">Arrives <?= $etaFrom ?> - <?= $etaTo ?></p></div>
        </div>
      </section>

      <!-- 3. Payment -->
      <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
        <h2 class="flex items-center gap-3 text-sm font-extrabold uppercase tracking-wider text-gray-900 mb-4">
          <span class="w-7 h-7 rounded-full bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600 text-white text-xs flex items-center justify-center">3</span> Payment Method
        </h2>
        <div class="space-y-3">
          <label class="pay-opt flex items-start gap-3 rounded-xl border-2 border-gray-200 p-4 cursor-pointer hover:border-pink-300 transition-colors has-[:checked]:border-pink-500 has-[:checked]:bg-pink-50/40">
            <input type="radio" name="payment" value="online" class="mt-1 accent-pink-600" <?= ($old['payment'] ?? 'online') === 'online' ? 'checked' : '' ?>>
            <div class="flex-1"><p class="text-sm font-bold text-gray-900"><i class="bi bi-credit-card text-pink-600"></i> Pay online (UPI, card, net banking)
              <span class="ml-2 text-[10px] font-extrabold uppercase bg-green-100 text-green-700 rounded-full px-2 py-0.5">Extra <?= PREPAID_DISCOUNT ?>% off</span></p>
              <p class="text-xs text-gray-500 mt-0.5">You'll be redirected to our secure payment page.</p></div>
          </label>
          <label class="pay-opt flex items-start gap-3 rounded-xl border-2 border-gray-200 p-4 cursor-pointer hover:border-pink-300 transition-colors has-[:checked]:border-pink-500 has-[:checked]:bg-pink-50/40">
            <input type="radio" name="payment" value="cod" class="mt-1 accent-pink-600" <?= ($old['payment'] ?? '') === 'cod' ? 'checked' : '' ?>>
            <div class="flex-1"><p class="text-sm font-bold text-gray-900"><i class="bi bi-cash-coin text-pink-600"></i> Cash on delivery</p>
              <p class="text-xs text-gray-500 mt-0.5">Pay in cash when your order arrives.</p></div>
          </label>
        </div>
        <label class="block mt-4"><span class="text-xs font-bold text-gray-600">Order notes (optional)</span>
          <textarea name="notes" rows="2" class="<?= $input ?> mt-1" placeholder="Anything we should know about delivery?"><?= $val('notes') ?></textarea></label>
      </section>
    </div>

    <!-- Summary -->
    <aside class="lg:col-span-4">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 lg:sticky lg:top-4 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-900">Your Order</h2>
          <a href="cart.php" class="text-xs font-bold text-pink-600 hover:underline">Edit cart</a>
        </div>
        <ul class="max-h-64 overflow-y-auto divide-y divide-gray-100 -mx-1 px-1">
          <?php foreach ($items as $i): ?>
          <li class="flex gap-3 py-2.5">
            <img src="<?= e(first_image($i['specification'])) ?>" alt="" class="w-12 h-16 rounded-lg object-cover object-top bg-pink-50">
            <div class="flex-1 min-w-0"><p class="text-xs font-semibold text-gray-800 truncate"><?= e($i['product_name']) ?></p><p class="text-[11px] text-gray-500">Qty <?= $i['quantity'] ?></p></div>
            <p class="text-xs font-bold text-gray-900"><?= money($i['line_total']) ?></p>
          </li>
          <?php endforeach; ?>
        </ul>

        <dl class="text-sm space-y-2.5 pt-2 border-t border-gray-100">
          <div class="flex justify-between"><dt class="text-gray-600">Items (<?= $t['count'] ?>)</dt><dd class="font-semibold"><?= money($t['mrp']) ?></dd></div>
          <?php if ($t['savings'] > 0): ?><div class="flex justify-between"><dt class="text-gray-600">Discount</dt><dd class="font-semibold text-green-700">- <?= money($t['savings']) ?></dd></div><?php endif; ?>
          <div class="flex justify-between"><dt class="text-gray-600">Shipping</dt><dd class="font-semibold"><?= $t['shipping'] ? money($t['shipping']) : '<span class="text-green-700">FREE</span>' ?></dd></div>
          <div id="prepaidRow" class="flex justify-between"><dt class="text-gray-600">Prepaid discount (<?= PREPAID_DISCOUNT ?>%)</dt><dd class="font-semibold text-green-700">- <?= money($prepaid['prepaid_off']) ?></dd></div>
          <div class="flex justify-between pt-3 border-t border-gray-100 text-base"><dt class="font-extrabold text-gray-900">Total</dt><dd id="grandTotal" class="font-extrabold text-gray-900"><?= money($prepaid['total']) ?></dd></div>
        </dl>

        <button type="submit" class="w-full h-12 rounded-xl text-sm font-bold uppercase tracking-wide text-white <?= $btn ?> transition-all"><i class="bi bi-lock-fill mr-1"></i> <span id="btnLabel">Pay <?= money($prepaid['total']) ?></span></button>
        <p class="text-[11px] text-gray-500 text-center">By placing your order you agree to our terms and 7-day return policy.</p>
      </div>
    </aside>
  </form>
</main>

<?php include __DIR__ . "/footer.php"; ?>
<script>
  const TOTAL_COD = <?= json_encode($t['total']) ?>, TOTAL_ONLINE = <?= json_encode($prepaid['total']) ?>;
  const inr = n => '₹' + Math.round(n).toLocaleString('en-IN');
  function refreshTotals() {
    const online = document.querySelector('input[name=payment]:checked').value === 'online';
    const total = online ? TOTAL_ONLINE : TOTAL_COD;
    document.getElementById('prepaidRow').classList.toggle('hidden', !online);
    document.getElementById('grandTotal').textContent = inr(total);
    document.getElementById('btnLabel').textContent = online ? 'Pay ' + inr(total) : 'Place Order · ' + inr(total);
  }
  document.querySelectorAll('input[name=payment]').forEach(r => r.addEventListener('change', refreshTotals));
  refreshTotals();

  document.getElementById('checkoutForm').addEventListener('submit', e => {
    const f = e.target; let bad = null;
    f.querySelectorAll('[required]').forEach(el => {
      const invalid = !el.value.trim() || (el.pattern && !new RegExp('^(?:' + el.pattern + ')$').test(el.value.trim()));
      el.classList.toggle('border-red-400', invalid);
      if (invalid && !bad) bad = el;
    });
    if (bad) { e.preventDefault(); bad.scrollIntoView({ behavior: 'smooth', block: 'center' }); bad.focus(); }
  });
</script>
</body>
</html>