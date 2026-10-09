-- 1. USERS TABLE
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) UNIQUE NOT NULL,
    token VARCHAR(255) NULL,
    
    username VARCHAR(50) NOT NULL UNIQUE,
    firstname VARCHAR(50) NOT NULL,
    lastname VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20) UNIQUE NULL,
    
    email_is_verified BOOLEAN DEFAULT FALSE,
    phone_is_verified BOOLEAN DEFAULT FALSE,
    password VARCHAR(255) NOT NULL,
    
    status ENUM('active', 'pending', 'suspended') DEFAULT 'pending',
    is_admin BOOLEAN DEFAULT FALSE,
    admin_default_password VARCHAR(255) NULL,
    
    is_vendor BOOLEAN DEFAULT FALSE,
    is_sub_domain_owner BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. CATEGORY TABLE
CREATE TABLE IF NOT EXISTS category (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    keywords TEXT,
    image VARCHAR(255),
    logo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 3. SUB CATEGORY TABLE
CREATE TABLE IF NOT EXISTS sub_category (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    keywords TEXT,
    image VARCHAR(255),
    logo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES category(id) ON DELETE CASCADE
);

-- 4. BRAND CATEGORY TABLE
CREATE TABLE IF NOT EXISTS brand_category (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_category_id INT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    keywords TEXT,
    image VARCHAR(255),
    logo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_category_id) REFERENCES sub_category(id) ON DELETE SET NULL
);


-- 6. PRODUCTS TABLE
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    product_name VARCHAR(255) NOT NULL,
    product_category_id INT NULL,
    product_sub_category_id INT NULL,
    brand_category_id INT NULL,
    keywords TEXT,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    off_price DECIMAL(5, 2) DEFAULT 0.00,
    
    final_price DECIMAL(10, 2) GENERATED ALWAYS AS (price - (price * (off_price / 100))) STORED,
    
    specification JSON DEFAULT NULL,
    instock INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE,
    FOREIGN KEY (product_category_id) REFERENCES category(id) ON DELETE SET NULL,
    FOREIGN KEY (product_sub_category_id) REFERENCES sub_category(id) ON DELETE SET NULL,
    FOREIGN KEY (brand_category_id) REFERENCES brand_category(id) ON DELETE SET NULL,

    INDEX idx_products_uid (uid),
    INDEX idx_products_category (product_category_id),
    INDEX idx_products_sub_category (product_sub_category_id),
    INDEX idx_products_brand (brand_category_id),
    INDEX idx_products_instock (instock),
    INDEX idx_products_price (price)
);

CREATE TABLE IF NOT EXISTS products_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) NOT NULL,
    parent_id INT DEFAULT NULL,
    product_id INT NOT NULL,
    star TINYINT UNSIGNED NOT NULL DEFAULT 5,
    msg TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Key Constraints
    FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES products_reviews(id) ON DELETE CASCADE,

    -- Enforce valid rating scores (1 to 5 stars)
    CONSTRAINT chk_star_rating CHECK (star >= 1 AND star <= 5),

    -- Performance Indexes
    INDEX idx_reviews_uid (uid),
    INDEX idx_reviews_product (product_id),
    INDEX idx_reviews_parent (parent_id)
);



-- 5. WISHLIST TABLE
CREATE TABLE IF NOT EXISTS wishlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,

    UNIQUE KEY uq_user_wishlist_product (uid, product_id),
    INDEX idx_wishlist_uid (uid),
    INDEX idx_wishlist_product (product_id)
);
-- 7. INVENTORIES TABLE
CREATE TABLE IF NOT EXISTS inventories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inv_position INT NOT NULL UNIQUE,
    style_type INT DEFAULT NULL,
    show_ads_next BOOLEAN DEFAULT FALSE,
    ads_id INT DEFAULT NULL,
    ads_id2 INT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    image VARCHAR(255) DEFAULT NULL,
    description TEXT,
    keywords TEXT,
    time_start DATETIME NULL,
    time_end DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 8. PRODUCT INVENTORIES TABLE (Junction Table)
CREATE TABLE IF NOT EXISTS product_inventories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    product_id INT NOT NULL,
    is_paid BOOLEAN DEFAULT FALSE,
    time_start DATETIME NULL,
    time_end DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (inventory_id) REFERENCES inventories(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,

    UNIQUE KEY uq_inventory_product (inventory_id, product_id),
    INDEX idx_product_inv_inventory (inventory_id),
    INDEX idx_product_inv_product (product_id)
);

-- 9. CART ITEMS TABLE
CREATE TABLE IF NOT EXISTS cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,

    INDEX idx_cart_items_uid (uid),
    INDEX idx_cart_items_product (product_id),
    UNIQUE KEY uq_user_product (uid, product_id)
);



CREATE TABLE IF NOT EXISTS site_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) NOT NULL,
    parent_id INT DEFAULT NULL,
    star TINYINT UNSIGNED NOT NULL DEFAULT 5,
    msg TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Key Constraints
    FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES products_reviews(id) ON DELETE CASCADE,

    -- Enforce valid rating scores (1 to 5 stars)
    CONSTRAINT chk_star_rating CHECK (star >= 1 AND star <= 5),

    -- Performance Indexes
    INDEX idx_reviews_uid (uid),
    INDEX idx_reviews_parent (parent_id)
);