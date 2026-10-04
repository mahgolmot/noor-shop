ALTER TABLE products ADD COLUMN brand VARCHAR(100) NOT NULL DEFAULT 'bilumiere' AFTER image_path;
CREATE INDEX idx_products_brand_active ON products(brand,active);
