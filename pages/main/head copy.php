
    <!-- Preloader -->
<div id="preloader" class="fixed  top-0 bottom-0 left-0 right-0 inset-0 z-50 bg-white flex flex-col justify-center items-center">
    <div class="w-12 h-12 border-4 border-pink-200 border-t-pink-600 rounded-full animate-spin"></div>
    <p class="mt-4 text-xs font-semibold tracking-wider text-pink-600 uppercase">Loading Velora...</p>
</div>
<!-- Navigation Bar -->
<header class="border-b border-gray-100 shadow-sm bg-white sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Top Header Bar -->
            <div class="flex items-center justify-between py-4 gap-4">
                
                <!-- Logo -->
                <div class="flex-shrink-0">
                    <a href="/" class="block">
                        <span class="text-3xl uppercase font-extrabold tracking-wider bg-gradient-to-r from-indigo-900 via-purple-600 to-pink-500 bg-clip-text text-transparent">
                            <?= $company['name'] ?>
                        </span>
                    </a>
                </div>

                <!-- Search Bar -->
                <div class="flex-1 max-w-xl mx-4">
                    <form method="get" action="/search?" class="relative flex items-center">
                        <input 
                            type="text" name="q"
                            placeholder="Search for products, brands and more..." 
                            class="w-full py-2 pl-4 pr-12 text-sm text-gray-600 rounded-full border border-pink-300 focus:outline-none focus:border-pink-500 transition-colors"
                        />
                        <button class="absolute right-0 top-0 bottom-0 px-4 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-r-full flex items-center justify-center hover:opacity-90 transition-opacity">
                            <i class="bi bi-search text-sm"></i>
                        </button>
                    </form>
                </div>

                <!-- Action Icons -->
                <div class="flex items-center space-x-6">
                    <!-- Wishlist -->
                    <a href="/members?tab=wishlist" class="flex flex-col items-center group relative">
                          <?php if($wishlist_count > 0): ?>
                            <span class="absolute -top-1.5 -right-2.5 bg-pink-500 text-white text-[10px] font-bold w-4 h-4 rounded-full flex items-center justify-center">
                                <?= $wishlist_count ?>
                            </span>
                          <?php endif ?>
                        <i class="bi bi-heart text-xl text-gray-700 group-hover:text-pink-600 transition-colors"></i>
                        <span class="text-[10px] font-medium text-gray-600 mt-0.5">Wishlist</span>
                    </a>

                    <!-- Account -->
                    <a href="/member" class="flex flex-col items-center group">
                        <i class="bi bi-person text-2xl text-gray-700 group-hover:text-pink-600 transition-colors"></i>
                        <span class="text-[10px] font-medium text-gray-600 -mt-1"> <?= isset($user_profile) && $user_profile !== null ? htmlspecialchars($user_profile['user']) : "Account" ?></span>
                    </a> 

                    <!-- Cart -->
                    <a href="/cart" class="flex flex-col items-center group relative">
                        <div class="relative">
                            <i class="bi bi-bag text-xl text-gray-700 group-hover:text-pink-600 transition-colors"></i>
                            <?php if($cart_count > 0): ?>
                                <span class="absolute -top-1.5 -right-2.5 bg-pink-500 text-white text-[10px] font-bold w-4 h-4 rounded-full flex items-center justify-center">
                                    <?= $cart_count ?>
                                </span>
                            <?php endif ?>
                        </div>
                        <span class="text-[10px] font-medium text-gray-600 mt-0.5">Cart</span>
                    </a>
                </div>
            </div>

            <!-- Bottom Category Menu -->
            <nav class="flex items-center justify-center space-x-8 text-xs font-bold text-gray-800 pt-2 pb-3">
                <a href="#" class="text-pink-600 border-b-2 border-pink-600 pb-1">HOME</a>
                <a href="#" class="hover:text-pink-600 transition-colors pb-1">NEW ARRIVALS</a>
                <a href="#" class="hover:text-pink-600 transition-colors pb-1">WOMEN</a>
                <a href="#" class="hover:text-pink-600 transition-colors pb-1">MEN</a>
                <a href="#" class="hover:text-pink-600 transition-colors pb-1">KIDS</a>
                <a href="#" class="hover:text-pink-600 transition-colors pb-1">COLLECTIONS</a>
                <a href="#" class="relative hover:text-pink-600 transition-colors pb-1 flex items-center gap-1">
                    <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 bg-orange-500 text-white text-[8px] font-extrabold px-1.5 py-0.2 rounded-full uppercase tracking-tighter">
                        HOT
                    </span>
                    SALE
                </a>
                <a href="#" class="hover:text-pink-600 transition-colors pb-1">BLOG</a>
                <a href="#" class="hover:text-pink-600 transition-colors pb-1">CONTACT US</a>
            </nav>
        </div>
    </header>