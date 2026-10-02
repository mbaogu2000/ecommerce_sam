<?php
   $page=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
   include __DIR__."/pages/server/conn.php";
   $company = [
    "name"     => "bluestore",
    "desc"     => "Create your own subdomain store or attach a custom domain. Buy, sell, and grow your e-commerce business effortlessly with bluestore.",
    "keywords" => "e-commerce, buy and sell, online store, custom domain, subdomain, bluestore, start online store",
    "logo"     => "/logo.png",
    "title"    => "bluestore - Buy, sell, and launch your own custom domain store"
];
   switch ($page) {
    case '/':
    case '/home':
        include __DIR__."/pages/main/index.php";
        break;
    
    case '/login':
        include __DIR__."/pages/main/login.php";
        break;
    
    case '/member':
    case '/dashboard':
        include __DIR__."/pages/member/index.php";
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    case '/':
    case '/home':
        break;
    
    default:
        include __DIR__."/pages/main/404.php";
        break;
   }
?>