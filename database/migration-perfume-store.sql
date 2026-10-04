ALTER TABLE products MODIFY category ENUM('ring','necklace','bracelet','earring','women','men','unisex','niche') NOT NULL;

INSERT IGNORE INTO catalog_categories(name,slug,sort_order,active) VALUES
('عطر زنانه','women',0,1),
('عطر مردانه','men',1,1),
('عطر یونی‌سکس','unisex',2,1),
('عطر نیش','niche',3,1);

INSERT IGNORE INTO catalog_attributes(name,slug,input_type,sort_order) VALUES
('حجم','volume','select',0),
('غلظت','concentration','select',1),
('طبع','temperament','select',2),
('خانواده بویایی','olfactive-family','select',3),
('کشور سازنده','country','text',4);
