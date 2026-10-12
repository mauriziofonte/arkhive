-- Seed data for the e2e suite: two databases with deterministic rows.
CREATE DATABASE shop;
CREATE DATABASE blog;

USE shop;
CREATE TABLE products (
    id INT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    payload BLOB
);
CREATE TABLE orders (
    id INT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    created_at DATETIME NOT NULL
);
INSERT INTO products (id, name, price, payload)
SELECT seq, CONCAT('product ', seq), seq * 1.25, UNHEX(SHA2(seq, 256)) FROM seq_1_to_500;
INSERT INTO orders (id, product_id, quantity, created_at)
SELECT seq, 1 + (seq MOD 500), 1 + (seq MOD 7), '2026-01-01 00:00:00' + INTERVAL seq MINUTE FROM seq_1_to_2000;
DELIMITER //
CREATE PROCEDURE order_count() BEGIN SELECT COUNT(*) FROM orders; END //
DELIMITER ;

USE blog;
CREATE TABLE posts (
    id INT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL
);
INSERT INTO posts (id, title, body)
SELECT seq, CONCAT('post ', seq), REPEAT(CONCAT('lorem ipsum ', seq, ' '), 20) FROM seq_1_to_300;
