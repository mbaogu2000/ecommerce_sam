<?php
include __DIR__ . "/config.php";

/* =====================================================================
   product?slug=floral-maxi-dress
   Amazon / Jumia style layout: gallery | details | sticky buy box.
   Read-only: no DB changes. Images, sizes, colours and extra specs come
   from products.specification JSON.
   ===================================================================== */

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n) { return '₹' . number_format((float)$n, 0); }
function rows($conn, $sql, $types = '', $params = []) {
    $s = $conn->prepare($sql);
    if (!$s) return [];
    if ($params) $s->bind_param($types, ...$params);
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}
function first_image($json) {
    $s = json_decode($json ?? '', true);
    $fb = 'https://placehold.co/300x400/f3e8ff/7b1d8e?text=No+Image';
    return is_array($s) ? ($s['image'] ?? ($s['images'][0] ?? $fb)) : $fb;
}
function star_row($avg, $cls = 'text-sm') {
    $a = (int)round($avg);
    $h = '<span class="inline-flex items-center gap-0.5 text-amber-400 ' . $cls . '">';
    for ($i = 1; $i <= 5; $i++) $h .= '<i class="bi ' . ($i <= $a ? 'bi-star-fill' : 'bi-star') . '"></i>';
    return $h . '</span>';
}
function mini_card($x) { ?>
  <a href="product?slug=<?= e($x['slug']) ?>" class="bg-white rounded-xl overflow-hidden border border-gray-100 shadow-sm hover:shadow-md transition-shadow p-2 flex flex-col justify-between">
    <div class="relative rounded-lg overflow-hidden bg-rose-50/40 aspect-[3/4]">
      <?php if ($x['off_price'] > 0): ?><span class="absolute top-2 left-2 z-10 bg-pink-600 text-white text-[9px] font-extrabold px-1.5 py-0.5 rounded">-<?= (int)$x['off_price'] ?>%</span><?php endif; ?>
      <img src="<?= e(first_image($x['specification'])) ?>" alt="<?= e($x['product_name']) ?>" loading="lazy" class="w-full h-full object-cover object-top">
    </div>
    <div class="mt-2 text-center">
      <h3 class="text-[11px] font-medium text-gray-700 truncate"><?= e($x['product_name']) ?></h3>
      <p class="text-xs font-bold text-gray-900 mt-0.5"><?= money($x['final_price']) ?>
        <?php if ($x['off_price'] > 0): ?><span class="text-[10px] text-gray-400 line-through font-normal ml-1"><?= money($x['price']) ?></span><?php endif; ?></p>
    </div>
  </a>
<?php }

$slug = trim($_GET['slug'] ?? '');
$p = $slug === '' ? null : (rows($conn,
    "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, sc.name AS sub_name, b.name AS brand_name, u.username AS seller
       FROM products p
       LEFT JOIN category c ON c.id = p.product_category_id
       LEFT JOIN sub_category sc ON sc.id = p.product_sub_category_id
       LEFT JOIN brand_category b ON b.id = p.brand_category_id
       LEFT JOIN users u ON u.uid = p.uid
      WHERE p.slug = ?", 's', [$slug])[0] ?? null);
if (!$p) http_response_code(404);

if ($p) {
    $pid = (int)$p['id'];

    /* ---- specification JSON ---- */
    $spec = json_decode($p['specification'] ?? '', true); $spec = is_array($spec) ? $spec : [];
    $images = !empty($spec['images']) ? (array)$spec['images'] : (!empty($spec['image']) ? [$spec['image']] : []);
    if (!$images) $images = ['https://placehold.co/600x800/f3e8ff/7b1d8e?text=No+Image'];
    $sizes  = (array)($spec['sizes'] ?? []);
    $colors = (array)($spec['colors'] ?? ($spec['color'] ?? []));
    $extra  = array_diff_key($spec, array_flip(['image', 'images', 'sizes', 'color', 'colors']));

    /* ---- ratings ---- */
    $agg = rows($conn, "SELECT COUNT(*) c, COALESCE(AVG(star),0) a FROM products_reviews WHERE product_id = ? AND parent_id IS NULL", 'i', [$pid])[0];
    $revCount = (int)$agg['c']; $avg = (float)$agg['a'];
    $dist = array_fill(1, 5, 0);
    foreach (rows($conn, "SELECT star, COUNT(*) c FROM products_reviews WHERE product_id = ? AND parent_id IS NULL GROUP BY star", 'i', [$pid]) as $r) $dist[(int)$r['star']] = (int)$r['c'];

    /* ---- reviews (star filter + pagination) ---- */
    $starF = in_array((int)($_GET['star'] ?? 0), [1, 2, 3, 4, 5]) ? (int)$_GET['star'] : 0;
    $filtCount = $starF ? $dist[$starF] : $revCount;
    $rPer = 5; $rPages = max(1, (int)ceil($filtCount / $rPer));
    $rp = min(max(1, (int)($_GET['rp'] ?? 1)), $rPages);
    $revSql = "SELECT r.id, r.uid, r.star, r.msg, r.created_at, u.firstname, u.lastname
                 FROM products_reviews r JOIN users u ON u.uid = r.uid
                WHERE r.product_id = ? AND r.parent_id IS NULL" . ($starF ? " AND r.star = ?" : "") .
              " ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?";
    $reviews = $starF ? rows($conn, $revSql, 'iiii', [$pid, $starF, $rPer, ($rp - 1) * $rPer])
                      : rows($conn, $revSql, 'iii', [$pid, $rPer, ($rp - 1) * $rPer]);
    $replies = [];
    if ($reviews) {
        $ids = implode(',', array_map('intval', array_column($reviews, 'id')));
        foreach (rows($conn, "SELECT r.parent_id, r.uid, r.msg, r.created_at, u.firstname
                                FROM products_reviews r JOIN users u ON u.uid = r.uid
                               WHERE r.parent_id IN ($ids) ORDER BY r.created_at ASC") as $rep) $replies[$rep['parent_id']][] = $rep;
    }

    /* ---- related + more from seller ---- */
    $related = rows($conn,
        "SELECT id, slug, product_name, final_price, price, off_price, specification FROM products
          WHERE id <> ? AND instock > 0 AND (product_sub_category_id <=> ? OR product_category_id <=> ?)
          ORDER BY (product_sub_category_id <=> ?) DESC, id DESC LIMIT 8",
        'iiii', [$pid, $p['product_sub_category_id'], $p['product_category_id'], $p['product_sub_category_id']]);
    $fromSeller = rows($conn,
        "SELECT id, slug, product_name, final_price, price, off_price, specification FROM products
          WHERE uid = ? AND id <> ? AND instock > 0 ORDER BY id DESC LIMIT 8", 'si', [$p['uid'], $pid]);

    /* ---- derived ---- */
    $inStock = (int)$p['instock'] > 0;
    $maxQty = max(1, min(10, (int)$p['instock']));
    $saving = (float)$p['price'] - (float)$p['final_price'];
    $freeShip = (float)$p['final_price'] >= 999;
    $etaFrom = date('D, d M', strtotime('+3 days')); $etaTo = date('D, d M', strtotime('+6 days'));
    $loggedUid = $_SESSION['uid'] ?? null;
    $bullets = array_values(array_filter(array_map('trim', preg_split('/\r?\n|(?<=[.!?])\s+/', (string)$p['description']))));
    $bullets = array_slice($bullets, 0, 6);
    $specRows = [];
    if ($p['brand_name']) $specRows['Brand'] = $p['brand_name'];
    if ($p['cat_name'])   $specRows['Category'] = $p['cat_name'];
    if ($p['sub_name'])   $specRows['Type'] = $p['sub_name'];
    if ($colors)          $specRows['Colour'] = implode(', ', $colors);
    if ($sizes)           $specRows['Sizes'] = implode(', ', $sizes);
    foreach ($extra as $k => $v) $specRows[ucwords(str_replace('_', ' ', $k))] = is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v;
}
$btnPrimary = 'bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:from-amber-600 hover:to-purple-700 shadow-lg shadow-pink-500/30';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $p ? e($p['product_name']) : 'Product not found' ?> | <?= e($company['title']) ?></title>
  <?php if ($p): ?><meta name="description" content="<?= e(mb_strimwidth(strip_tags($p['description'] ?? ''), 0, 155, '…')) ?>"><?php endif; ?>
  <link href="./src/output.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>html { scroll-behavior: smooth; } .zoom-img { transition: transform .15s ease-out; }</style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<?php include __DIR__ . "/head.php"; ?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 pb-24 lg:pb-8">

<?php if (!$p): ?>
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm py-20 px-6 text-center">
    <div class="mx-auto w-16 h-16 rounded-full bg-gradient-to-tr from-purple-100 to-pink-100 flex items-center justify-center text-pink-500 mb-4"><i class="bi bi-bag-x text-2xl"></i></div>
    <h1 class="text-lg font-bold text-gray-900">Product not found</h1>
    <p class="text-sm text-gray-500 mt-1">It may have been removed or the link is incorrect.</p>
    <a href="category" class="inline-block mt-5 px-6 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-white <?= $btnPrimary ?>">Browse products</a>
  </div>
<?php else: ?>

  <!-- Breadcrumb -->
  <nav class="text-xs text-gray-500 mb-3 flex flex-wrap items-center gap-1.5">
    <a href="/" class="hover:text-pink-600">Home</a><i class="bi bi-chevron-right text-[9px]"></i>
    <?php if ($p['cat_name']): ?><a href="category?slug=<?= e($p['cat_slug']) ?>" class="hover:text-pink-600"><?= e($p['cat_name']) ?></a><i class="bi bi-chevron-right text-[9px]"></i><?php endif; ?>
    <span class="font-semibold text-gray-800 truncate max-w-[16rem]"><?= e($p['product_name']) ?></span>
  </nav>

  <!-- ================= TOP: gallery | details | buy box ================= -->
  <section class="grid grid-cols-1 lg:grid-cols-12 gap-5">

    <!-- Gallery -->
    <div class="lg:col-span-5 bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
      <div class="flex flex-col-reverse sm:flex-row gap-3">
        <?php if (count($images) > 1): ?>
        <div class="flex sm:flex-col gap-2 sm:w-16 overflow-x-auto sm:overflow-y-auto sm:max-h-[520px]">
          <?php foreach ($images as $i => $img): ?>
            <button type="button" onmouseover="swapImg(this,'<?= e($img) ?>')" onclick="swapImg(this,'<?= e($img) ?>')" class="thumb shrink-0 w-14 h-14 sm:w-16 sm:h-16 rounded-lg overflow-hidden border-2 <?= $i === 0 ? 'border-pink-500' : 'border-gray-200' ?> hover:border-pink-400 transition-colors">
              <img src="<?= e($img) ?>" alt="" class="w-full h-full object-cover object-top">
            </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="relative flex-1">
          <div id="zoomBox" class="relative rounded-xl overflow-hidden bg-pink-50/50 aspect-[3/4] cursor-zoom-in">
            <?php if ($p['off_price'] > 0): ?><span class="absolute top-3 left-3 z-10 bg-pink-600 text-white text-xs font-extrabold px-2 py-1 rounded">-<?= (int)$p['off_price'] ?>%</span><?php endif; ?>
            <img id="mainImg" src="<?= e($images[0]) ?>" alt="<?= e($p['product_name']) ?>" class="zoom-img w-full h-full object-cover object-top">
          </div>
          <div class="absolute top-3 right-3 z-20 flex flex-col gap-2">
            <button onclick="wishlist(<?= $pid ?>)" title="Add to wishlist" class="w-9 h-9 bg-white/95 rounded-full flex items-center justify-center text-gray-600 hover:text-pink-600 shadow transition-colors"><i class="bi bi-heart"></i></button>
            <button onclick="shareIt()" title="Share" class="w-9 h-9 bg-white/95 rounded-full flex items-center justify-center text-gray-600 hover:text-pink-600 shadow transition-colors"><i class="bi bi-share"></i></button>
          </div>
          <p class="hidden sm:block text-center text-[11px] text-gray-400 mt-2">Hover over the image to zoom</p>
        </div>
      </div>
    </div>

    <!-- Details -->
    <div class="lg:col-span-4 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
      <?php if ($p['brand_name']): ?>
        <a href="category?brand[]=<?= (int)$p['brand_category_id'] ?>" class="text-xs font-bold uppercase tracking-widest text-pink-600 hover:underline">Visit the <?= e($p['brand_name']) ?> store</a>
      <?php endif; ?>
      <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight text-gray-900 mt-1 leading-snug"><?= e($p['product_name']) ?></h1>

      <div class="flex flex-wrap items-center gap-2 mt-2 text-sm">
        <span class="font-bold text-gray-800"><?= number_format($avg, 1) ?></span>
        <?= star_row($avg) ?>
        <a href="#reviews" class="text-gray-500 hover:text-pink-600"><?= $revCount ?> rating<?= $revCount === 1 ? '' : 's' ?></a>
      </div>

      <hr class="my-4 border-gray-100">

      <!-- Price (Amazon style) -->
      <div class="flex items-end flex-wrap gap-x-3 gap-y-1">
        <?php if ($p['off_price'] > 0): ?><span class="text-2xl font-light text-rose-600">-<?= (int)$p['off_price'] ?>%</span><?php endif; ?>
        <span class="text-3xl font-extrabold text-gray-900"><?= money($p['final_price']) ?></span>
      </div>
      <?php if ($p['off_price'] > 0): ?>
        <p class="text-xs text-gray-500 mt-1">M.R.P.: <span class="line-through"><?= money($p['price']) ?></span>
          <span class="ml-2 font-bold text-green-700">You save <?= money($saving) ?></span></p>
      <?php endif; ?>
      <p class="text-[11px] text-gray-400 mt-1">Inclusive of all taxes</p>

      <!-- Offers -->
      <div class="grid grid-cols-3 gap-2 mt-4">
        <?php foreach ([['bi-credit-card','Extra 10% off','Prepaid orders','from-indigo-700 via-blue-600 to-cyan-500'],['bi-gift','Buy 2 Get 1','On all products','from-pink-500 via-rose-500 to-purple-600'],['bi-truck','Free shipping','Above ₹999','from-purple-600 via-indigo-500 to-cyan-500']] as $o): ?>
        <div class="rounded-xl p-2.5 bg-gradient-to-r <?= $o[3] ?> text-white text-center shadow">
          <i class="bi <?= $o[0] ?> text-lg"></i>
          <p class="text-[10px] font-extrabold uppercase leading-tight mt-0.5"><?= $o[1] ?></p>
          <p class="text-[9px] text-white/80"><?= $o[2] ?></p>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Colour / Size -->
      <?php if ($colors): ?>
        <h3 class="mt-5 text-xs font-extrabold uppercase tracking-wider text-gray-900">Colour: <span class="font-semibold text-gray-600 normal-case"><?= e(implode(', ', $colors)) ?></span></h3>
      <?php endif; ?>
      <?php if ($sizes): ?>
      <div class="mt-4">
        <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Size</h3>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($sizes as $s): ?>
            <button type="button" onclick="pickSize(this)" data-size="<?= e($s) ?>" class="size-btn min-w-[2.75rem] px-3 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:border-pink-400 transition-colors"><?= e($s) ?></button>
          <?php endforeach; ?>
        </div>
        <p id="sizeErr" class="hidden text-xs text-red-600 mt-1.5">Please select a size.</p>
      </div>
      <?php endif; ?>

      <!-- About this item -->
      <?php if ($bullets): ?>
      <div class="mt-5 pt-4 border-t border-gray-100">
        <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">About this item</h3>
        <ul class="space-y-1.5 text-sm text-gray-600">
          <?php foreach ($bullets as $b): ?><li class="flex gap-2"><i class="bi bi-check2-circle text-pink-500 mt-0.5"></i><span><?= e($b) ?></span></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <!-- Buy box -->
    <aside class="lg:col-span-3">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 lg:sticky lg:top-4 space-y-4">
        <div class="text-2xl font-extrabold text-gray-900"><?= money($p['final_price']) ?></div>

        <div class="text-sm">
          <?php if ($freeShip): ?>
            <p class="text-gray-700"><span class="font-bold text-green-700">FREE delivery</span> <span class="font-semibold"><?= $etaFrom ?> - <?= $etaTo ?></span></p>
          <?php else: ?>
            <p class="text-gray-700">Delivery <span class="font-semibold"><?= $etaFrom ?> - <?= $etaTo ?></span></p>
            <p class="text-[11px] text-gray-500 mt-0.5">Add <?= money(999 - (float)$p['final_price']) ?> more for free shipping</p>
          <?php endif; ?>
        </div>

        <p class="text-base font-bold <?= $inStock ? ((int)$p['instock'] <= 5 ? 'text-amber-600' : 'text-green-600') : 'text-red-600' ?>">
          <?= $inStock ? ((int)$p['instock'] <= 5 ? 'Only ' . (int)$p['instock'] . ' left in stock' : 'In stock') : 'Currently unavailable' ?>
        </p>

        <div class="flex items-center gap-2 <?= $inStock ? '' : 'hidden' ?>">
          <label for="qty" class="text-xs font-bold uppercase tracking-wider text-gray-700">Qty</label>
          <select id="qty" class="border border-gray-200 rounded-lg px-3 py-2 text-sm font-semibold focus:outline-none focus:border-pink-500">
            <?php for ($i = 1; $i <= $maxQty; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?>
          </select>
        </div>

        <div class="space-y-2">
          <button <?= $inStock ? '' : 'disabled' ?> onclick="addToCart(false)" class="w-full h-11 rounded-xl text-sm font-bold uppercase tracking-wide text-white <?= $btnPrimary ?> transition-all disabled:opacity-40 disabled:cursor-not-allowed"><i class="bi bi-bag-plus mr-1"></i> Add to Cart</button>
          <button <?= $inStock ? '' : 'disabled' ?> onclick="addToCart(true)" class="w-full h-11 rounded-xl text-sm font-bold uppercase tracking-wide text-pink-600 border-2 border-pink-500 hover:bg-pink-600 hover:text-white transition-colors disabled:opacity-40 disabled:cursor-not-allowed">Buy Now</button>
          <button onclick="wishlist(<?= $pid ?>)" class="w-full h-10 rounded-xl text-xs font-bold uppercase tracking-wide text-gray-600 border border-gray-200 hover:border-pink-300 hover:text-pink-600 transition-colors"><i class="bi bi-heart mr-1"></i> Add to Wishlist</button>
        </div>
        <p id="cartMsg" class="hidden text-xs font-semibold"></p>

        <dl class="text-xs space-y-1.5 pt-3 border-t border-gray-100">
          <div class="flex gap-3"><dt class="w-20 text-gray-500">Payment</dt><dd class="text-gray-800"><i class="bi bi-lock text-pink-500"></i> Secure transaction</dd></div>
          <div class="flex gap-3"><dt class="w-20 text-gray-500">Sold by</dt><dd class="text-gray-800 font-semibold"><?= e($p['seller'] ?: ($company['title'] ?? 'Store')) ?></dd></div>
          <div class="flex gap-3"><dt class="w-20 text-gray-500">Returns</dt><dd class="text-gray-800">7 days return &amp; exchange</dd></div>
        </dl>
      </div>
    </aside>
  </section>

  <!-- ================= Sticky section nav ================= -->
  <nav class="sticky top-0 z-30 mt-6 bg-white/95 backdrop-blur rounded-xl border border-gray-100 shadow-sm flex overflow-x-auto">
    <?php foreach (['details' => 'Product Details', 'specs' => 'Specifications', 'reviews' => 'Customer Reviews', 'more' => 'You May Also Like'] as $id => $lbl): ?>
      <a href="#<?= $id ?>" class="px-5 py-3 text-[11px] sm:text-xs font-bold uppercase tracking-wider text-gray-600 hover:text-pink-600 whitespace-nowrap transition-colors"><?= $lbl ?></a>
    <?php endforeach; ?>
  </nav>

  <!-- Details -->
  <section id="details" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-8 mt-4 scroll-mt-16">
    <h2 class="text-lg font-bold tracking-tight text-gray-900 uppercase mb-3">Product Details</h2>
    <?php if (trim((string)$p['description']) !== ''): ?>
      <p class="text-sm text-gray-600 leading-relaxed max-w-3xl"><?= nl2br(e($p['description'])) ?></p>
    <?php else: ?><p class="text-sm text-gray-400">No description available.</p><?php endif; ?>
    <?php if (!empty($p['keywords'])): ?>
      <div class="flex flex-wrap gap-2 mt-5">
        <?php foreach (array_filter(array_map('trim', explode(',', $p['keywords']))) as $kw): ?>
          <a href="category?q=<?= urlencode($kw) ?>" class="px-3 py-1 rounded-full bg-pink-50 text-pink-700 border border-pink-200 text-[11px] font-semibold hover:bg-pink-100 transition-colors">#<?= e($kw) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Specs -->
  <section id="specs" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-8 mt-4 scroll-mt-16">
    <h2 class="text-lg font-bold tracking-tight text-gray-900 uppercase mb-3">Specifications</h2>
    <dl class="max-w-2xl rounded-xl border border-gray-100 overflow-hidden text-sm">
      <?php $n = 0; foreach ($specRows as $k => $v): ?>
        <div class="grid grid-cols-3 gap-4 px-4 py-2.5 <?= $n++ % 2 ? 'bg-white' : 'bg-gray-50' ?>"><dt class="font-semibold text-gray-500"><?= e($k) ?></dt><dd class="col-span-2 text-gray-800"><?= e($v) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>

  <!-- Reviews -->
  <section id="reviews" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-8 mt-4 scroll-mt-16">
    <h2 class="text-lg font-bold tracking-tight text-gray-900 uppercase mb-5">Customer Reviews</h2>
    <div class="grid grid-cols-1 md:grid-cols-12 gap-8">
      <div class="md:col-span-4">
        <div class="flex items-center gap-3">
          <span class="text-5xl font-extrabold text-gray-900"><?= number_format($avg, 1) ?></span>
          <div><?= star_row($avg, 'text-lg') ?><p class="text-xs text-gray-500 mt-0.5"><?= $revCount ?> global rating<?= $revCount === 1 ? '' : 's' ?></p></div>
        </div>
        <div class="mt-4 space-y-1.5">
          <?php for ($s = 5; $s >= 1; $s--): $pc = $revCount ? round($dist[$s] / $revCount * 100) : 0; ?>
            <a href="product?slug=<?= urlencode($p['slug']) ?>&star=<?= $s ?>#reviews" class="flex items-center gap-2 text-xs group <?= $starF === $s ? 'font-bold text-pink-600' : 'text-gray-600' ?>">
              <span class="w-8 group-hover:text-pink-600"><?= $s ?> star</span>
              <div class="flex-1 h-3 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-gradient-to-r from-amber-400 to-pink-500" style="width:<?= $pc ?>%"></div></div>
              <span class="w-9 text-right"><?= $pc ?>%</span>
            </a>
          <?php endfor; ?>
        </div>
        <?php if ($starF): ?><a href="product?slug=<?= urlencode($p['slug']) ?>#reviews" class="inline-block mt-3 text-xs font-bold text-pink-600 hover:underline">Show all reviews</a><?php endif; ?>

        <div class="mt-6 pt-5 border-t border-gray-100">
          <?php if ($loggedUid): ?>
            <form method="post" action="review.php" class="space-y-3">
              <input type="hidden" name="product_id" value="<?= $pid ?>">
              <h4 class="text-xs font-extrabold uppercase tracking-wider text-gray-900">Write a review</h4>
              <div class="flex flex-row-reverse justify-end gap-1 text-2xl text-gray-300">
                <?php for ($s = 5; $s >= 1; $s--): ?>
                  <input type="radio" id="st<?= $s ?>" name="star" value="<?= $s ?>" class="peer hidden" <?= $s === 5 ? 'checked' : '' ?>>
                  <label for="st<?= $s ?>" class="cursor-pointer hover:text-amber-400 peer-checked:text-amber-400">★</label>
                <?php endfor; ?>
              </div>
              <textarea name="msg" required rows="3" placeholder="Share your experience..." class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-pink-500"></textarea>
              <button class="px-5 py-2 rounded-lg text-xs font-bold uppercase tracking-wider text-white <?= $btnPrimary ?>">Submit review</button>
            </form>
          <?php else: ?>
            <p class="text-sm text-gray-500"><a href="/login" class="font-bold text-pink-600 hover:underline">Log in</a> to write a review.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="md:col-span-8">
        <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-1">Top reviews<?= $starF ? ' · ' . $starF . ' star' : '' ?></h3>
        <?php if (!$reviews): ?>
          <p class="text-sm text-gray-400 py-6">No reviews to show yet.</p>
        <?php else: foreach ($reviews as $r): ?>
          <article class="py-4 border-b border-gray-100 last:border-0">
            <div class="flex items-center gap-3">
              <span class="w-9 h-9 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white text-xs font-bold flex items-center justify-center"><?= e(strtoupper(mb_substr($r['firstname'], 0, 1))) ?></span>
              <p class="text-sm font-bold text-gray-800"><?= e($r['firstname'] . ' ' . mb_substr($r['lastname'], 0, 1) . '.') ?></p>
            </div>
            <div class="flex items-center gap-2 mt-2"><?= star_row($r['star'], 'text-xs') ?><span class="text-[11px] text-gray-400">Reviewed on <?= e(date('d M Y', strtotime($r['created_at']))) ?></span></div>
            <p class="text-sm text-gray-600 mt-2 leading-relaxed"><?= nl2br(e($r['msg'])) ?></p>
            <?php foreach ($replies[$r['id']] ?? [] as $rep): ?>
              <div class="mt-3 ml-6 pl-4 border-l-2 border-pink-200 bg-pink-50/40 rounded-r-lg py-2 pr-3">
                <p class="text-xs font-bold text-gray-800"><?= $rep['uid'] === $p['uid'] ? 'Seller response' : e($rep['firstname']) ?> <span class="font-normal text-gray-400">· <?= e(date('d M Y', strtotime($rep['created_at']))) ?></span></p>
                <p class="text-sm text-gray-600 mt-0.5"><?= nl2br(e($rep['msg'])) ?></p>
              </div>
            <?php endforeach; ?>
          </article>
        <?php endforeach;
          if ($rPages > 1): ?>
          <nav class="flex items-center justify-center gap-1.5 mt-5">
            <?php for ($i = 1; $i <= $rPages; $i++): ?>
              <a href="product?slug=<?= urlencode($p['slug']) ?><?= $starF ? '&star=' . $starF : '' ?>&rp=<?= $i ?>#reviews" class="min-w-[2.25rem] h-9 inline-flex items-center justify-center rounded-lg text-xs font-bold border transition-colors <?= $i === $rp ? 'bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 border-transparent text-white shadow-md' : 'bg-white border-gray-200 text-gray-700 hover:border-pink-400 hover:text-pink-600' ?>"><?= $i ?></a>
            <?php endfor; ?>
          </nav>
        <?php endif; endif; ?>
      </div>
    </div>
  </section>

  <!-- Related / seller rows -->
  <div id="more" class="scroll-mt-16">
  <?php foreach ([['You May Also Like', $related, $p['cat_slug'] ? 'category?slug=' . $p['cat_slug'] : 'category'],
                  ['More From This Seller', $fromSeller, null]] as [$ttl, $list, $more]): if (!$list) continue; ?>
    <section class="mt-8">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-gray-900 uppercase"><?= e($ttl) ?></h2>
        <?php if ($more): ?><a href="<?= e($more) ?>" class="text-xs sm:text-sm font-semibold text-gray-700 hover:text-pink-600 flex items-center gap-1 uppercase tracking-wider transition-colors">View All <i class="bi bi-arrow-right"></i></a><?php endif; ?>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-8 gap-3 sm:gap-4">
        <?php foreach ($list as $x) mini_card($x); ?>
      </div>
    </section>
  <?php endforeach; ?>
  </div>

  <!-- Mobile sticky buy bar -->
  <div class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-gray-200 shadow-[0_-4px_12px_rgba(0,0,0,.08)] px-4 py-3 flex items-center gap-3">
    <div class="leading-tight">
      <div class="text-lg font-extrabold text-gray-900"><?= money($p['final_price']) ?></div>
      <?php if ($p['off_price'] > 0): ?><div class="text-[11px] text-gray-400 line-through"><?= money($p['price']) ?></div><?php endif; ?>
    </div>
    <button <?= $inStock ? '' : 'disabled' ?> onclick="addToCart(false)" class="flex-1 h-11 rounded-xl text-sm font-bold uppercase text-white <?= $btnPrimary ?> disabled:opacity-40"><?= $inStock ? 'Add to Cart' : 'Unavailable' ?></button>
  </div>

<?php endif; ?>
</main>

<?php include __DIR__ . "/footer.php"; ?>

<?php if ($p): ?>
<script>
  const PID = <?= $pid ?>, HAS_SIZES = <?= $sizes ? 'true' : 'false' ?>;
  let chosenSize = null;

  function swapImg(btn, src) {
    document.getElementById('mainImg').src = src;
    document.querySelectorAll('.thumb').forEach(t => { t.classList.remove('border-pink-500'); t.classList.add('border-gray-200'); });
    btn.classList.remove('border-gray-200'); btn.classList.add('border-pink-500');
  }
  // Hover zoom
  (function () {
    const box = document.getElementById('zoomBox'), img = document.getElementById('mainImg');
    if (!box || window.matchMedia('(hover: none)').matches) return;
    box.addEventListener('mousemove', e => {
      const r = box.getBoundingClientRect();
      img.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%';
      img.style.transform = 'scale(2)';
    });
    box.addEventListener('mouseleave', () => { img.style.transform = 'scale(1)'; });
  })();

  function pickSize(btn) {
    chosenSize = btn.dataset.size;
    document.querySelectorAll('.size-btn').forEach(b => { b.classList.remove('border-pink-500', 'bg-pink-50', 'text-pink-700'); b.classList.add('border-gray-200', 'text-gray-700'); });
    btn.classList.remove('border-gray-200', 'text-gray-700'); btn.classList.add('border-pink-500', 'bg-pink-50', 'text-pink-700');
    document.getElementById('sizeErr').classList.add('hidden');
  }
  function msg(text, ok) {
    const m = document.getElementById('cartMsg');
    m.textContent = text; m.className = 'text-xs font-semibold ' + (ok ? 'text-green-600' : 'text-red-600');
  }
  // Note: cart_items has no size column, so size is sent but only stored if your cart.php handles it.
  function addToCart(buyNow) {
    if (HAS_SIZES && !chosenSize) {
      document.getElementById('sizeErr').classList.remove('hidden');
      document.getElementById('sizeErr').scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    const body = 'product_id=' + PID + '&quantity=' + document.getElementById('qty').value + (chosenSize ? '&size=' + encodeURIComponent(chosenSize) : '');
    fetch('cart.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body })
      .then(r => r.json()).catch(() => ({}))
      .then(d => {
        if (d && d.login) { window.location.href = '/login'; return; }
        if (buyNow) { window.location.href = '/cart'; return; }
        msg('Added to your cart.', true);
      });
  }
  function wishlist(id) {
    fetch('wishlist.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'product_id=' + id })
      .then(r => r.json()).catch(() => ({}))
      .then(d => { if (d && d.login) window.location.href = '/login'; });
  }
  function shareIt() {
    const data = { title: document.title, url: location.href };
    if (navigator.share) navigator.share(data).catch(() => {});
    else if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(() => msg('Link copied.', true));
  }
</script>
<?php endif; ?>
</body>
</html>