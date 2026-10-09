<?php
include __DIR__ . "/config.php";

/* =====================================================================
   product.php?slug=floral-maxi-dress
   Read-only: no DB changes. Images / sizes / colours / extra specs come
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
    if (is_array($s)) return $s['image'] ?? ($s['images'][0] ?? 'https://placehold.co/300x400/f3e8ff/7b1d8e?text=No+Image');
    return 'https://placehold.co/300x400/f3e8ff/7b1d8e?text=No+Image';
}
function star_row($avg, $cls = 'text-sm') {
    $a = (int)round($avg);
    $h = '<span class="inline-flex items-center gap-0.5 text-amber-400 ' . $cls . '">';
    for ($i = 1; $i <= 5; $i++) $h .= '<i class="bi ' . ($i <= $a ? 'bi-star-fill' : 'bi-star') . '"></i>';
    return $h . '</span>';
}

$slug = trim($_GET['slug'] ?? '');
$p = $slug === '' ? null : (rows($conn,
    "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, sc.name AS sub_name, b.name AS brand_name, u.username AS seller
       FROM products p
       LEFT JOIN category c ON c.id = p.product_category_id
       LEFT JOIN sub_category sc ON sc.id = p.product_sub_category_id
       LEFT JOIN brand_category b ON b.id = p.brand_category_id
       LEFT JOIN users u ON u.uid = p.uid
      WHERE p.slug = ?", 's', [$slug])[0] ?? null);

if (!$p) { http_response_code(404); }

if ($p) {
    $pid = (int)$p['id'];

    /* ---- specification JSON ---- */
    $spec = json_decode($p['specification'] ?? '', true);
    $spec = is_array($spec) ? $spec : [];
    $images = !empty($spec['images']) ? (array)$spec['images'] : (!empty($spec['image']) ? [$spec['image']] : []);
    if (!$images) $images = ['https://placehold.co/600x800/f3e8ff/7b1d8e?text=No+Image'];
    $sizes  = (array)($spec['sizes'] ?? []);
    $colors = (array)($spec['colors'] ?? ($spec['color'] ?? []));
    $extra  = array_diff_key($spec, array_flip(['image', 'images', 'sizes', 'color', 'colors']));

    /* ---- ratings (top-level reviews only) ---- */
    $agg = rows($conn, "SELECT COUNT(*) c, COALESCE(AVG(star),0) a FROM products_reviews WHERE product_id = ? AND parent_id IS NULL", 'i', [$pid])[0];
    $revCount = (int)$agg['c']; $avg = (float)$agg['a'];
    $dist = array_fill(1, 5, 0);
    foreach (rows($conn, "SELECT star, COUNT(*) c FROM products_reviews WHERE product_id = ? AND parent_id IS NULL GROUP BY star", 'i', [$pid]) as $r) $dist[(int)$r['star']] = (int)$r['c'];

    /* ---- reviews page (5 per page) + replies ---- */
    $rPer = 5; $rPages = max(1, (int)ceil($revCount / $rPer));
    $rp = min(max(1, (int)($_GET['rp'] ?? 1)), $rPages);
    $reviews = rows($conn,
        "SELECT r.id, r.uid, r.star, r.msg, r.created_at, u.firstname, u.lastname
           FROM products_reviews r JOIN users u ON u.uid = r.uid
          WHERE r.product_id = ? AND r.parent_id IS NULL ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?",
        'iii', [$pid, $rPer, ($rp - 1) * $rPer]);
    $replies = [];
    if ($reviews) {
        $ids = implode(',', array_map('intval', array_column($reviews, 'id')));
        foreach (rows($conn, "SELECT r.parent_id, r.uid, r.msg, r.created_at, u.firstname, u.lastname
                                FROM products_reviews r JOIN users u ON u.uid = r.uid
                               WHERE r.parent_id IN ($ids) ORDER BY r.created_at ASC") as $rep) $replies[$rep['parent_id']][] = $rep;
    }

    /* ---- related products ---- */
    $related = rows($conn,
        "SELECT p2.id, p2.slug, p2.product_name, p2.final_price, p2.price, p2.off_price, p2.specification
           FROM products p2 WHERE p2.id <> ? AND p2.instock > 0
            AND (p2.product_sub_category_id <=> ? OR p2.product_category_id <=> ?)
          ORDER BY (p2.product_sub_category_id <=> ?) DESC, p2.id DESC LIMIT 8",
        'iiii', [$pid, $p['product_sub_category_id'], $p['product_category_id'], $p['product_sub_category_id']]);

    $inStock = (int)$p['instock'] > 0;
    $maxQty = max(1, min(10, (int)$p['instock']));
    $saving = (float)$p['price'] - (float)$p['final_price'];
    $loggedUid = $_SESSION['uid'] ?? null;
}
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
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<?php include __DIR__ . "/head.php"; ?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<?php if (!$p): ?>
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm py-20 px-6 text-center">
    <div class="mx-auto w-16 h-16 rounded-full bg-gradient-to-tr from-purple-100 to-pink-100 flex items-center justify-center text-pink-500 mb-4"><i class="bi bi-bag-x text-2xl"></i></div>
    <h1 class="text-lg font-bold text-gray-900">Product not found</h1>
    <p class="text-sm text-gray-500 mt-1">It may have been removed or the link is incorrect.</p>
    <a href="category.php" class="inline-block mt-5 px-6 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-white bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:opacity-90">Browse products</a>
  </div>
<?php else: ?>

  <!-- Breadcrumb -->
  <nav class="text-xs text-gray-500 mb-4 flex flex-wrap items-center gap-1.5">
    <a href="/" class="hover:text-pink-600">Home</a><i class="bi bi-chevron-right text-[9px]"></i>
    <?php if ($p['cat_name']): ?><a href="category.php?slug=<?= e($p['cat_slug']) ?>" class="hover:text-pink-600"><?= e($p['cat_name']) ?></a><i class="bi bi-chevron-right text-[9px]"></i><?php endif; ?>
    <span class="font-semibold text-gray-800 truncate max-w-[16rem]"><?= e($p['product_name']) ?></span>
  </nav>

  <!-- ================= MAIN ================= -->
  <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-6 grid grid-cols-1 lg:grid-cols-12 gap-8">

    <!-- Gallery -->
    <div class="lg:col-span-5">
      <div class="relative rounded-2xl overflow-hidden bg-pink-50/50 aspect-[3/4] group">
        <?php if ($p['off_price'] > 0): ?>
          <span class="absolute top-3 left-3 z-10 bg-pink-600 text-white text-xs font-extrabold px-2 py-1 rounded">-<?= (int)$p['off_price'] ?>%</span>
        <?php endif; ?>
        <button onclick="wishlist(<?= $pid ?>)" class="absolute top-3 right-3 z-10 w-9 h-9 bg-white/90 rounded-full flex items-center justify-center text-gray-600 hover:text-pink-600 shadow transition-colors"><i class="bi bi-heart"></i></button>
        <img id="mainImg" src="<?= e($images[0]) ?>" alt="<?= e($p['product_name']) ?>" class="w-full h-full object-cover object-top transition-transform duration-500 group-hover:scale-105">
      </div>
      <?php if (count($images) > 1): ?>
      <div class="grid grid-cols-5 gap-2 mt-3">
        <?php foreach ($images as $i => $img): ?>
          <button type="button" onclick="swapImg(this, '<?= e($img) ?>')" class="thumb rounded-lg overflow-hidden aspect-square border-2 <?= $i === 0 ? 'border-pink-500' : 'border-transparent' ?> hover:border-pink-400 transition-colors">
            <img src="<?= e($img) ?>" alt="" class="w-full h-full object-cover object-top">
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Info -->
    <div class="lg:col-span-7 flex flex-col">
      <?php if ($p['brand_name']): ?><p class="text-xs font-bold uppercase tracking-widest text-pink-600"><?= e($p['brand_name']) ?></p><?php endif; ?>
      <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-gray-900 mt-1"><?= e($p['product_name']) ?></h1>

      <div class="flex flex-wrap items-center gap-3 mt-2 text-sm">
        <?= star_row($avg) ?>
        <a href="#reviews" onclick="showTab('reviews')" class="text-gray-500 hover:text-pink-600"><?= $revCount ? number_format($avg, 1) . ' · ' . $revCount . ' review' . ($revCount === 1 ? '' : 's') : 'No reviews yet' ?></a>
        <?php if ($p['seller']): ?><span class="text-gray-300">|</span><span class="text-gray-500">Sold by <span class="font-semibold text-gray-700"><?= e($p['seller']) ?></span></span><?php endif; ?>
      </div>

      <!-- Price -->
      <div class="mt-5 flex flex-wrap items-end gap-3">
        <span class="text-3xl font-extrabold text-gray-900"><?= money($p['final_price']) ?></span>
        <?php if ($p['off_price'] > 0): ?>
          <span class="text-base text-gray-400 line-through"><?= money($p['price']) ?></span>
          <span class="text-xs font-bold text-green-700 bg-green-50 border border-green-200 rounded-full px-2.5 py-0.5">You save <?= money($saving) ?></span>
        <?php endif; ?>
      </div>
      <p class="text-[11px] text-gray-400 mt-1">Inclusive of all taxes. Free shipping above ₹999.</p>

      <!-- Stock -->
      <p class="mt-3 text-sm font-semibold <?= $inStock ? ((int)$p['instock'] <= 5 ? 'text-amber-600' : 'text-green-600') : 'text-red-600' ?>">
        <i class="bi <?= $inStock ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
        <?= $inStock ? ((int)$p['instock'] <= 5 ? 'Only ' . (int)$p['instock'] . ' left in stock' : 'In stock') : 'Out of stock' ?>
      </p>

      <!-- Colour -->
      <?php if ($colors): ?>
      <div class="mt-5">
        <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Colour: <span class="font-semibold text-gray-600 normal-case"><?= e(implode(', ', $colors)) ?></span></h3>
      </div>
      <?php endif; ?>

      <!-- Size -->
      <?php if ($sizes): ?>
      <div class="mt-4">
        <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Select Size</h3>
        <div class="flex flex-wrap gap-2" id="sizeWrap">
          <?php foreach ($sizes as $s): ?>
            <button type="button" onclick="pickSize(this)" data-size="<?= e($s) ?>" class="size-btn min-w-[2.75rem] px-3 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:border-pink-400 transition-colors"><?= e($s) ?></button>
          <?php endforeach; ?>
        </div>
        <p id="sizeErr" class="hidden text-xs text-red-600 mt-1.5">Please select a size.</p>
      </div>
      <?php endif; ?>

      <!-- Quantity + CTA -->
      <div class="mt-6 flex flex-wrap items-center gap-3">
        <div class="inline-flex items-center border border-gray-200 rounded-xl overflow-hidden <?= $inStock ? '' : 'opacity-50 pointer-events-none' ?>">
          <button type="button" onclick="qty(-1)" class="w-10 h-11 text-gray-600 hover:bg-pink-50 hover:text-pink-600"><i class="bi bi-dash-lg"></i></button>
          <input id="qty" type="number" value="1" min="1" max="<?= $maxQty ?>" readonly class="w-12 h-11 text-center text-sm font-bold text-gray-900 focus:outline-none">
          <button type="button" onclick="qty(1)" class="w-10 h-11 text-gray-600 hover:bg-pink-50 hover:text-pink-600"><i class="bi bi-plus-lg"></i></button>
        </div>
        <button <?= $inStock ? '' : 'disabled' ?> onclick="addToCart(false)" class="flex-1 min-w-[10rem] h-11 rounded-xl text-sm font-bold uppercase tracking-wide text-white bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:from-amber-600 hover:to-purple-700 shadow-lg shadow-pink-500/30 transition-all disabled:opacity-40 disabled:cursor-not-allowed">
          <i class="bi bi-bag-plus mr-1"></i> Add to Cart
        </button>
        <button <?= $inStock ? '' : 'disabled' ?> onclick="addToCart(true)" class="h-11 px-6 rounded-xl text-sm font-bold uppercase tracking-wide text-pink-600 border-2 border-pink-500 hover:bg-pink-600 hover:text-white transition-colors disabled:opacity-40 disabled:cursor-not-allowed">Buy Now</button>
      </div>
      <p id="cartMsg" class="hidden mt-2 text-xs font-semibold"></p>

      <!-- Trust badges -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-7 pt-6 border-t border-gray-100">
        <?php foreach ([['bi-truck','Free Shipping','Above ₹999'],['bi-arrow-repeat','Easy Returns','7 days return & exchange'],['bi-shield-check','Secure Payment','100% safe & secure']] as $t): ?>
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-gradient-to-tr from-purple-100 to-pink-100 text-pink-600 flex items-center justify-center flex-shrink-0"><i class="bi <?= $t[0] ?>"></i></div>
          <div><h4 class="text-[11px] font-bold uppercase tracking-wider text-gray-900"><?= $t[1] ?></h4><p class="text-[11px] text-gray-500"><?= $t[2] ?></p></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ================= TABS ================= -->
  <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mt-6">
    <div class="flex border-b border-gray-100 overflow-x-auto">
      <?php foreach (['description' => 'Description', 'specs' => 'Specifications', 'reviews' => 'Reviews (' . $revCount . ')'] as $k => $lbl): ?>
        <button type="button" data-tab="<?= $k ?>" onclick="showTab('<?= $k ?>')" class="tab-btn px-5 sm:px-8 py-4 text-xs sm:text-sm font-bold uppercase tracking-wider whitespace-nowrap border-b-2 border-transparent text-gray-500 hover:text-pink-600 transition-colors"><?= $lbl ?></button>
      <?php endforeach; ?>
    </div>

    <!-- Description -->
    <div id="tab-description" class="tab-panel p-5 sm:p-8">
      <?php if (!empty(trim((string)$p['description']))): ?>
        <div class="text-sm text-gray-600 leading-relaxed max-w-3xl"><?= nl2br(e($p['description'])) ?></div>
      <?php else: ?><p class="text-sm text-gray-400">No description available.</p><?php endif; ?>
      <?php if (!empty($p['keywords'])): ?>
        <div class="flex flex-wrap gap-2 mt-5">
          <?php foreach (array_filter(array_map('trim', explode(',', $p['keywords']))) as $kw): ?>
            <a href="category.php?q=<?= urlencode($kw) ?>" class="px-3 py-1 rounded-full bg-pink-50 text-pink-700 border border-pink-200 text-[11px] font-semibold hover:bg-pink-100 transition-colors">#<?= e($kw) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Specs -->
    <div id="tab-specs" class="tab-panel hidden p-5 sm:p-8">
      <dl class="max-w-2xl divide-y divide-gray-100 text-sm">
        <?php
        $specRows = [];
        if ($p['brand_name']) $specRows['Brand'] = $p['brand_name'];
        if ($p['cat_name'])   $specRows['Category'] = $p['cat_name'];
        if ($p['sub_name'])   $specRows['Type'] = $p['sub_name'];
        if ($colors)          $specRows['Colour'] = implode(', ', $colors);
        if ($sizes)           $specRows['Sizes'] = implode(', ', $sizes);
        foreach ($extra as $k => $v) $specRows[ucwords(str_replace('_', ' ', $k))] = is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v;
        foreach ($specRows as $k => $v): ?>
          <div class="grid grid-cols-3 gap-4 py-2.5"><dt class="font-semibold text-gray-500"><?= e($k) ?></dt><dd class="col-span-2 text-gray-800"><?= e($v) ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </div>

    <!-- Reviews -->
    <div id="tab-reviews" class="tab-panel hidden p-5 sm:p-8">
      <div class="grid grid-cols-1 md:grid-cols-12 gap-8">
        <div class="md:col-span-4">
          <div class="text-center md:text-left">
            <div class="text-5xl font-extrabold text-gray-900"><?= number_format($avg, 1) ?></div>
            <div class="mt-1"><?= star_row($avg, 'text-base') ?></div>
            <p class="text-xs text-gray-500 mt-1">Based on <?= $revCount ?> review<?= $revCount === 1 ? '' : 's' ?></p>
          </div>
          <div class="mt-4 space-y-1.5">
            <?php for ($s = 5; $s >= 1; $s--): $pc = $revCount ? round($dist[$s] / $revCount * 100) : 0; ?>
              <div class="flex items-center gap-2 text-[11px] text-gray-600">
                <span class="w-6"><?= $s ?>★</span>
                <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-gradient-to-r from-amber-400 to-pink-500" style="width:<?= $pc ?>%"></div></div>
                <span class="w-6 text-right"><?= $dist[$s] ?></span>
              </div>
            <?php endfor; ?>
          </div>

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
                <button class="px-5 py-2 rounded-lg text-xs font-bold uppercase tracking-wider text-white bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:opacity-90">Submit review</button>
              </form>
            <?php else: ?>
              <p class="text-sm text-gray-500"><a href="/login" class="font-bold text-pink-600 hover:underline">Log in</a> to write a review.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="md:col-span-8">
          <?php if (!$reviews): ?>
            <p class="text-sm text-gray-400">Be the first to review this product.</p>
          <?php else: foreach ($reviews as $r): ?>
            <article class="py-4 border-b border-gray-100 last:border-0">
              <div class="flex items-center gap-3">
                <span class="w-9 h-9 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white text-xs font-bold flex items-center justify-center"><?= e(strtoupper(mb_substr($r['firstname'], 0, 1))) ?></span>
                <div>
                  <p class="text-sm font-bold text-gray-800"><?= e($r['firstname'] . ' ' . mb_substr($r['lastname'], 0, 1) . '.') ?></p>
                  <div class="flex items-center gap-2"><?= star_row($r['star'], 'text-[11px]') ?><span class="text-[11px] text-gray-400"><?= e(date('d M Y', strtotime($r['created_at']))) ?></span></div>
                </div>
              </div>
              <p class="text-sm text-gray-600 mt-2.5 leading-relaxed"><?= nl2br(e($r['msg'])) ?></p>
              <?php foreach ($replies[$r['id']] ?? [] as $rep): $isSeller = ($rep['uid'] === $p['uid']); ?>
                <div class="mt-3 ml-6 pl-4 border-l-2 border-pink-200 bg-pink-50/40 rounded-r-lg py-2 pr-3">
                  <p class="text-xs font-bold text-gray-800"><?= $isSeller ? 'Seller response' : e($rep['firstname']) ?> <span class="font-normal text-gray-400">· <?= e(date('d M Y', strtotime($rep['created_at']))) ?></span></p>
                  <p class="text-sm text-gray-600 mt-0.5"><?= nl2br(e($rep['msg'])) ?></p>
                </div>
              <?php endforeach; ?>
            </article>
          <?php endforeach; ?>

          <?php if ($rPages > 1): ?>
            <nav class="flex items-center justify-center gap-1.5 mt-5">
              <?php for ($i = 1; $i <= $rPages; $i++): ?>
                <a href="product.php?slug=<?= urlencode($p['slug']) ?>&rp=<?= $i ?>#reviews" class="min-w-[2.25rem] h-9 inline-flex items-center justify-center rounded-lg text-xs font-bold border transition-colors <?= $i === $rp ? 'bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 border-transparent text-white shadow-md' : 'bg-white border-gray-200 text-gray-700 hover:border-pink-400 hover:text-pink-600' ?>"><?= $i ?></a>
              <?php endfor; ?>
            </nav>
          <?php endif; endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= RELATED ================= -->
  <?php if ($related): ?>
  <section class="mt-10">
    <div class="flex justify-between items-center mb-5">
      <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-gray-900 uppercase">You May Also Like</h2>
      <?php if ($p['cat_slug']): ?><a href="category.php?slug=<?= e($p['cat_slug']) ?>" class="text-xs sm:text-sm font-semibold text-gray-700 hover:text-pink-600 flex items-center gap-1 uppercase tracking-wider transition-colors">View All <i class="bi bi-arrow-right"></i></a><?php endif; ?>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-8 gap-3 sm:gap-4">
      <?php foreach ($related as $rp2): ?>
      <a href="product.php?slug=<?= e($rp2['slug']) ?>" class="bg-white rounded-xl overflow-hidden border border-gray-100 shadow-sm hover:shadow-md transition-shadow p-2 flex flex-col justify-between">
        <div class="relative rounded-lg overflow-hidden bg-rose-50/40 aspect-[3/4]">
          <?php if ($rp2['off_price'] > 0): ?><span class="absolute top-2 left-2 z-10 bg-pink-600 text-white text-[9px] font-extrabold px-1.5 py-0.5 rounded">-<?= (int)$rp2['off_price'] ?>%</span><?php endif; ?>
          <img src="<?= e(first_image($rp2['specification'])) ?>" alt="<?= e($rp2['product_name']) ?>" loading="lazy" class="w-full h-full object-cover object-top">
        </div>
        <div class="mt-2 text-center">
          <h3 class="text-[11px] font-medium text-gray-700 truncate"><?= e($rp2['product_name']) ?></h3>
          <p class="text-xs font-bold text-gray-900 mt-0.5"><?= money($rp2['final_price']) ?></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

<?php endif; ?>
</main>

<?php include __DIR__ . "/footer.php"; ?>

<?php if ($p): ?>
<script>
  const PID = <?= $pid ?>, MAXQ = <?= $maxQty ?>, HAS_SIZES = <?= $sizes ? 'true' : 'false' ?>;
  let chosenSize = null;

  function swapImg(btn, src) {
    document.getElementById('mainImg').src = src;
    document.querySelectorAll('.thumb').forEach(t => { t.classList.replace('border-pink-500', 'border-transparent'); });
    btn.classList.replace('border-transparent', 'border-pink-500');
  }
  function pickSize(btn) {
    chosenSize = btn.dataset.size;
    document.querySelectorAll('.size-btn').forEach(b => b.className = b.className.replace(/border-pink-500 bg-pink-50 text-pink-700/g, 'border-gray-200 text-gray-700'));
    btn.className = btn.className.replace('border-gray-200 text-gray-700', 'border-pink-500 bg-pink-50 text-pink-700');
    document.getElementById('sizeErr').classList.add('hidden');
  }
  function qty(d) {
    const i = document.getElementById('qty');
    i.value = Math.min(MAXQ, Math.max(1, (parseInt(i.value) || 1) + d));
  }
  function msg(text, ok) {
    const m = document.getElementById('cartMsg');
    m.textContent = text; m.className = 'mt-2 text-xs font-semibold ' + (ok ? 'text-green-600' : 'text-red-600');
  }
  // Note: cart_items has no size column, so size is sent but only stored if your cart.php handles it.
  function addToCart(buyNow) {
    if (HAS_SIZES && !chosenSize) { document.getElementById('sizeErr').classList.remove('hidden'); return; }
    const body = 'product_id=' + PID + '&quantity=' + document.getElementById('qty').value + (chosenSize ? '&size=' + encodeURIComponent(chosenSize) : '');
    fetch('cart.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body })
      .then(r => r.json()).catch(() => ({}))
      .then(d => {
        if (d && d.login) { window.location.href = '/login'; return; }
        if (buyNow) { window.location.href = '/cart'; return; }
        msg('Added to your cart.', true);
      })
      .catch(() => msg('Could not add to cart. Please try again.', false));
  }
  function wishlist(id) {
    fetch('wishlist.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'product_id=' + id })
      .then(r => r.json()).catch(() => ({}))
      .then(d => { if (d && d.login) window.location.href = '/login'; });
  }

  function showTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('hidden', p.id !== 'tab-' + name));
    document.querySelectorAll('.tab-btn').forEach(b => {
      const on = b.dataset.tab === name;
      b.classList.toggle('border-pink-500', on); b.classList.toggle('text-pink-600', on);
      b.classList.toggle('border-transparent', !on); b.classList.toggle('text-gray-500', !on);
    });
  }
  showTab(location.hash === '#reviews' || new URLSearchParams(location.search).has('rp') ? 'reviews' : 'description');
</script>
<?php endif; ?>
</body>
</html>