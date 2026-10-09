<?php
/* head - preloader + announcement bar + responsive header
   Uses what your config already builds: $company, $categories, $unlinked_brands,
   $user_profile, $wishlist_count, $cart_count. Safe defaults below if any are missing. */
$categories      = $categories ?? [];
$unlinked_brands = $unlinked_brands ?? [];
$user_profile    = $user_profile    ?? null;
$wishlist_count  = (int)($wishlist_count ?? 0);
$cart_count      = (int)($cart_count ?? 0);

$h      = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$badge  = fn($n) => $n > 99 ? '99+' : (string)$n;
$url    = fn($q = []) => 'category' . ($q ? '?' . http_build_query($q) : '');

$script     = basename($_SERVER['SCRIPT_NAME'] ?? '');
$activeSlug = $_GET['slug'] ?? '';
$isHome     = in_array($script, ['index', ''], true);
$loggedIn   = !empty($user_profile);
$userName   = $loggedIn ? $user_profile['user'] : 'Account';
$initial    = strtoupper(mb_substr($userName, 0, 1));

$barCats  = array_slice($categories, 0, 4, true);   // shown in the desktop bar
$moreCats = array_slice($categories, 4, null, true); // rest go under "More"


$linkBase   = 'block py-3 border-b-2 border-transparent hover:text-pink-600 hover:border-pink-300 transition-colors whitespace-nowrap';
$linkActive = 'block py-3 border-b-2 border-pink-600 text-pink-600 whitespace-nowrap';
?>
<style>
  /* ---- header behaviours that do not depend on Tailwind rebuilds ---- */
  .dd { display: none; }
  @media (min-width: 1024px) {
    .has-dd:hover > .dd, .has-dd:focus-within > .dd { display: block; }
  }
  details[open] > summary .chev { transform: rotate(180deg); }
  summary::-webkit-details-marker { display: none; }
  #navDrawer  { transform: translateX(-100%); transition: transform .3s ease; z-index: 61; }
  #navOverlay { opacity: 0; pointer-events: none; transition: opacity .3s ease; z-index: 60; }
  html.drawer-open #navDrawer  { transform: none; }
  html.drawer-open #navOverlay { opacity: 1; pointer-events: auto; }
  html.drawer-open, html.drawer-open body { overflow: hidden; }
  #preloader { transition: opacity .35s ease; }
  #preloader.done { opacity: 0; pointer-events: none; }
  /* sticky elements inside pages sit BELOW the sticky header */
  main .sticky, main .lg\:sticky { top: calc(var(--hdr, 0px) + .75rem) !important; }
  [id=details], [id=specs], [id=reviews], [id=more] { scroll-margin-top: calc(var(--hdr, 0px) + 3.5rem); }
</style>

<!-- Preloader -->
<div id="preloader" class="fixed inset-0 z-50 bg-white flex flex-col justify-center items-center">
  <div class="w-12 h-12 border-4 border-pink-200 border-t-pink-600 rounded-full animate-spin"></div>
  <p class="mt-4 text-xs font-semibold tracking-wider text-pink-600 uppercase">Loading <?= $h($company['name'] ?? 'Velora') ?>...</p>
</div>

<!-- Announcement bar -->
<div class="bg-gradient-to-r from-indigo-900 via-purple-700 to-pink-500 text-white text-[11px] sm:text-xs font-semibold text-center py-1.5 px-3 tracking-wide">
  <i class="bi bi-truck"></i> Free shipping above ₹999<span class="hidden sm:inline"> &nbsp;·&nbsp; Extra 10% off on prepaid orders &nbsp;·&nbsp; 7-day easy returns</span>
</div>

<!-- Header -->
<header id="siteHeader" class="border-b border-gray-100 shadow-sm bg-white sticky top-0 z-40">
  <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">

    <!-- Top row -->
    <div class="flex flex-wrap items-center gap-x-2 sm:gap-x-4 gap-y-2.5 py-2.5 lg:py-4">

      <!-- Menu button (mobile / tablet) -->
      <button type="button" id="menuBtn" aria-label="Open menu" aria-controls="navDrawer" class="lg:hidden w-10 h-10 -ml-1 rounded-full flex items-center justify-center text-gray-800 hover:bg-pink-50 hover:text-pink-600 transition-colors">
        <i class="bi bi-list text-3xl leading-none"></i>
      </button>

      <!-- Logo -->
      <a href="/" class="flex-shrink-0 block">
        <span class="text-2xl sm:text-3xl uppercase font-extrabold tracking-wider bg-gradient-to-r from-indigo-900 via-purple-600 to-pink-500 bg-clip-text text-transparent">
          <?= $h($company['name'] ?? 'Velora') ?>
        </span>
      </a>

      <!-- Search: full width row on mobile, inline on desktop -->
      <form method="get" action="category" role="search" class="order-last basis-full w-full lg:order-none lg:basis-auto lg:w-auto lg:flex-1 lg:max-w-xl lg:mx-auto relative flex items-center">
        <input type="search" name="q" value="<?= $h($_GET['q'] ?? '') ?>" autocomplete="off" enterkeyhint="search"
               placeholder="Search for products, brands and more..."
               class="w-full h-10 lg:h-11 pl-4 pr-14 text-[16px] sm:text-sm text-gray-700 bg-gray-50 focus:bg-white rounded-full border border-pink-300 focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-100 transition-all placeholder:text-gray-400" />
        <button type="submit" aria-label="Search" class="absolute right-0 top-0 bottom-0 w-12 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-r-full flex items-center justify-center hover:opacity-90 transition-opacity">
          <i class="bi bi-search text-sm"></i>
        </button>
      </form>

      <!-- Action icons -->
      <div class="ml-auto lg:ml-0 flex items-center gap-0.5 sm:gap-3">

        <!-- Wishlist -->
        <a href="members?tab=wishlist" aria-label="Wishlist" class="relative flex flex-col items-center justify-center w-10 h-10 sm:w-auto sm:h-auto sm:px-2 group">
          <span class="relative">
            <i class="bi bi-heart text-xl text-gray-700 group-hover:text-pink-600 transition-colors"></i>
            <?php if ($wishlist_count > 0): ?>
              <span class="absolute -top-1.5 -right-2.5 bg-pink-500 text-white text-[10px] font-bold min-w-[16px] h-4 px-1 rounded-full flex items-center justify-center"><?= $badge($wishlist_count) ?></span>
            <?php endif; ?>
          </span>
          <span class="hidden sm:block text-[10px] font-medium text-gray-600 mt-0.5">Wishlist</span>
        </a>

        <!-- Account (dropdown on desktop) -->
        <div class="has-dd relative">
          <a href="<?= $loggedIn ? 'members' : '/login' ?>" aria-label="Account" class="flex flex-col items-center justify-center w-10 h-10 sm:w-auto sm:h-auto sm:px-2 group">
            <i class="bi bi-person text-2xl text-gray-700 group-hover:text-pink-600 transition-colors leading-none"></i>
            <span class="hidden sm:block text-[10px] font-medium text-gray-600 max-w-[72px] truncate"><?= $h($userName) ?></span>
          </a>
          <div class="dd absolute right-0 top-full pt-2 w-56 z-50">
            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
              <?php if ($loggedIn): ?>
                <div class="px-4 py-3 bg-gradient-to-r from-indigo-900 via-purple-700 to-pink-500 text-white">
                  <p class="text-sm font-bold truncate"><?= $h($userName) ?></p>
                  <p class="text-[11px] text-white/80 truncate"><?= $h($user_profile['email'] ?? '') ?></p>
                </div>
                <ul class="py-1.5 text-sm text-gray-700">
                  <li><a href="members" class="flex items-center gap-3 px-4 py-2 hover:bg-pink-50 hover:text-pink-600"><i class="bi bi-grid"></i> My Account</a></li>
                  <li><a href="members?tab=orders" class="flex items-center gap-3 px-4 py-2 hover:bg-pink-50 hover:text-pink-600"><i class="bi bi-box-seam"></i> My Orders</a></li>
                  <li><a href="members?tab=wishlist" class="flex items-center gap-3 px-4 py-2 hover:bg-pink-50 hover:text-pink-600"><i class="bi bi-heart"></i> Wishlist</a></li>
                  <li class="border-t border-gray-100 mt-1 pt-1"><a href="/logout" class="flex items-center gap-3 px-4 py-2 hover:bg-red-50 hover:text-red-600"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
                </ul>
              <?php else: ?>
                <div class="p-4 space-y-2">
                  <a href="/login" class="block text-center py-2 rounded-xl text-xs font-bold uppercase tracking-wider text-white bg-gradient-to-r from-amber-500 via-rose-500 to-purple-600 hover:opacity-90">Log in</a>
                  <a href="/register" class="block text-center py-2 rounded-xl text-xs font-bold uppercase tracking-wider text-pink-600 border border-pink-300 hover:bg-pink-50">Create account</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Cart -->
        <a href="cart" aria-label="Cart" class="relative flex flex-col items-center justify-center w-10 h-10 sm:w-auto sm:h-auto sm:px-2 group">
          <span class="relative">
            <i class="bi bi-bag text-xl text-gray-700 group-hover:text-pink-600 transition-colors"></i>
            <?php if ($cart_count > 0): ?>
              <span class="absolute -top-1.5 -right-2.5 bg-pink-500 text-white text-[10px] font-bold min-w-[16px] h-4 px-1 rounded-full flex items-center justify-center"><?= $badge($cart_count) ?></span>
            <?php endif; ?>
          </span>
          <span class="hidden sm:block text-[10px] font-medium text-gray-600 mt-0.5">Cart</span>
        </a>
      </div>
    </div>

    <!-- Desktop category nav with mega menus -->
    <nav class="hidden lg:block relative border-t border-gray-100" aria-label="Main">
      <ul class="flex items-center justify-center gap-6 xl:gap-8 text-xs font-bold text-gray-800">
        <li><a href="/" class="<?= $isHome ? $linkActive : $linkBase ?>">HOME</a></li>
        <li><a href="<?= $h($url(['sort' => 'new'])) ?>" class="<?= $linkBase ?>">NEW ARRIVALS</a></li>

        <?php foreach ($barCats as $cat): $on = ($script === 'category' && $activeSlug === $cat['slug']); ?>
        <li class="has-dd">
          <a href="<?= $h($url(['slug' => $cat['slug']])) ?>" class="<?= $on ? $linkActive : $linkBase ?> uppercase">
            <?= $h($cat['name']) ?><?php if ($cat['sub_categories']): ?> <i class="bi bi-chevron-down text-[9px]"></i><?php endif; ?>
          </a>
          <?php if ($cat['sub_categories']): ?>
          <div class="dd absolute inset-x-0 top-full z-50">
            <div class="bg-white rounded-b-2xl shadow-2xl border border-gray-100 border-t-0 p-6 grid grid-cols-12 gap-6">
              <div class="col-span-9 grid grid-cols-3 gap-x-6 gap-y-5 text-left font-normal">
                <?php foreach ($cat['sub_categories'] as $sub): ?>
                  <div>
                    <a href="<?= $h($url(['slug' => $cat['slug'], 'sub' => [$sub['id']]])) ?>" class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-900 hover:text-pink-600"><?= $h($sub['name']) ?></a>
                    <?php if ($sub['brands']): ?>
                      <ul class="mt-1.5 space-y-1">
                        <?php foreach (array_slice($sub['brands'], 0, 5) as $b): ?>
                          <li><a href="<?= $h($url(['slug' => $cat['slug'], 'brand' => [$b['id']]])) ?>" class="text-xs text-gray-500 hover:text-pink-600 transition-colors"><?= $h($b['name']) ?></a></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <a href="<?= $h($url(['slug' => $cat['slug']])) ?>" class="col-span-3 relative rounded-xl overflow-hidden bg-gradient-to-br from-indigo-900 via-purple-700 to-pink-500 text-white p-4 flex flex-col justify-end min-h-[140px] group/promo">
                <?php if (!empty($cat['image'])): ?><img src="<?= $h($cat['image']) ?>" alt="" class="absolute inset-0 w-full h-full object-cover object-top opacity-40 group-hover/promo:scale-105 transition-transform duration-500"><?php endif; ?>
                <span class="relative text-[10px] font-bold uppercase tracking-widest text-amber-200">Explore</span>
                <span class="relative text-lg font-extrabold uppercase leading-tight"><?= $h($cat['name']) ?></span>
                <span class="relative text-[11px] font-semibold mt-1">Shop all <i class="bi bi-arrow-right"></i></span>
              </a>
            </div>
          </div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>

        <?php if ($moreCats): ?>
        <li class="has-dd relative">
          <a href="<?= $h($url()) ?>" class="<?= $linkBase ?>">MORE <i class="bi bi-chevron-down text-[9px]"></i></a>
          <div class="dd absolute left-1/2 -translate-x-1/2 top-full z-50 w-56">
            <ul class="bg-white rounded-b-2xl shadow-xl border border-gray-100 py-2 text-sm font-medium">
              <?php foreach ($moreCats as $cat): ?>
                <li><a href="<?= $h($url(['slug' => $cat['slug']])) ?>" class="block px-4 py-2 text-gray-700 hover:bg-pink-50 hover:text-pink-600"><?= $h($cat['name']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </li>
        <?php endif; ?>

        <?php if ($unlinked_brands): ?>
        <li class="has-dd relative">
          <a href="<?= $h($url()) ?>" class="<?= $linkBase ?>">BRANDS <i class="bi bi-chevron-down text-[9px]"></i></a>
          <div class="dd absolute left-1/2 -translate-x-1/2 top-full z-50 w-56">
            <ul class="bg-white rounded-b-2xl shadow-xl border border-gray-100 py-2 text-sm font-medium">
              <?php foreach ($unlinked_brands as $b): ?>
                <li><a href="<?= $h($url(['brand' => [$b['id']]])) ?>" class="block px-4 py-2 text-gray-700 hover:bg-pink-50 hover:text-pink-600"><?= $h($b['name']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </li>
        <?php endif; ?>

        <li><a href="<?= $h($url()) ?>" class="<?= $linkBase ?>">COLLECTIONS</a></li>
        <li class="relative">
          <span class="absolute -top-0.5 left-1/2 -translate-x-1/2 bg-orange-500 text-white text-[8px] font-extrabold px-1.5 rounded-full uppercase tracking-tighter pointer-events-none">HOT</span>
          <a href="<?= $h($url(['discount' => 25, 'sort' => 'discount'])) ?>" class="<?= $linkBase ?> pt-4">SALE</a>
        </li>
        <li><a href="/blog" class="<?= $linkBase ?>">BLOG</a></li>
        <li><a href="/contact" class="<?= $linkBase ?>">CONTACT</a></li>
      </ul>
    </nav>
  </div>
</header>

<!-- Mobile / tablet drawer -->
<div id="navOverlay" class="fixed inset-0 bg-black/50 lg:hidden" aria-hidden="true"></div>
<aside id="navDrawer" class="fixed top-0 left-0 bottom-0 w-[86%] max-w-sm bg-white shadow-2xl flex flex-col lg:hidden" aria-label="Menu">

  <div class="bg-gradient-to-r from-indigo-900 via-purple-700 to-pink-500 text-white p-4 pt-5">
    <div class="flex items-start justify-between">
      <?php if ($loggedIn): ?>
        <a href="members" class="flex items-center gap-3 min-w-0">
          <span class="w-11 h-11 rounded-full bg-white/20 border border-white/30 flex items-center justify-center text-lg font-extrabold"><?= $h($initial) ?></span>
          <span class="min-w-0"><span class="block text-[11px] text-white/80">Hello,</span><span class="block font-bold truncate"><?= $h($userName) ?></span></span>
        </a>
      <?php else: ?>
        <div>
          <p class="font-bold">Welcome to <?= $h($company['name'] ?? 'Velora') ?></p>
          <div class="flex gap-2 mt-2.5">
            <a href="/login" class="px-4 py-1.5 rounded-lg bg-white text-gray-900 text-[11px] font-bold uppercase tracking-wider">Log in</a>
            <a href="/register" class="px-4 py-1.5 rounded-lg border border-white/70 text-white text-[11px] font-bold uppercase tracking-wider">Sign up</a>
          </div>
        </div>
      <?php endif; ?>
      <button type="button" id="menuClose" aria-label="Close menu" class="w-9 h-9 -mr-1 -mt-1 rounded-full flex items-center justify-center hover:bg-white/15"><i class="bi bi-x-lg text-lg"></i></button>
    </div>
  </div>

  <div class="flex-1 overflow-y-auto overscroll-contain">
    <!-- Quick links -->
    <div class="grid grid-cols-3 gap-2 p-3 border-b border-gray-100 text-center">
      <?php foreach ([['bi-house', 'Home', '/'], ['bi-stars', 'New', $url(['sort' => 'new'])], ['bi-tag', 'Sale', $url(['discount' => 25, 'sort' => 'discount'])],
                      ['bi-heart', 'Wishlist', 'members?tab=wishlist', $wishlist_count], ['bi-bag', 'Cart', 'cart', $cart_count], ['bi-person', 'Account', $loggedIn ? 'members' : '/login']] as $q): ?>
        <a href="<?= $h($q[2]) ?>" class="relative rounded-xl bg-gray-50 hover:bg-pink-50 hover:text-pink-600 py-2.5 text-gray-700 transition-colors">
          <i class="bi <?= $q[0] ?> text-lg"></i>
          <span class="block text-[10px] font-bold uppercase tracking-wider mt-0.5"><?= $q[1] ?></span>
          <?php if (!empty($q[3])): ?><span class="absolute top-1 right-2 bg-pink-500 text-white text-[9px] font-bold min-w-[15px] h-[15px] px-1 rounded-full flex items-center justify-center"><?= $badge($q[3]) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Categories accordion -->
    <div class="p-3">
      <p class="px-1 pb-2 text-[11px] font-extrabold uppercase tracking-widest text-gray-400">Shop by category</p>
      <?php foreach ($categories as $cat): ?>
        <?php if ($cat['sub_categories']): ?>
          <details class="border-b border-gray-100">
            <summary class="flex items-center justify-between px-1 py-3 text-sm font-bold uppercase tracking-wide text-gray-800 cursor-pointer list-none">
              <?= $h($cat['name']) ?><i class="bi bi-chevron-down chev text-xs text-gray-400 transition-transform"></i>
            </summary>
            <ul class="pb-2 pl-2 space-y-0.5">
              <li><a href="<?= $h($url(['slug' => $cat['slug']])) ?>" class="block px-3 py-2 rounded-lg text-sm font-semibold text-pink-600 hover:bg-pink-50">View all <?= $h($cat['name']) ?></a></li>
              <?php foreach ($cat['sub_categories'] as $sub): ?>
                <li><a href="<?= $h($url(['slug' => $cat['slug'], 'sub' => [$sub['id']]])) ?>" class="block px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-pink-50 hover:text-pink-600"><?= $h($sub['name']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php else: ?>
          <a href="<?= $h($url(['slug' => $cat['slug']])) ?>" class="flex items-center justify-between px-1 py-3 border-b border-gray-100 text-sm font-bold uppercase tracking-wide text-gray-800 hover:text-pink-600"><?= $h($cat['name']) ?><i class="bi bi-chevron-right text-xs text-gray-400"></i></a>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($unlinked_brands): ?>
        <details class="border-b border-gray-100">
          <summary class="flex items-center justify-between px-1 py-3 text-sm font-bold uppercase tracking-wide text-gray-800 cursor-pointer list-none">Brands<i class="bi bi-chevron-down chev text-xs text-gray-400 transition-transform"></i></summary>
          <ul class="pb-2 pl-2 space-y-0.5">
            <?php foreach ($unlinked_brands as $b): ?>
              <li><a href="<?= $h($url(['brand' => [$b['id']]])) ?>" class="block px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-pink-50 hover:text-pink-600"><?= $h($b['name']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </div>

    <!-- More -->
    <div class="px-3 pb-4 text-sm font-semibold text-gray-700 space-y-0.5">
      <a href="/blog" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-pink-50 hover:text-pink-600"><i class="bi bi-journal-text"></i> Blog</a>
      <a href="/contact" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-pink-50 hover:text-pink-600"><i class="bi bi-headset"></i> Contact us</a>
      <?php if ($loggedIn): ?><a href="/logout" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-red-50 hover:text-red-600"><i class="bi bi-box-arrow-right"></i> Log out</a><?php endif; ?>
    </div>
  </div>
</aside>

<script>
  (function () {
    var root = document.documentElement, header = document.getElementById('siteHeader');

    // Preloader
    var pre = document.getElementById('preloader');
    function hidePre() { if (!pre) return; pre.classList.add('done'); setTimeout(function () { pre.remove(); pre = null; }, 400); }
    window.addEventListener('load', hidePre);
    setTimeout(hidePre, 4000);

    // Header height -> CSS var so sticky page elements sit below it
    function setHdr() { root.style.setProperty('--hdr', (header ? header.offsetHeight : 0) + 'px'); }
    setHdr(); window.addEventListener('resize', setHdr); window.addEventListener('load', setHdr);
    if (window.ResizeObserver && header) new ResizeObserver(setHdr).observe(header);

    // Drawer
    function openMenu()  { root.classList.add('drawer-open'); }
    function closeMenu() { root.classList.remove('drawer-open'); }
    document.getElementById('menuBtn').addEventListener('click', openMenu);
    document.getElementById('menuClose').addEventListener('click', closeMenu);
    document.getElementById('navOverlay').addEventListener('click', closeMenu);
    document.getElementById('navDrawer').addEventListener('click', function (e) { if (e.target.closest('a')) closeMenu(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
    window.matchMedia('(min-width: 1024px)').addEventListener('change', function (m) { if (m.matches) closeMenu(); });
  })();
</script>