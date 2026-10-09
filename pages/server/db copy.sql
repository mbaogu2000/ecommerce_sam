
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) UNIQUE,
    token VARCHAR(255) NULL,
    
    username VARCHAR(50)  NOT NULL,
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

-- create table if not EXISTS vendor(
--     id,  uid, store_name, title, logo, banner, description, keywords, store_address, created_at, updated_at
-- )





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


CREATE TABLE IF NOT EXISTS brand_category (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_category_id INT,
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

CREATE TABLE IF NOT EXISTS wishlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_category VARCHAR(255),
    product_sub_category VARCHAR(255),
    product_brand VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key constraint referencing users(uid)
    FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE,

    -- Indexes to speed up queries on user wishlists and category/brand filters
    INDEX idx_wishlist_uid (uid),
    INDEX idx_wishlist_category (product_category),
    INDEX idx_wishlist_brand (product_brand)
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    product_name VARCHAR(255) NOT NULL,
    product_category_id INT,
    product_sub_category_id INT,
    brand_category_id INT,
    keywords TEXT,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    off_price DECIMAL(5, 2) DEFAULT 0.00, -- Discount percentage (e.g., 15.50 for 15.5%)
    
    -- Calculated actual price after discount (MySQL Generated Column)
    final_price DECIMAL(10, 2) GENERATED ALWAYS AS (price - (price * (off_price / 100))) STORED,
    
    specification JSON DEFAULT NULL,
    instock INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key relations
    FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE,
    FOREIGN KEY (product_category_id) REFERENCES category(id) ON DELETE SET NULL,
    FOREIGN KEY (product_sub_category_id) REFERENCES sub_category(id) ON DELETE SET NULL,
    FOREIGN KEY (brand_category_id) REFERENCES brand_category(id) ON DELETE SET NULL,
    -- Essential performance indexes
    INDEX idx_products_uid (uid),
    INDEX idx_products_category (product_category_id),
    INDEX idx_products_sub_category (product_sub_category_id),
    INDEX idx_products_brand (brand_category_id),
    INDEX idx_products_instock (instock),
    INDEX idx_products_price (price)
);


create table if not EXISTS inventries(
    id, name, image DEFAULT null, slug, description, keywords, time_start, time_end, updated_at, created_at
)

create table if not EXISTS product_inventries(
  id, inventries_id,  product_id, is_paid, time_start, time_end,  updated_at, created_at
)

CREATE TABLE IF NOT EXISTS cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(64) NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00, -- Unit price or snapshot price
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Key constraints
    FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,

    -- Indexes for fast lookup
    INDEX idx_cart_items_uid (uid),
    INDEX idx_cart_items_product (product_id),
    
    -- Prevents duplicate records for the same product per user
    UNIQUE KEY uq_user_product (uid, product_id)
);





-- CREATE TABLE IF NOT EXISTS ads (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     title VARCHAR(255) NOT NULL,
--     subtitle VARCHAR(255) NULL,
--     button_text VARCHAR(100) DEFAULT 'Shop Now',
--     button_link VARCHAR(255) DEFAULT '#',
--     image VARCHAR(255) NULL,
--     gradient_class VARCHAR(255) DEFAULT 'from-indigo-900 via-purple-700 to-pink-500',
--     status ENUM('active', 'inactive') DEFAULT 'active',
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- );