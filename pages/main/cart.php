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
if (!function_exists('current_uid')) { function current_uid() { return $_SESSION['uid'] ?? null; } }
if (!function_exists('require_login')) {
    function require_login() {
        $uid = current_uid();
        if (!$uid) { header('Location: /login'); exit; }
        return $uid;
    }
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
if (!function_exists('cart_count')) {
    function cart_count($conn, $uid) {
        return (int)(rows($conn, "SELECT COALESCE(SUM(quantity),0) c FROM cart_items WHERE uid = ?", 's', [$uid])[0]['c'] ?? 0);
    }
}

/* =====================================================================
   cart.php
   GET  -> the cart page
   POST -> JSON endpoint (the same one index/category/product pages call):
           action = add (default) | update | remove | to_wishlist | clear
           product_id, quantity
   Only cart_items / wishlist rows are written. No schema changes.
   ===================================================================== */

$uid = current_uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$uid) { echo json_encode(['ok' => false, 'login' => true]); exit; }

    $action = $_POST['action'] ?? 'add';
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    $msg = '';

    if ($action === 'clear') {
        run($conn, "DELETE FROM cart_items WHERE uid = ?", 's', [$uid]);
    } elseif ($pid > 0) {
        $prod = rows($conn, "SELECT id, final_price, instock FROM products WHERE id = ?", 'i', [$pid])[0] ?? null;

        if ($action === 'remove') {
            run($conn, "DELETE FROM cart_items WHERE uid = ? AND product_id = ?", 'si', [$uid, $pid]);
        } elseif ($action === 'to_wishlist') {
            run($conn, "INSERT IGNORE INTO wishlist (uid, product_id) VALUES (?, ?)", 'si', [$uid, $pid]);
            run($conn, "DELETE FROM cart_items WHERE uid = ? AND product_id = ?", 'si', [$uid, $pid]);
        } elseif ($prod && (int)$prod['instock'] > 0) {
            $max = min((int)$prod['instock'], 10);
            if ($action === 'update') {
                $newQty = min($qty, $max);
            } else { // add
                $cur = (int)(rows($conn, "SELECT quantity FROM cart_items WHERE uid = ? AND product_id = ?", 'si', [$uid, $pid])[0]['quantity'] ?? 0);
                $newQty = min($cur + $qty, $max);
                if ($cur + $qty > $max) $msg = 'Maximum available quantity added.';
            }
            $amount = round($newQty * (float)$prod['final_price'], 2);
            run($conn, "INSERT INTO cart_items (uid, product_id, quantity, amount) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), amount = VALUES(amount)", 'siid', [$uid, $pid, $newQty, $amount]);
        } else {
            echo json_encode(['ok' => false, 'message' => 'This item is out of stock.']); exit;
        }
    }
    echo json_encode(['ok' => true, 'message' => $msg, 'count' => cart_count($conn, $uid)]);
    exit;
}

/* ---------------- page ---------------- */
$items = $uid ? cart_items($conn, $uid) : [];
$t = cart_totals($items);
$allOk = $items && !array_filter($items, fn($i) => !$i['available']);
$progress = $t['sell'] > 0 ? min(100, round($t['sell'] / FREE_SHIP_MIN * 100)) : 0;
$btn = 'bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:from-amber-600 hover:to-purple-700 shadow-lg shadow-pink-500/30';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Shopping Cart | <?= e($company['title']) ?></title>
  <link href="./src/output.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<?php include __DIR__ . "/head.php"; ?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
  <nav class="text-xs text-gray-500 mb-3 flex items-center gap-1.5">
    <a href="/" class="hover:text-pink-600">Home</a><i class="bi bi-chevron-right text-[9px]"></i><span class="font-semibold text-gray-800">Cart</span>
  </nav>

  <div class="rounded-2xl bg-gradient-to-r from-indigo-900 via-purple-700 to-pink-500 text-white px-6 py-5 mb-5 shadow-md flex items-center justify-between">
    <h1 class="text-xl sm:text-2xl font-extrabold uppercase tracking-wide">Shopping Cart</h1>
    <?php if ($items): ?><span class="text-xs sm:text-sm text-white/80"><?= $t['count'] ?> item<?= $t['count'] === 1 ? '' : 's' ?></span><?php endif; ?>
  </div>

<?php if (!$uid): ?>
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm py-16 px-6 text-center">
    <div class="mx-auto w-16 h-16 rounded-full bg-gradient-to-tr from-purple-100 to-pink-100 flex items-center justify-center text-pink-500 mb-4"><i class="bi bi-person text-2xl"></i></div>
    <h2 class="text-lg font-bold text-gray-900">Log in to see your cart</h2>
    <p class="text-sm text-gray-500 mt-1">Your cart is saved to your account.</p>
    <a href="/login" class="inline-block mt-5 px-8 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-white <?= $btn ?>">Log in</a>
  </div>

<?php elseif (!$items): ?>
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm py-16 px-6 text-center">
    <div class="mx-auto w-16 h-16 rounded-full bg-gradient-to-tr from-purple-100 to-pink-100 flex items-center justify-center text-pink-500 mb-4"><i class="bi bi-bag text-2xl"></i></div>
    <h2 class="text-lg font-bold text-gray-900">Your cart is empty</h2>
    <p class="text-sm text-gray-500 mt-1">Looks like you haven't added anything yet.</p>
    <a href="category.php" class="inline-block mt-5 px-8 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-white <?= $btn ?>">Start shopping</a>
  </div>

<?php else: ?>
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

    <!-- Items -->
    <section class="lg:col-span-8 space-y-3">
      <!-- Free shipping progress -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <?php if ($t['free_left'] > 0): ?>
          <p class="text-sm text-gray-700"><i class="bi bi-truck text-pink-500"></i> Add <span class="font-bold"><?= money($t['free_left']) ?></span> more for <span class="font-bold text-green-700">FREE shipping</span></p>
        <?php else: ?>
          <p class="text-sm font-bold text-green-700"><i class="bi bi-check-circle-fill"></i> You've unlocked FREE shipping!</p>
        <?php endif; ?>
        <div class="h-2 bg-gray-100 rounded-full overflow-hidden mt-2"><div class="h-full bg-gradient-to-r from-amber-400 via-rose-500 to-purple-600 transition-all" style="width:<?= $progress ?>%"></div></div>
      </div>

      <?php foreach ($items as $i): $max = max(1, min(10, (int)$i['instock'])); ?>
      <article class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex gap-4">
        <a href="product.php?slug=<?= e($i['slug']) ?>" class="relative shrink-0 w-24 sm:w-28 aspect-[3/4] rounded-xl overflow-hidden bg-pink-50/50">
          <?php if ($i['off_price'] > 0): ?><span class="absolute top-1.5 left-1.5 z-10 bg-pink-600 text-white text-[9px] font-extrabold px-1.5 py-0.5 rounded">-<?= (int)$i['off_price'] ?>%</span><?php endif; ?>
          <img src="<?= e(first_image($i['specification'])) ?>" alt="<?= e($i['product_name']) ?>" class="w-full h-full object-cover object-top">
        </a>
        <div class="flex-1 min-w-0 flex flex-col justify-between">
          <div class="flex justify-between gap-3">
            <div class="min-w-0">
              <a href="product.php?slug=<?= e($i['slug']) ?>" class="block text-sm sm:text-base font-semibold text-gray-800 hover:text-pink-600 truncate"><?= e($i['product_name']) ?></a>
              <div class="flex items-center gap-2 mt-1">
                <span class="text-sm font-bold text-gray-900"><?= money($i['final_price']) ?></span>
                <?php if ($i['off_price'] > 0): ?><span class="text-xs text-gray-400 line-through"><?= money($i['price']) ?></span><?php endif; ?>
              </div>
              <?php if (!$i['available']): ?>
                <p class="text-xs font-bold text-red-600 mt-1.5"><i class="bi bi-exclamation-circle"></i> <?= (int)$i['instock'] > 0 ? 'Only ' . (int)$i['instock'] . ' left - reduce quantity' : 'Out of stock - remove to continue' ?></p>
              <?php elseif ((int)$i['instock'] <= 5): ?>
                <p class="text-xs font-semibold text-amber-600 mt-1.5">Only <?= (int)$i['instock'] ?> left</p>
              <?php endif; ?>
            </div>
            <div class="text-right shrink-0"><p class="text-base sm:text-lg font-extrabold text-gray-900"><?= money($i['line_total']) ?></p></div>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
            <div class="inline-flex items-center border border-gray-200 rounded-lg overflow-hidden">
              <button onclick="cartApi('update', <?= (int)$i['pid'] ?>, <?= max(1, $i['quantity'] - 1) ?>)" class="w-9 h-9 text-gray-600 hover:bg-pink-50 hover:text-pink-600 <?= $i['quantity'] <= 1 ? 'opacity-40 pointer-events-none' : '' ?>"><i class="bi bi-dash-lg"></i></button>
              <span class="w-10 text-center text-sm font-bold"><?= $i['quantity'] ?></span>
              <button onclick="cartApi('update', <?= (int)$i['pid'] ?>, <?= $i['quantity'] + 1 ?>)" class="w-9 h-9 text-gray-600 hover:bg-pink-50 hover:text-pink-600 <?= $i['quantity'] >= $max ? 'opacity-40 pointer-events-none' : '' ?>"><i class="bi bi-plus-lg"></i></button>
            </div>
            <div class="flex items-center gap-4 text-xs font-bold uppercase tracking-wide">
              <button onclick="cartApi('to_wishlist', <?= (int)$i['pid'] ?>)" class="text-gray-500 hover:text-pink-600 transition-colors"><i class="bi bi-heart"></i> Save for later</button>
              <button onclick="cartApi('remove', <?= (int)$i['pid'] ?>)" class="text-gray-500 hover:text-red-600 transition-colors"><i class="bi bi-trash3"></i> Remove</button>
            </div>
          </div>
        </div>
      </article>
      <?php endforeach; ?>

      <div class="flex items-center justify-between pt-1">
        <a href="category.php" class="text-xs font-bold uppercase tracking-wider text-pink-600 hover:underline"><i class="bi bi-arrow-left"></i> Continue shopping</a>
        <button onclick="if(confirm('Remove all items from your cart?')) cartApi('clear', 0)" class="text-xs font-bold uppercase tracking-wider text-gray-500 hover:text-red-600">Clear cart</button>
      </div>
    </section>

    <!-- Summary -->
    <aside class="lg:col-span-4">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 lg:sticky lg:top-4 space-y-4">
        <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-900">Order Summary</h2>
        <dl class="text-sm space-y-2.5">
          <div class="flex justify-between"><dt class="text-gray-600">Items (<?= $t['count'] ?>)</dt><dd class="font-semibold"><?= money($t['mrp']) ?></dd></div>
          <?php if ($t['savings'] > 0): ?><div class="flex justify-between"><dt class="text-gray-600">Discount</dt><dd class="font-semibold text-green-700">- <?= money($t['savings']) ?></dd></div><?php endif; ?>
          <div class="flex justify-between"><dt class="text-gray-600">Shipping</dt><dd class="font-semibold"><?= $t['shipping'] ? money($t['shipping']) : '<span class="text-green-700">FREE</span>' ?></dd></div>
          <div class="flex justify-between pt-3 border-t border-gray-100 text-base"><dt class="font-extrabold text-gray-900">Total</dt><dd class="font-extrabold text-gray-900"><?= money($t['total']) ?></dd></div>
        </dl>
        <?php if ($t['savings'] > 0): ?><p class="text-xs font-bold text-green-700 bg-green-50 border border-green-200 rounded-lg px-3 py-2">You're saving <?= money($t['savings']) ?> on this order</p><?php endif; ?>
        <p class="text-[11px] text-gray-500"><i class="bi bi-credit-card text-pink-500"></i> Extra <?= PREPAID_DISCOUNT ?>% off on prepaid orders at checkout.</p>

        <?php if ($allOk): ?>
          <a href="checkout.php" class="block text-center h-11 leading-[2.75rem] rounded-xl text-sm font-bold uppercase tracking-wide text-white <?= $btn ?> transition-all">Proceed to Checkout</a>
        <?php else: ?>
          <button disabled class="w-full h-11 rounded-xl text-sm font-bold uppercase tracking-wide text-white bg-gray-300 cursor-not-allowed">Fix unavailable items</button>
        <?php endif; ?>
        <div class="flex items-center justify-center gap-4 text-[11px] text-gray-500 pt-1">
          <span><i class="bi bi-lock text-pink-500"></i> Secure payment</span><span><i class="bi bi-arrow-repeat text-pink-500"></i> 7-day returns</span>
        </div>
      </div>
    </aside>
  </div>
<?php endif; ?>
</main>

<?php include __DIR__ . "/footer.php"; ?>
<script>
  function cartApi(action, pid, qty) {
    const body = new URLSearchParams({ action, product_id: pid, quantity: qty || 1 });
    fetch('cart.php', { method: 'POST', body })
      .then(r => r.json())
      .then(d => { if (d.login) location.href = '/login'; else location.reload(); })
      .catch(() => alert('Something went wrong. Please try again.'));
  }
</script>
</body>
</html>