CREATE DATABASE IF NOT EXISTS createposts;
USE createposts;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    openai_api_key VARCHAR(255) NULL,
    openai_model VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wordpress_sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    site_name VARCHAR(100) NOT NULL,
    site_url VARCHAR(255) NOT NULL,
    rest_style VARCHAR(10) NOT NULL DEFAULT 'pretty',
    username VARCHAR(100) NOT NULL,
    application_password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS published_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    site_id INT NULL,
    title VARCHAR(255) NOT NULL,
    remote_post_id INT NOT NULL,
    status VARCHAR(20) NOT NULL,
    posted_by INT NULL,
    site_name_snapshot VARCHAR(100) NULL,
    site_url_snapshot VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_site FOREIGN KEY (site_id) REFERENCES wordpress_sites(id) ON DELETE SET NULL
) ENGINE=InnoDB;
