<?php
include __DIR__ . "/config.php";
/* ---------- helpers (read-only, no DB changes) ---------- */
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n) { return '₹' . number_format((float)$n, 0); }

// Image comes from products.specification JSON (image / images[0]); placeholder otherwise
function product_image($row) {
    $fallback = 'https://placehold.co/300x400/f3e8ff/7b1d8e?text=No+Image';
    if (empty($row['specification'])) return $fallback;
    $spec = json_decode($row['specification'], true);
    if (!is_array($spec)) return $fallback;
    if (!empty($spec['image'])) return $spec['image'];
    if (!empty($spec['images'][0])) return $spec['images'][0];
    return $fallback;
}

function stars($avg, $count) {
    $avg = (int)round($avg);
    $h = '<div class="flex items-center gap-0.5 text-[10px] text-amber-400 mt-1">';
    for ($i = 1; $i <= 5; $i++) $h .= '<i class="bi ' . ($i <= $avg ? 'bi-star-fill' : 'bi-star') . '"></i>';
    return $h . '<span class="text-gray-400 ml-0.5">(' . (int)$count . ')</span></div>';
}

function price_block($p) {
    $h = '<div class="flex items-center gap-1 mt-1"><span class="text-xs font-bold text-gray-900">' . money($p['final_price']) . '</span>';
    if ($p['off_price'] > 0) $h .= '<span class="text-[10px] text-gray-400 line-through">' . money($p['price']) . '</span>';
    return $h . '</div>';
}

// Ads renderer - override by defining your own ads() in config.php
if (!function_exists('ads')) {
    function ads($id) {
        echo '<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4"><div class="rounded-2xl bg-gradient-to-r from-indigo-900 via-purple-600 to-pink-500 text-white p-6 text-center shadow-md">
              <p class="text-[10px] uppercase tracking-widest text-white/70">Sponsored</p>
              <h3 class="text-xl font-extrabold uppercase">Special Offer #' . (int)$id . '</h3></div></section>';
    }
}

function section_header($title, $href) {
    echo '<div class="flex justify-between items-center mb-6">
      <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-gray-900 uppercase">' . e($title) . '</h2>
      <a href="' . e($href) . '" class="text-xs sm:text-sm font-semibold text-gray-700 hover:text-pink-600 flex items-center gap-1 uppercase tracking-wider transition-colors">View All <i class="bi bi-arrow-right"></i></a></div>';
}

// Style 1 card: badge, wishlist, price, rating, add to cart
function card_trending($p) { ?>
  <div class="bg-white rounded-xl overflow-hidden border border-gray-100 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between p-2">
    <div class="relative rounded-lg overflow-hidden bg-pink-50/50 aspect-[3/4]">
      <?php if ($p['off_price'] > 0): ?>
        <span class="absolute top-2 left-2 z-10 bg-pink-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded">-<?= (int)$p['off_price'] ?>%</span>
      <?php endif; ?>
      <button onclick="wishlist(<?= (int)$p['id'] ?>)" class="absolute top-2 right-2 z-10 w-7 h-7 bg-white/80 rounded-full flex items-center justify-center text-gray-600 hover:text-pink-600 transition-colors">
        <i class="bi bi-heart text-xs"></i>
      </button>
      <a href="product.php?slug=<?= e($p['slug']) ?>"><img src="<?= e(product_image($p)) ?>" alt="<?= e($p['product_name']) ?>" class="w-full h-full object-cover object-top"></a>
    </div>
    <div class="mt-2 flex flex-col flex-grow justify-between">
      <div>
        <h3 class="text-[11px] font-semibold text-gray-700 truncate"><?= e($p['product_name']) ?></h3>
        <?= price_block($p) ?>
        <?= stars($p['avg_star'], $p['review_count']) ?>
      </div>
      <button onclick="addToCart(<?= (int)$p['id'] ?>)" class="w-full mt-3 py-1.5 border border-pink-500 text-pink-600 hover:bg-pink-600 hover:text-white rounded-md text-[10px] font-bold uppercase transition-colors">Add to Cart</button>
    </div>
  </div>
<?php }

// Style 2 card: NEW badge, centered
function card_new($p) { ?>
  <a href="product.php?slug=<?= e($p['slug']) ?>" class="bg-white rounded-xl overflow-hidden border border-gray-100 shadow-sm hover:shadow-md transition-shadow p-2 flex flex-col justify-between">
    <div class="relative rounded-lg overflow-hidden bg-rose-50/40 aspect-[3/4]">
      <span class="absolute top-2 left-2 z-10 bg-orange-500 text-white text-[9px] font-extrabold px-1.5 py-0.5 rounded uppercase">New</span>
      <img src="<?= e(product_image($p)) ?>" alt="<?= e($p['product_name']) ?>" class="w-full h-full object-cover object-top">
    </div>
    <div class="mt-2 text-center">
      <h3 class="text-[11px] font-medium text-gray-700 truncate"><?= e($p['product_name']) ?></h3>
      <p class="text-xs font-bold text-gray-900 mt-0.5"><?= money($p['final_price']) ?></p>
    </div>
  </a>
<?php }

// Default card: best seller, compact
function card_best($p) { ?>
  <a href="product.php?slug=<?= e($p['slug']) ?>" class="bg-white rounded-xl overflow-hidden border border-gray-100 shadow-sm p-2 flex flex-col justify-between">
    <div class="relative rounded-lg overflow-hidden bg-rose-50/50 aspect-[3/4]">
      <?php if ($p['off_price'] > 0): ?>
        <span class="absolute top-2 left-2 z-10 bg-pink-600 text-white text-[9px] font-extrabold px-1.5 py-0.5 rounded">-<?= (int)$p['off_price'] ?>%</span>
      <?php endif; ?>
      <img src="<?= e(product_image($p)) ?>" alt="<?= e($p['product_name']) ?>" class="w-full h-full object-cover object-top">
    </div>
    <div class="mt-2 text-center">
      <h3 class="text-[11px] font-semibold text-gray-700 truncate"><?= e($p['product_name']) ?></h3>
      <p class="text-xs font-bold text-gray-900 mt-0.5"><?= money($p['final_price']) ?></p>
      <div class="flex justify-center"><?= stars($p['avg_star'], $p['review_count']) ?></div>
    </div>
  </a>
<?php }

/* ---------- data ---------- */
// Active inventories only (time window respected; NULL = always on)
$inventories = $conn->query(
    "SELECT id, inv_position, style_type, show_ads_next, ads_id, ads_id2, name, slug
       FROM inventories
      WHERE (time_start IS NULL OR time_start <= NOW())
        AND (time_end   IS NULL OR time_end   >= NOW())
      ORDER BY inv_position ASC"
);

// Products of one inventory (junction window respected, in stock only)
$productStmt = $conn->prepare(
    "SELECT p.id, p.slug, p.product_name, p.price, p.off_price, p.final_price, p.specification,
            COALESCE(r.avg_star, 0) AS avg_star, COALESCE(r.review_count, 0) AS review_count
       FROM product_inventories pi
       JOIN products p ON p.id = pi.product_id
       LEFT JOIN (SELECT product_id, AVG(star) AS avg_star, COUNT(*) AS review_count
                    FROM products_reviews WHERE parent_id IS NULL GROUP BY product_id) r
              ON r.product_id = p.id
      WHERE pi.inventory_id = ?
        AND p.instock > 0
        AND (pi.time_start IS NULL OR pi.time_start <= NOW())
        AND (pi.time_end   IS NULL OR pi.time_end   >= NOW())
      ORDER BY pi.is_paid DESC, pi.created_at DESC
      LIMIT 8"
);

// Customer reviews for the default-style section
$siteReviews = [];
$rv = $conn->query(
    "SELECT sr.star, sr.msg, u.firstname, u.lastname
       FROM site_reviews sr JOIN users u ON u.uid = sr.uid
      WHERE sr.parent_id IS NULL AND sr.star >= 4
      ORDER BY sr.created_at DESC LIMIT 3"
);
if ($rv) while ($row = $rv->fetch_assoc()) $siteReviews[] = $row;

$categories = $conn->query("SELECT slug, name, image FROM category LIMIT 7");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($company['title']) ?></title>
  <link href="./src/output.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    .hero-gradient { background: linear-gradient(135deg, #2b1158 0%, #7b1d8e 35%, #e11d48 70%, #fb923c 100%); }
    @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
    .animate-float { animation: float 4s ease-in-out infinite; }
  </style>
</head>
<body class="bg-white text-gray-800 font-sans">
<?php include __DIR__ . "/head.php"; ?>

<!-- HERO -->
<section class="relative w-full max-w-[1440px] mx-auto px-2 sm:px-4 lg:px-6 pt-4 pb-12">
  <div class="relative rounded-3xl overflow-hidden hero-gradient shadow-2xl min-h-[580px] lg:min-h-[620px] flex flex-col justify-between p-6 sm:p-10 lg:p-14 text-white">
    <div class="absolute -top-20 -left-20 w-80 h-80 bg-purple-600/30 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute top-1/2 right-10 w-96 h-96 bg-amber-500/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-20 right-1/3 w-80 h-80 bg-pink-500/30 rounded-full blur-3xl pointer-events-none"></div>

    <div class="relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-8 items-center h-full">
      <div class="lg:col-span-7 flex flex-col items-start space-y-6 max-w-xl">
        <div class="inline-flex items-center px-4 py-1.5 rounded-full border border-white/30 bg-white/10 backdrop-blur-md text-xs font-semibold tracking-wider uppercase shadow-inner">NEW SEASON</div>
        <h1 class="font-serif text-4xl sm:text-5xl lg:text-6xl font-normal leading-[1.15] tracking-tight drop-shadow-sm">
          Elevate Your <br /><span class="italic font-light">Everyday Style</span>
        </h1>
        <p class="text-base sm:text-lg text-white/90 font-light leading-relaxed max-w-lg">Discover premium fashion curated for every mood, every moment.</p>
        <div class="flex flex-wrap items-center gap-4 pt-2">
          <a href="/Category" class="px-8 py-3.5 rounded-xl font-bold text-sm tracking-wide bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:from-amber-600 hover:to-purple-700 shadow-lg shadow-pink-500/30 hover:shadow-pink-500/50 transform hover:-translate-y-0.5 transition-all duration-200">SHOP NOW</a>
          <a href="/Category" class="px-8 py-3.5 rounded-xl font-semibold text-sm border-2 border-white/80 bg-white/5 hover:bg-white/15 backdrop-blur-sm transition-all duration-200">EXPLORE COLLECTION</a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-8 w-full border-t border-white/15">
          <?php foreach ([['bi-truck','FREE SHIPPING','On orders above ₹999'],['bi-patch-check','PREMIUM QUALITY','Finest materials'],['bi-arrow-counterclockwise','EASY RETURNS','Hassle free returns']] as $f): ?>
          <div class="flex items-center space-x-3 group">
            <div class="w-10 h-10 rounded-xl bg-white/10 border border-white/20 backdrop-blur-md flex items-center justify-center flex-shrink-0 group-hover:bg-white/20 transition-colors"><i class="bi <?= $f[0] ?> text-lg"></i></div>
            <div><h4 class="text-xs font-bold uppercase tracking-wider"><?= $f[1] ?></h4><p class="text-[11px] text-white/70"><?= $f[2] ?></p></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="lg:col-span-5 relative flex justify-center items-end h-full mt-6 lg:mt-0">
        <div class="absolute top-4 right-2 sm:right-6 lg:-right-4 z-20 animate-float">
          <div class="w-28 h-28 sm:w-32 sm:h-32 rounded-full p-1 bg-gradient-to-tr from-amber-400 via-rose-500 to-purple-500 shadow-2xl">
            <div class="w-full h-full rounded-full bg-gradient-to-br from-rose-500/90 to-purple-800/90 backdrop-blur-md flex flex-col items-center justify-center text-center p-2 border border-white/30">
              <span class="text-[10px] font-bold tracking-widest uppercase text-amber-200">UP TO</span>
              <span class="text-2xl sm:text-3xl font-extrabold leading-none tracking-tight">60%</span>
              <span class="text-[10px] font-bold tracking-wider uppercase">OFF</span>
            </div>
          </div>
        </div>
        <div class="relative w-full max-w-md lg:max-w-none flex justify-center">
          <div class="absolute inset-0 bg-gradient-to-t from-rose-500/40 to-transparent rounded-b-3xl blur-2xl"></div>
          <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=1000&q=80" alt="Fashion Model"
               class="relative z-10 max-h-[480px] sm:max-h-[520px] lg:max-h-[560px] object-cover object-top filter drop-shadow-2xl rounded-2xl lg:rounded-none"
               onerror="this.onerror=null; this.src='https://placehold.co/600x800/818cf8/ffffff?text=Fashion+Model';" />
        </div>
      </div>
    </div>

    <div class="relative z-10 flex items-center justify-center space-x-2 pt-6">
      <button class="w-2.5 h-2.5 rounded-full bg-white opacity-40 hover:opacity-100 transition-opacity"></button>
      <button class="w-8 h-2.5 rounded-full bg-white shadow-sm"></button>
      <button class="w-2.5 h-2.5 rounded-full bg-white opacity-40 hover:opacity-100 transition-opacity"></button>
    </div>
  </div>

  <!-- Promo cards -->
  <div class="relative z-20 -mt-8 sm:-mt-10 px-2 sm:px-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <?php
      $promos = [
        ['from-orange-500 via-pink-500 to-purple-600','bi-percent','UP TO','60% OFF','On Selected Items'],
        ['from-pink-500 via-rose-500 to-purple-600','bi-gift','','BUY 2 GET 1 FREE','On All Products'],
        ['from-purple-600 via-indigo-500 to-cyan-500','bi-truck','FREE SHIPPING','Above ₹999','Only For Today'],
        ['from-indigo-700 via-blue-600 to-cyan-500','bi-credit-card','','EXTRA 10% OFF','On Prepaid Orders'],
      ];
      foreach ($promos as $pr): ?>
      <div class="rounded-2xl p-4 bg-gradient-to-r <?= $pr[0] ?> text-white shadow-xl hover:shadow-2xl hover:-translate-y-1 transition-all duration-300 flex items-center space-x-4 border border-white/20">
        <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur-md border border-white/30 flex items-center justify-center flex-shrink-0"><i class="bi <?= $pr[1] ?> text-2xl"></i></div>
        <div>
          <?php if ($pr[2]): ?><div class="text-xs font-black tracking-wider uppercase text-amber-200"><?= $pr[2] ?></div><?php endif; ?>
          <div class="text-lg font-extrabold tracking-tight leading-none"><?= $pr[3] ?></div>
          <p class="text-[11px] text-white/80 font-medium mt-1"><?= $pr[4] ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- SHOP BY CATEGORY -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
  <?php section_header('Shop By Category', '/Category'); ?>
  <div class="grid grid-cols-4 md:grid-cols-7 gap-4 sm:gap-6 text-center">
    <?php if ($categories && $categories->num_rows > 0): while ($c = $categories->fetch_assoc()):
      $img = !empty($c['image']) ? $c['image'] : 'https://images.unsplash.com/photo-1572804013309-59a88b7e92f1?auto=format&fit=crop&w=300&q=80'; ?>
      <a href="category.php?slug=<?= e($c['slug']) ?>" class="group flex flex-col items-center">
        <div class="w-20 h-20 sm:w-24 sm:h-24 lg:w-28 lg:h-28 rounded-full p-1 bg-gradient-to-tr from-purple-200 via-pink-200 to-indigo-100 group-hover:from-purple-500 group-hover:to-pink-500 transition-all duration-300 shadow-sm">
          <div class="w-full h-full rounded-full overflow-hidden bg-gray-100">
            <img src="<?= e($img) ?>" alt="<?= e($c['name']) ?>" class="w-full h-full object-cover object-top group-hover:scale-110 transition-transform duration-300">
          </div>
        </div>
        <span class="mt-2.5 text-xs font-bold text-gray-800 tracking-wider uppercase group-hover:text-pink-600 transition-colors"><?= e($c['name']) ?></span>
      </a>
    <?php endwhile; endif; ?>
  </div>
</section>

<!-- DYNAMIC INVENTORY SECTIONS (ordered by inv_position) -->
<?php
if ($inventories && $inventories->num_rows > 0):
  while ($inv = $inventories->fetch_assoc()):
    $invId = (int)$inv['id'];
    $productStmt->bind_param('i', $invId);
    $productStmt->execute();
    $products = $productStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if ($products):
      switch ((int)$inv['style_type']) {

        case 1: // grid with wishlist + add to cart ?>
          <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 relative">
            <?php section_header($inv['name'], '/' . $inv['slug']); ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-8 gap-3 sm:gap-4">
              <?php foreach ($products as $p) card_trending($p); ?>
            </div>
          </section>
        <?php break;

        case 2: // new arrivals, horizontal scroll carousel ?>
          <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative">
            <?php section_header($inv['name'], '/' . $inv['slug']); ?>
            <div class="relative">
              <button onclick="scrollRow(this,-1)" class="absolute -left-4 top-1/2 -translate-y-1/2 w-9 h-9 bg-white rounded-full shadow-lg border border-gray-100 hidden sm:flex items-center justify-center text-gray-700 hover:text-pink-600 hover:scale-105 transition-all z-20"><i class="bi bi-chevron-left text-sm"></i></button>
              <div class="flex gap-3 sm:gap-4 overflow-x-auto scroll-smooth snap-x [scrollbar-width:none]" data-row>
                <?php foreach ($products as $p): ?>
                  <div class="snap-start shrink-0 w-36 sm:w-40"><?php card_new($p); ?></div>
                <?php endforeach; ?>
              </div>
              <button onclick="scrollRow(this,1)" class="absolute -right-4 top-1/2 -translate-y-1/2 w-9 h-9 bg-white rounded-full shadow-lg border border-gray-100 hidden sm:flex items-center justify-center text-gray-700 hover:text-pink-600 hover:scale-105 transition-all z-20"><i class="bi bi-chevron-right text-sm"></i></button>
            </div>
          </section>
        <?php break;

        default: // best sellers + customer reviews ?>
          <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
              <div class="lg:col-span-6 flex flex-col justify-between">
                <div class="flex justify-between items-center mb-4">
                  <h2 class="text-xl font-bold tracking-tight text-gray-900 uppercase"><?= e($inv['name']) ?></h2>
                  <a href="/<?= e($inv['slug']) ?>" class="text-xs font-semibold text-gray-700 hover:text-pink-600 flex items-center gap-1 uppercase tracking-wider transition-colors">View All <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                  <?php foreach (array_slice($products, 0, 4) as $p) card_best($p); ?>
                </div>
              </div>

              <?php if ($siteReviews): ?>
              <div class="lg:col-span-6 flex flex-col justify-between">
                <div class="mb-4"><h2 class="text-xl font-bold tracking-tight text-gray-900 uppercase">What Our Customers Say</h2></div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 h-full items-stretch">
                  <?php foreach ($siteReviews as $r): ?>
                  <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 flex flex-col justify-between text-center">
                    <div>
                      <div class="flex justify-center text-amber-400 text-xs gap-0.5 mb-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?><i class="bi <?= $i <= $r['star'] ? 'bi-star-fill' : 'bi-star' ?>"></i><?php endfor; ?>
                      </div>
                      <p class="text-xs text-gray-600 italic leading-snug">"<?= e(mb_strimwidth($r['msg'], 0, 140, '…')) ?>"</p>
                    </div>
                    <div class="flex items-center justify-center gap-2 mt-4 pt-2 border-t border-gray-50">
                      <span class="w-8 h-8 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white text-xs font-bold flex items-center justify-center"><?= e(strtoupper(mb_substr($r['firstname'], 0, 1))) ?></span>
                      <span class="text-xs font-bold text-gray-800">— <?= e($r['firstname'] . ' ' . mb_substr($r['lastname'], 0, 1) . '.') ?></span>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </section>
        <?php break;
      }

      // Ads after the section
      if (!empty($inv['show_ads_next'])) {
        if (!empty($inv['ads_id']))  ads($inv['ads_id']);
        if (!empty($inv['ads_id2'])) ads($inv['ads_id2']);
      }
    endif;
  endwhile;
endif;
?>

<!-- COLLECTION BANNERS -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
  <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <?php
    $banners = [
      ['from-indigo-900 via-purple-700 to-pink-500','Summer<br />Collection','Light, Breezy & Beautiful','photo-1515886657613-9f3515b0c78f'],
      ['from-orange-500 via-rose-500 to-pink-500','New Arrivals','Fresh Styles Just For You','photo-1534528741775-53994a69daeb'],
      ['from-purple-900 via-purple-700 to-pink-600','Party Wear','Shine In Every Occasion','photo-1566174053879-31528523f8ae'],
    ];
    foreach ($banners as $b): ?>
    <div class="relative rounded-2xl overflow-hidden bg-gradient-to-r <?= $b[0] ?> p-6 flex items-center justify-between min-h-[180px] shadow-md hover:shadow-lg transition-shadow">
      <div class="z-10 max-w-[60%] flex flex-col items-start justify-between h-full">
        <div>
          <h3 class="text-xl sm:text-2xl font-extrabold text-white leading-tight uppercase tracking-wide"><?= $b[1] ?></h3>
          <p class="text-xs text-white/80 font-medium mt-1"><?= $b[2] ?></p>
        </div>
        <a href="/Category" class="mt-4 inline-block px-4 py-2 bg-white text-gray-900 hover:bg-gray-100 rounded-lg text-[11px] font-bold tracking-wider uppercase transition-colors shadow-sm">Shop Now</a>
      </div>
      <div class="absolute right-0 bottom-0 top-0 w-1/2 overflow-hidden flex items-end justify-end">
        <img src="https://images.unsplash.com/<?= $b[3] ?>?auto=format&fit=crop&w=400&q=80" alt="" class="h-full w-full object-cover object-top">
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- GUARANTEE BAR -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
  <div class="bg-[#1e143b] text-white rounded-2xl py-6 px-6 sm:px-10 shadow-xl border border-white/10">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 lg:gap-8 items-center">
      <?php
      $feats = [
        ['bi-shield-check','pink','Premium Quality','Finest fabrics & trusted quality'],
        ['bi-arrow-repeat','purple','Easy Returns','7 days return & exchange'],
        ['bi-lock','pink','Secure Payment','100% safe & secure'],
        ['bi-truck','purple','Fast Delivery','Quick delivery at your doorstep'],
      ];
      foreach ($feats as $f): ?>
      <div class="flex items-center space-x-4">
        <div class="w-12 h-12 rounded-full border-2 border-<?= $f[1] ?>-500/60 bg-<?= $f[1] ?>-500/10 flex items-center justify-center flex-shrink-0 text-<?= $f[1] ?>-400"><i class="bi <?= $f[0] ?> text-2xl"></i></div>
        <div><h4 class="text-xs font-bold uppercase tracking-wider"><?= $f[2] ?></h4><p class="text-[11px] text-gray-300 mt-0.5"><?= $f[3] ?></p></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- INSTAGRAM -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
  <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 uppercase flex items-center gap-2 mb-4">
    Follow Us On Instagram <span class="text-xs font-medium text-pink-600 normal-case">@velora.fashion</span>
  </h2>
  <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-8 gap-2">
    <?php foreach (['photo-1534528741775-53994a69daeb','photo-1515886657613-9f3515b0c78f','photo-1564257631407-4deb1f99d992','photo-1503342217505-b0a15ec3261c','photo-1572804013309-59a88b7e92f1','photo-1539109136881-3be0616acf4b','photo-1509631179647-0177331693ae'] as $ig): ?>
    <a href="#" class="group relative rounded-lg overflow-hidden aspect-square bg-gray-100">
      <img src="https://images.unsplash.com/<?= $ig ?>?auto=format&fit=crop&w=300&q=80" alt="Instagram Post" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
      <div class="absolute inset-0 bg-pink-600/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white"><i class="bi bi-instagram text-lg"></i></div>
    </a>
    <?php endforeach; ?>
    <div class="rounded-lg overflow-hidden bg-gradient-to-tr from-purple-800 via-pink-600 to-orange-400 p-3 text-white flex flex-col justify-center items-center text-center">
      <h4 class="text-xs font-black uppercase leading-tight">Tag Us To Get Featured</h4>
      <p class="text-[10px] font-semibold mt-1 opacity-90">#VeloraFashion</p>
    </div>
  </div>
</section>

<!-- NEWSLETTER -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
  <div class="relative rounded-2xl overflow-hidden bg-gradient-to-r from-indigo-900 via-purple-600 to-pink-500 p-6 sm:p-8 text-white shadow-xl flex flex-col lg:flex-row items-center justify-between gap-6">
    <div class="max-w-xl text-center lg:text-left">
      <h3 class="text-2xl sm:text-3xl font-extrabold tracking-tight uppercase">Get 10% Off On Your First Order</h3>
      <p class="text-xs sm:text-sm text-white/80 mt-1">Subscribe to our newsletter & get exclusive offers, new arrivals and style updates.</p>
    </div>
    <div class="flex flex-col sm:flex-row items-center gap-4 w-full lg:w-auto">
      <div class="relative flex items-center w-full sm:w-96 bg-white rounded-xl overflow-hidden p-1 shadow-inner">
        <input type="email" placeholder="Enter your email address" class="w-full py-2.5 px-4 text-xs text-gray-800 focus:outline-none placeholder:text-gray-400" />
        <button class="bg-[#0f1026] hover:bg-black text-white px-6 py-2.5 rounded-lg text-xs font-bold uppercase tracking-wider transition-colors flex-shrink-0">Subscribe</button>
      </div>
      <div class="hidden sm:flex items-center justify-center w-16 h-16 rounded-xl bg-white/10 backdrop-blur-md border border-white/20 flex-shrink-0"><i class="bi bi-gift-fill text-3xl text-pink-200"></i></div>
    </div>
  </div>
</section>

<!-- BRANDS -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 border-t border-gray-100">
  <div class="flex flex-wrap justify-between items-center gap-6 opacity-70 grayscale hover:grayscale-0 transition-all">
    <?php foreach (['ZARA','H&M','MANGO','ONLY','VERO MODA','FOREVER 21','NIKE',"LEVI'S"] as $brand): ?>
      <span class="font-extrabold tracking-wider text-base text-gray-800"><?= e($brand) ?></span>
    <?php endforeach; ?>
  </div>
</section>

<?php include __DIR__ . "/footer.php"; ?>

<script>
  // Carousel arrows
  function scrollRow(btn, dir) {
    const row = btn.parentElement.querySelector('[data-row]');
    row.scrollBy({ left: dir * row.clientWidth * 0.8, behavior: 'smooth' });
  }
  // Hooks: point these at your own endpoints
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