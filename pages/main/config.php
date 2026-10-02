<?php
// 1. Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_profile = null;
$wishlist_count = 0;
$cart_count = 0;

// 2. Authenticate user via Session
if (!empty($_SESSION["suid"]) && !empty($_SESSION["tid"])) {
    $stmt = $conn->prepare("SELECT uid, username, firstname, email FROM users WHERE uid = ? AND token = ? LIMIT 1");
    $stmt->bind_param("ss", $_SESSION["suid"], $_SESSION["tid"]);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        // Fallback display name: username -> firstname -> email
        $displayName = !empty($user['username']) ? $user['username'] : (!empty($user['firstname']) ? $user['firstname'] : $user['email']);
        
        $user_profile = [
            "uid"  => $user['uid'],
            "user" => $displayName,
            "email"=> $user['email']
        ];

        // 3. Get Wishlist Count
        $stmt_wishlist = $conn->prepare("SELECT COUNT(*) AS total FROM wishlist WHERE uid = ?");
        $stmt_wishlist->bind_param("s", $user['uid']);
        $stmt_wishlist->execute();
        $wishlist_count = $stmt_wishlist->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt_wishlist->close();

        // 4. Get Cart Items Count
        $stmt_cart = $conn->prepare("SELECT COUNT(*) AS total FROM cart_items WHERE uid = ?");
        $stmt_cart->bind_param("s", $user['uid']);
        $stmt_cart->execute();
        $cart_count = $stmt_cart->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt_cart->close();
    }
    $stmt->close();
}

// 5. Fetch Categories, Subcategories, and Brand Categories
$sql_navigation = "
    SELECT 
        c.id AS cat_id, c.name AS cat_name, c.slug AS cat_slug, c.image AS cat_image,
        sc.id AS sub_id, sc.name AS sub_name, sc.slug AS sub_slug, sc.image AS sub_image,
        bc.id AS brand_id, bc.name AS brand_name, bc.slug AS brand_slug, bc.image AS brand_image, bc.sub_category_id AS brand_sub_id
    FROM category c
    LEFT JOIN sub_category sc ON sc.category_id = c.id
    LEFT JOIN brand_category bc ON bc.sub_category_id = sc.id
    ORDER BY c.id ASC, sc.id ASC, bc.id ASC
";

$nav_result = $conn->query($sql_navigation);
$categories = [];

if ($nav_result && $nav_result->num_rows > 0) {
    while ($row = $nav_result->fetch_assoc()) {
        $cat_id = $row['cat_id'];
        $sub_id = $row['sub_id'];
        $brand_id = $row['brand_id'];

        // Initialize Category array if not set
        if (!isset($categories[$cat_id])) {
            $categories[$cat_id] = [
                'id' => $cat_id,
                'name' => $row['cat_name'],
                'slug' => $row['cat_slug'],
                'image' => $row['cat_image'],
                'sub_categories' => [],
                'standalone_brands' => []
            ];
        }

        // Add Subcategory if exists
        if ($sub_id && !isset($categories[$cat_id]['sub_categories'][$sub_id])) {
            $categories[$cat_id]['sub_categories'][$sub_id] = [
                'id' => $sub_id,
                'name' => $row['sub_name'],
                'slug' => $row['sub_slug'],
                'image' => $row['sub_image'],
                'brands' => []
            ];
        }

        // Add Brand under Subcategory
        if ($sub_id && $brand_id) {
            $categories[$cat_id]['sub_categories'][$sub_id]['brands'][$brand_id] = [
                'id' => $brand_id,
                'name' => $row['brand_name'],
                'slug' => $row['brand_slug'],
                'image' => $row['brand_image']
            ];
        }
    }
}

// 6. Fetch standalone brands where sub_category_id IS NULL
$sql_unlinked_brands = "SELECT id, name, slug, image FROM brand_category WHERE sub_category_id IS NULL";
$unlinked_result = $conn->query($sql_unlinked_brands);
$unlinked_brands = [];

if ($unlinked_result && $unlinked_result->num_rows > 0) {
    while ($brand = $unlinked_result->fetch_assoc()) {
        $unlinked_brands[] = $brand;
    }
}