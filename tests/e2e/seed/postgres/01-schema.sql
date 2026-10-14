-- Seed data for the e2e suite: two databases with deterministic rows.
CREATE DATABASE inventory;
CREATE DATABASE crm;

\connect inventory
CREATE TABLE items (id INT PRIMARY KEY, sku TEXT NOT NULL, qty INT NOT NULL);
INSERT INTO items SELECT g, 'sku-' || g, g % 13 FROM generate_series(1, 1500) AS g;

\connect crm
CREATE TABLE contacts (id INT PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL);
INSERT INTO contacts SELECT g, 'contact ' || g, 'contact' || g || '@example.com' FROM generate_series(1, 800) AS g;
