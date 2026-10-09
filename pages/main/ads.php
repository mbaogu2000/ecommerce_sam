<?php
function ads($param) {
    switch ($param) {
        case 1:
            return <<<_HTML
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      
      <!-- Banner 1: Summer Collection -->
      <div class="relative rounded-2xl overflow-hidden bg-gradient-to-r from-indigo-900 via-purple-700 to-pink-500 p-6 flex items-center justify-between min-h-[180px] shadow-md hover:shadow-lg transition-shadow">
        <div class="z-10 max-w-[60%] flex flex-col items-start justify-between h-full">
          <div>
            <h3 class="text-xl sm:text-2xl font-extrabold text-white leading-tight uppercase tracking-wide">
              Summer<br />Collection
            </h3>
            <p class="text-xs text-white/80 font-medium mt-1">Light, Breezy & Beautiful</p>
          </div>
          <a href="#" class="mt-4 inline-block px-4 py-2 bg-white text-gray-900 hover:bg-gray-100 rounded-lg text-[11px] font-bold tracking-wider uppercase transition-colors shadow-sm">
            Shop Now
          </a>
        </div>
        <div class="absolute right-0 bottom-0 top-0 w-1/2 overflow-hidden flex items-end justify-end">
          <img src="https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?auto=format&fit=crop&w=400&q=80" alt="Summer Collection Model" class="h-full w-full object-cover object-top">
        </div>
      </div>
  
      <!-- Banner 2: New Arrivals -->
      <div class="relative rounded-2xl overflow-hidden bg-gradient-to-r from-orange-500 via-rose-500 to-pink-500 p-6 flex items-center justify-between min-h-[180px] shadow-md hover:shadow-lg transition-shadow">
        <div class="z-10 max-w-[60%] flex flex-col items-start justify-between h-full">
          <div>
            <h3 class="text-xl sm:text-2xl font-extrabold text-white leading-tight uppercase tracking-wide">
              New Arrivals
            </h3>
            <p class="text-xs text-white/80 font-medium mt-1">Fresh Styles Just For You</p>
          </div>
          <a href="#" class="mt-4 inline-block px-4 py-2 bg-white text-gray-900 hover:bg-gray-100 rounded-lg text-[11px] font-bold tracking-wider uppercase transition-colors shadow-sm">
            Shop Now
          </a>
        </div>
        <div class="absolute right-0 bottom-0 top-0 w-1/2 overflow-hidden flex items-end justify-end">
          <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80" alt="New Arrivals Model" class="h-full w-full object-cover object-top">
        </div>
      </div>
  
      <!-- Banner 3: Party Wear -->
      <div class="relative rounded-2xl overflow-hidden bg-gradient-to-r from-purple-900 via-purple-700 to-pink-600 p-6 flex items-center justify-between min-h-[180px] shadow-md hover:shadow-lg transition-shadow">
        <div class="z-10 max-w-[60%] flex flex-col items-start justify-between h-full">
          <div>
            <h3 class="text-xl sm:text-2xl font-extrabold text-white leading-tight uppercase tracking-wide">
              Party Wear
            </h3>
            <p class="text-xs text-white/80 font-medium mt-1">Shine In Every Occasion</p>
          </div>
          <a href="#" class="mt-4 inline-block px-4 py-2 bg-white text-gray-900 hover:bg-gray-100 rounded-lg text-[11px] font-bold tracking-wider uppercase transition-colors shadow-sm">
            Shop Now
          </a>
        </div>
        <div class="absolute right-0 bottom-0 top-0 w-1/2 overflow-hidden flex items-end justify-end">
          <img src="https://images.unsplash.com/photo-1566174053879-31528523f8ae?auto=format&fit=crop&w=400&q=80" alt="Party Wear Model" class="h-full w-full object-cover object-top">
        </div>
      </div>
  
    </div>
</section>
_HTML;

case 2:
   return <<<_HTML 
  <!-- BOTTOM FEATURE / GUARANTEE BAR SECTION -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="bg-[#1e143b] text-white rounded-2xl py-6 px-6 sm:px-10 shadow-xl border border-white/10">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 lg:gap-8 items-center">
        
        <!-- Feature 1: Premium Quality -->
        <div class="flex items-center space-x-4">
          <div class="w-12 h-12 rounded-full border-2 border-pink-500/60 bg-pink-500/10 flex items-center justify-center flex-shrink-0 text-pink-400">
            <i class="bi bi-shield-check text-2xl"></i>
          </div>
          <div>
            <h4 class="text-xs font-bold uppercase tracking-wider text-white">Premium Quality</h4>
            <p class="text-[11px] text-gray-300 mt-0.5">Finest fabrics & trusted quality</p>
          </div>
        </div>
  
        <!-- Feature 2: Easy Returns -->
        <div class="flex items-center space-x-4">
          <div class="w-12 h-12 rounded-full border-2 border-purple-500/60 bg-purple-500/10 flex items-center justify-center flex-shrink-0 text-purple-400">
            <i class="bi bi-arrow-repeat text-2xl"></i>
          </div>
          <div>
            <h4 class="text-xs font-bold uppercase tracking-wider text-white">Easy Returns</h4>
            <p class="text-[11px] text-gray-300 mt-0.5">7 days return & exchange</p>
          </div>
        </div>
  
        <!-- Feature 3: Secure Payment -->
        <div class="flex items-center space-x-4">
          <div class="w-12 h-12 rounded-full border-2 border-pink-500/60 bg-pink-500/10 flex items-center justify-center flex-shrink-0 text-pink-400">
            <i class="bi bi-lock text-2xl"></i>
          </div>
          <div>
            <h4 class="text-xs font-bold uppercase tracking-wider text-white">Secure Payment</h4>
            <p class="text-[11px] text-gray-300 mt-0.5">100% safe & secure</p>
          </div>
        </div>
  
        <!-- Feature 4: Fast Delivery -->
        <div class="flex items-center space-x-4">
          <div class="w-12 h-12 rounded-full border-2 border-purple-500/60 bg-purple-500/10 flex items-center justify-center flex-shrink-0 text-purple-400">
            <i class="bi bi-truck text-2xl"></i>
          </div>
          <div>
            <h4 class="text-xs font-bold uppercase tracking-wider text-white">Fast Delivery</h4>
            <p class="text-[11px] text-gray-300 mt-0.5">Quick delivery at your doorstep</p>
          </div>
        </div>
  
      </div>
    </div>
  </section>
_HTML;



        default:
            return '';
    }
}