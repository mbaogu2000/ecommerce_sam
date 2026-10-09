<?php
include __DIR__ . "/config.php";

/* =====================================================================
   category  -  ONE page for:
     category?slug=women          -> category page
     category?q=dress             -> search page
     category?inv=trending-now    -> inventory page
     category                     -> all products
   Filters: sub[] brand[] min max rating discount stock sort page
   Read-only: no DB changes.
   ===================================================================== */

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n) { return '₹' . number_format((float)$n, 0); }
function product_image($row) {
    $fb = 'https://placehold.co/300x400/f3e8ff/7b1d8e?text=No+Image';
    $s = json_decode($row['specification'] ?? '', true);
    if (!is_array($s)) return $fb;
    return $s['image'] ?? ($s['images'][0] ?? $fb);
}
function rows($conn, $sql, $types = '', $params = []) {
    $s = $conn->prepare($sql);
    if (!$s) return [];
    if ($params) $s->bind_param($types, ...$params);
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}
// Build a URL from current query string with overrides (null removes a key)
function qs($over = []) {
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) if ($v === '' || $v === null || $v === []) unset($q[$k]);
    return 'category' . ($q ? '?' . http_build_query($q) : '');
}

/* ---------- input ---------- */
$slug    = trim($_GET['slug'] ?? '');
$search  = trim($_GET['q'] ?? '');
$invSlug = trim($_GET['inv'] ?? '');
$fSub    = array_values(array_filter(array_map('intval', (array)($_GET['sub'] ?? []))));
$fBrand  = array_values(array_filter(array_map('intval', (array)($_GET['brand'] ?? []))));
$fMin    = ($_GET['min'] ?? '') !== '' ? max(0, (float)$_GET['min']) : null;
$fMax    = ($_GET['max'] ?? '') !== '' ? max(0, (float)$_GET['max']) : null;
$fRating = in_array((int)($_GET['rating'] ?? 0), [3, 4]) ? (int)$_GET['rating'] : 0;
$fDisc   = in_array((int)($_GET['discount'] ?? 0), [10, 25, 50]) ? (int)$_GET['discount'] : 0;
$fStock  = !empty($_GET['stock']);
$sort    = $_GET['sort'] ?? 'popular';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$orders = [
  'popular'    => 'review_count DESC, p.id DESC',
  'new'        => 'p.created_at DESC',
  'price_asc'  => 'p.final_price ASC',
  'price_desc' => 'p.final_price DESC',
  'discount'   => 'p.off_price DESC',
  'rating'     => 'avg_star DESC, review_count DESC',
];
if (!isset($orders[$sort])) $sort = 'popular';

/* ---------- context (category / inventory / search) ---------- */
$join = ''; $where = ['1=1']; $types = ''; $params = [];
$title = 'All Products'; $desc = ''; $mode = 'all'; $notFound = false; $activeCatId = 0;

if ($invSlug !== '') {
    $mode = 'inventory';
    $inv = rows($conn, "SELECT id, name, description FROM inventories WHERE slug = ?
                        AND (time_start IS NULL OR time_start <= NOW()) AND (time_end IS NULL OR time_end >= NOW())", 's', [$invSlug])[0] ?? null;
    if ($inv) {
        $title = $inv['name']; $desc = $inv['description'] ?? '';
        $join .= " JOIN product_inventories pi ON pi.product_id = p.id AND pi.inventory_id = ?
                   AND (pi.time_start IS NULL OR pi.time_start <= NOW()) AND (pi.time_end IS NULL OR pi.time_end >= NOW())";
        $types .= 'i'; $params[] = (int)$inv['id'];
    } else { $notFound = true; $title = 'Collection not found'; }
}
if ($slug !== '') {
    $mode = 'category';
    $cat = rows($conn, "SELECT id, name, description FROM category WHERE slug = ?", 's', [$slug])[0] ?? null;
    if ($cat) {
        $title = $cat['name']; $desc = $cat['description'] ?? ''; $activeCatId = (int)$cat['id'];
        $where[] = 'p.product_category_id = ?'; $types .= 'i'; $params[] = $activeCatId;
    } else { $notFound = true; $title = 'Category not found'; }
}
if ($search !== '') {
    $mode = 'search'; $title = 'Results for "' . $search . '"';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where[] = '(p.product_name LIKE ? OR p.keywords LIKE ? OR p.description LIKE ?)';
    $types .= 'sss'; array_push($params, $like, $like, $like);
}
$ctxWhere = implode(' AND ', $where);
$ctxTypes = $types; $ctxParams = $params;

/* ---------- filters ---------- */
$fw = []; $ft = ''; $fp = [];
if ($fSub)   { $fw[] = 'p.product_sub_category_id IN (' . implode(',', $fSub) . ')'; }
if ($fBrand) { $fw[] = 'p.brand_category_id IN (' . implode(',', $fBrand) . ')'; }
if ($fMin !== null) { $fw[] = 'p.final_price >= ?'; $ft .= 'd'; $fp[] = $fMin; }
if ($fMax !== null) { $fw[] = 'p.final_price <= ?'; $ft .= 'd'; $fp[] = $fMax; }
if ($fRating) { $fw[] = 'COALESCE(r.avg_star,0) >= ?'; $ft .= 'i'; $fp[] = $fRating; }
if ($fDisc)   { $fw[] = 'p.off_price >= ?'; $ft .= 'i'; $fp[] = $fDisc; }
if ($fStock)  { $fw[] = 'p.instock > 0'; }
$fullWhere = $ctxWhere . ($fw ? ' AND ' . implode(' AND ', $fw) : '');
$allTypes = $ctxTypes . $ft; $allParams = array_merge($ctxParams, $fp);

$rJoin = "LEFT JOIN (SELECT product_id, AVG(star) avg_star, COUNT(*) review_count
                       FROM products_reviews WHERE parent_id IS NULL GROUP BY product_id) r ON r.product_id = p.id";

/* ---------- data ---------- */
$total = 0; $products = []; $subFacets = []; $brandFacets = [];
if (!$notFound) {
    $total = (int)(rows($conn, "SELECT COUNT(*) c FROM products p $rJoin $join WHERE $fullWhere", $allTypes, $allParams)[0]['c'] ?? 0);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $products = rows($conn,
        "SELECT p.id, p.slug, p.product_name, p.price, p.off_price, p.final_price, p.specification, p.instock,
                COALESCE(r.avg_star,0) avg_star, COALESCE(r.review_count,0) review_count
           FROM products p $rJoin $join WHERE $fullWhere
          ORDER BY {$orders[$sort]} LIMIT ? OFFSET ?",
        $allTypes . 'ii', array_merge($allParams, [$perPage, ($page - 1) * $perPage]));
    $subFacets = rows($conn, "SELECT sc.id, sc.name, COUNT(*) c FROM products p $join
                              JOIN sub_category sc ON sc.id = p.product_sub_category_id WHERE $ctxWhere
                              GROUP BY sc.id, sc.name ORDER BY sc.name", $ctxTypes, $ctxParams);
    $brandFacets = rows($conn, "SELECT b.id, b.name, COUNT(*) c FROM products p $join
                                JOIN brand_category b ON b.id = p.brand_category_id WHERE $ctxWhere
                                GROUP BY b.id, b.name ORDER BY b.name", $ctxTypes, $ctxParams);
} else { $pages = 1; }
$allCats = rows($conn, "SELECT slug, name FROM category ORDER BY name LIMIT 30");

/* ---------- active filter chips ---------- */
$chips = [];
$subNames = array_column($subFacets, 'name', 'id'); $brandNames = array_column($brandFacets, 'name', 'id');
foreach ($fSub as $id)   $chips[] = [$subNames[$id] ?? 'Sub category', qs(['sub' => array_values(array_diff($fSub, [$id])), 'page' => null])];
foreach ($fBrand as $id) $chips[] = [$brandNames[$id] ?? 'Brand', qs(['brand' => array_values(array_diff($fBrand, [$id])), 'page' => null])];
if ($fMin !== null || $fMax !== null) $chips[] = ['Price ' . ($fMin !== null ? money($fMin) : '₹0') . ' - ' . ($fMax !== null ? money($fMax) : 'Any'), qs(['min' => null, 'max' => null, 'page' => null])];
if ($fRating) $chips[] = [$fRating . '★ & up', qs(['rating' => null, 'page' => null])];
if ($fDisc)   $chips[] = [$fDisc . '% off or more', qs(['discount' => null, 'page' => null])];
if ($fStock)  $chips[] = ['In stock only', qs(['stock' => null, 'page' => null])];

$activeFilterCount = count($chips);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> | <?= e($company['title']) ?></title>
  <link href="./src/output.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<?php include __DIR__ . "/head.php"; ?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

  <!-- Breadcrumb -->
  <nav class="text-xs text-gray-500 mb-3 flex items-center gap-1.5">
    <a href="/" class="hover:text-pink-600">Home</a><i class="bi bi-chevron-right text-[9px]"></i>
    <?php if ($mode === 'category'): ?><a href="/category" class="hover:text-pink-600">Shop</a><i class="bi bi-chevron-right text-[9px]"></i><?php endif; ?>
    <span class="font-semibold text-gray-800"><?= e($title) ?></span>
  </nav>

  <!-- Title banner -->
  <div class="rounded-2xl bg-gradient-to-r from-indigo-900 via-purple-700 to-pink-500 text-white px-6 py-5 mb-5 shadow-md">
    <h1 class="text-xl sm:text-2xl font-extrabold uppercase tracking-wide"><?= e($title) ?></h1>
    <?php if ($desc): ?><p class="text-xs sm:text-sm text-white/80 mt-1"><?= e($desc) ?></p><?php endif; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

    <!-- ============ ASIDE ============ -->
    <aside id="aside" class="hidden lg:block lg:col-span-3 xl:col-span-3">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-5 lg:sticky lg:top-4 max-h-[calc(100vh-2rem)] overflow-y-auto">

        <div>
          <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Category</h3>
          <ul class="space-y-1 text-sm">
            <li><a href="category" class="block px-2 py-1 rounded-md hover:text-pink-600 <?= $mode === 'all' ? 'text-pink-600 font-bold' : 'text-gray-700' ?>">All Products</a></li>
            <?php foreach ($allCats as $c): $on = ($slug === $c['slug']); ?>
              <li><a href="category?slug=<?= e($c['slug']) ?>" class="block px-2 py-1 rounded-md <?= $on ? 'bg-pink-50 text-pink-600 font-bold' : 'text-gray-700 hover:text-pink-600' ?>"><?= e($c['name']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>

        <form id="filterForm" method="get" action="category" class="space-y-5">
          <?php if ($slug !== ''): ?><input type="hidden" name="slug" value="<?= e($slug) ?>"><?php endif; ?>
          <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
          <?php if ($invSlug !== ''): ?><input type="hidden" name="inv" value="<?= e($invSlug) ?>"><?php endif; ?>

          <?php if (count($subFacets) > 0): ?>
          <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Sub Category</h3>
            <div class="space-y-1.5 max-h-44 overflow-y-auto pr-1">
              <?php foreach ($subFacets as $s): ?>
              <label class="flex items-center justify-between text-sm text-gray-700 cursor-pointer">
                <span class="flex items-center gap-2"><input type="checkbox" name="sub[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $fSub) ? 'checked' : '' ?> class="accent-pink-600"><?= e($s['name']) ?></span>
                <span class="text-[11px] text-gray-400"><?= (int)$s['c'] ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (count($brandFacets) > 0): ?>
          <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Brand</h3>
            <div class="space-y-1.5 max-h-44 overflow-y-auto pr-1">
              <?php foreach ($brandFacets as $b): ?>
              <label class="flex items-center justify-between text-sm text-gray-700 cursor-pointer">
                <span class="flex items-center gap-2"><input type="checkbox" name="brand[]" value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $fBrand) ? 'checked' : '' ?> class="accent-pink-600"><?= e($b['name']) ?></span>
                <span class="text-[11px] text-gray-400"><?= (int)$b['c'] ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Price (₹)</h3>
            <div class="flex items-center gap-2">
              <input type="number" min="0" name="min" value="<?= $fMin !== null ? e($fMin) : '' ?>" placeholder="Min" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:border-pink-500">
              <span class="text-gray-400">-</span>
              <input type="number" min="0" name="max" value="<?= $fMax !== null ? e($fMax) : '' ?>" placeholder="Max" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:border-pink-500">
            </div>
          </div>

          <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Customer Rating</h3>
            <?php foreach ([4 => '4★ & up', 3 => '3★ & up'] as $v => $lbl): ?>
            <label class="flex items-center gap-2 text-sm text-gray-700 mb-1.5 cursor-pointer"><input type="radio" name="rating" value="<?= $v ?>" <?= $fRating === $v ? 'checked' : '' ?> class="accent-pink-600"><span class="text-amber-400"><?= $lbl ?></span></label>
            <?php endforeach; ?>
          </div>

          <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-extrabold uppercase tracking-wider text-gray-900 mb-2">Discount</h3>
            <?php foreach ([50 => '50% or more', 25 => '25% or more', 10 => '10% or more'] as $v => $lbl): ?>
            <label class="flex items-center gap-2 text-sm text-gray-700 mb-1.5 cursor-pointer"><input type="radio" name="discount" value="<?= $v ?>" <?= $fDisc === $v ? 'checked' : '' ?> class="accent-pink-600"><?= $lbl ?></label>
            <?php endforeach; ?>
          </div>

          <div class="border-t border-gray-100 pt-4">
            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" name="stock" value="1" <?= $fStock ? 'checked' : '' ?> class="accent-pink-600">In stock only</label>
          </div>

          <div class="flex gap-2">
            <button type="submit" class="flex-1 py-2 rounded-lg text-xs font-bold uppercase tracking-wider text-white bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:opacity-90 transition-opacity">Apply</button>
            <?php if ($activeFilterCount): ?>
              <a href="<?= e(qs(['sub' => null, 'brand' => null, 'min' => null, 'max' => null, 'rating' => null, 'discount' => null, 'stock' => null, 'page' => null])) ?>" class="px-3 py-2 rounded-lg text-xs font-bold uppercase border border-gray-200 text-gray-600 hover:text-pink-600 hover:border-pink-300 transition-colors">Clear</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </aside>

    <!-- ============ RESULTS ============ -->
    <section class="lg:col-span-9">

      <!-- Toolbar -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm px-4 py-3 mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <button type="button" onclick="document.getElementById('aside').classList.toggle('hidden')" class="lg:hidden inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-bold uppercase text-gray-700 hover:text-pink-600">
            <i class="bi bi-funnel"></i> Filters<?= $activeFilterCount ? ' (' . $activeFilterCount . ')' : '' ?>
          </button>
          <p class="text-sm text-gray-600"><span class="font-bold text-gray-900"><?= number_format($total) ?></span> product<?= $total === 1 ? '' : 's' ?> found</p>
        </div>
        <label class="flex items-center gap-2 text-xs text-gray-600">
          <span class="font-semibold uppercase tracking-wider">Sort by</span>
          <select name="sort" form="filterForm" onchange="this.form.submit()" class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-gray-800 focus:outline-none focus:border-pink-500">
            <?php foreach (['popular' => 'Popularity', 'new' => 'Newest', 'price_asc' => 'Price: Low to High', 'price_desc' => 'Price: High to Low', 'discount' => 'Biggest Discount', 'rating' => 'Top Rated'] as $v => $lbl): ?>
              <option value="<?= $v ?>" <?= $sort === $v ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <!-- Active chips -->
      <?php if ($chips): ?>
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <?php foreach ($chips as $c): ?>
          <a href="<?= e($c[1]) ?>" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-pink-50 text-pink-700 border border-pink-200 text-[11px] font-semibold hover:bg-pink-100 transition-colors"><?= e($c[0]) ?> <i class="bi bi-x"></i></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!$products): ?>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm py-16 px-6 text-center">
          <div class="mx-auto w-16 h-16 rounded-full bg-gradient-to-tr from-purple-100 to-pink-100 flex items-center justify-center text-pink-500 mb-4"><i class="bi bi-search text-2xl"></i></div>
          <h2 class="text-lg font-bold text-gray-900">No products found</h2>
          <p class="text-sm text-gray-500 mt-1">Try removing some filters or searching for something else.</p>
          <a href="category" class="inline-block mt-5 px-6 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider text-white bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:opacity-90">Browse all products</a>
        </div>
      <?php else: ?>
      <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 sm:gap-4">
        <?php foreach ($products as $p): $out = ((int)$p['instock'] <= 0); $avg = (int)round($p['avg_star']); ?>
        <div class="bg-white rounded-xl overflow-hidden border border-gray-100 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between p-2">
          <div class="relative rounded-lg overflow-hidden bg-pink-50/50 aspect-[3/4]">
            <?php if ($p['off_price'] > 0): ?>
              <span class="absolute top-2 left-2 z-10 bg-pink-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded">-<?= (int)$p['off_price'] ?>%</span>
            <?php endif; ?>
            <button onclick="wishlist(<?= (int)$p['id'] ?>)" class="absolute top-2 right-2 z-10 w-7 h-7 bg-white/80 rounded-full flex items-center justify-center text-gray-600 hover:text-pink-600 transition-colors"><i class="bi bi-heart text-xs"></i></button>
            <a href="product?slug=<?= e($p['slug']) ?>">
              <img src="<?= e(product_image($p)) ?>" alt="<?= e($p['product_name']) ?>" loading="lazy" class="w-full h-full object-cover object-top <?= $out ? 'opacity-50' : '' ?>">
            </a>
            <?php if ($out): ?><span class="absolute bottom-2 left-2 z-10 bg-gray-800 text-white text-[10px] font-bold px-2 py-0.5 rounded">Out of stock</span>
            <?php elseif ($p['instock'] <= 5): ?><span class="absolute bottom-2 left-2 z-10 bg-amber-500 text-white text-[10px] font-bold px-2 py-0.5 rounded"><?= (int)$p['instock'] ?> left</span><?php endif; ?>
          </div>
          <div class="mt-2 flex flex-col flex-grow justify-between">
            <div>
              <a href="product?slug=<?= e($p['slug']) ?>" class="block text-[12px] font-semibold text-gray-700 hover:text-pink-600 truncate"><?= e($p['product_name']) ?></a>
              <div class="flex items-center gap-1.5 mt-1">
                <span class="text-sm font-bold text-gray-900"><?= money($p['final_price']) ?></span>
                <?php if ($p['off_price'] > 0): ?><span class="text-[11px] text-gray-400 line-through"><?= money($p['price']) ?></span><?php endif; ?>
              </div>
              <div class="flex items-center gap-0.5 text-[10px] text-amber-400 mt-1">
                <?php for ($i = 1; $i <= 5; $i++): ?><i class="bi <?= $i <= $avg ? 'bi-star-fill' : 'bi-star' ?>"></i><?php endfor; ?>
                <span class="text-gray-400 ml-0.5">(<?= (int)$p['review_count'] ?>)</span>
              </div>
            </div>
            <button <?= $out ? 'disabled' : '' ?> onclick="addToCart(<?= (int)$p['id'] ?>)" class="w-full mt-3 py-1.5 border rounded-md text-[10px] font-bold uppercase transition-colors <?= $out ? 'border-gray-200 text-gray-400 cursor-not-allowed' : 'border-pink-500 text-pink-600 hover:bg-pink-600 hover:text-white' ?>"><?= $out ? 'Unavailable' : 'Add to Cart' ?></button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if ($pages > 1):
        $from = max(1, $page - 2); $to = min($pages, $page + 2);
        $btn = 'min-w-[2.25rem] h-9 px-2 inline-flex items-center justify-center rounded-lg text-xs font-bold border transition-colors ';
        $off = 'bg-white border-gray-200 text-gray-700 hover:border-pink-400 hover:text-pink-600';
        $on  = 'bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 border-transparent text-white shadow-md';
      ?>
      <nav class="mt-8 flex flex-wrap items-center justify-center gap-1.5" aria-label="Pagination">
        <?php if ($page > 1): ?><a href="<?= e(qs(['page' => $page - 1])) ?>" class="<?= $btn . $off ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
        <?php if ($from > 1): ?>
          <a href="<?= e(qs(['page' => 1])) ?>" class="<?= $btn . $off ?>">1</a>
          <?php if ($from > 2): ?><span class="px-1 text-gray-400">…</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $from; $i <= $to; $i++): ?>
          <a href="<?= e(qs(['page' => $i])) ?>" class="<?= $btn . ($i === $page ? $on : $off) ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($to < $pages): ?>
          <?php if ($to < $pages - 1): ?><span class="px-1 text-gray-400">…</span><?php endif; ?>
          <a href="<?= e(qs(['page' => $pages])) ?>" class="<?= $btn . $off ?>"><?= $pages ?></a>
        <?php endif; ?>
        <?php if ($page < $pages): ?><a href="<?= e(qs(['page' => $page + 1])) ?>" class="<?= $btn . $off ?>"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
      </nav>
      <p class="text-center text-[11px] text-gray-400 mt-2">Page <?= $page ?> of <?= $pages ?></p>
      <?php endif; ?>
      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . "/footer.php"; ?>

<script>
  function wishlist(productId) {
    fetch('wishlist.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'product_id=' + productId })
      .then(r => r.json()).catch(() => ({}))
      .then(d => { if (d && d.login) window.location.href = '/login'; });
  }
  function addToCart(productId) {
    fetch('cart.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'product_id=' + productId + '&quantity=1' })
      .then(r => r.json()).catch(() => ({}))
      .then(d => { if (d && d.login) window.location.href = '/login'; });
  }
</script>
</body>
</html>