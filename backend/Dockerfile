# 1. ใช้ Base Image ที่เป็น PHP 8.2 แบบ FPM (เสถียรและเบากว่า)
FROM php:8.2-fpm-bullseye

# 2. ติดตั้ง Nginx (Web Server แทน Apache เพื่อตัดปัญหา MPM ทิ้ง)
RUN apt-get update && apt-get install -y \
    nginx \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*

# 3. ติดตั้ง Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. ตั้งค่า Nginx ให้รัน PHP ได้ (แทนที่ไฟล์คอนฟิกเดิม)
RUN echo 'server { \
    listen 80; \
    root /var/www/html; \
    index index.php index.html; \
    location / { \
        try_files $uri $uri/ /index.php?$query_string; \
    } \
    location ~ \.php$ { \
        include fastcgi_params; \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_index index.php; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
    } \
}' > /etc/nginx/sites-available/default

# 5. กำหนดพื้นที่ทำงาน
WORKDIR /var/www/html

# 6. Copy ไฟล์โปรเจกต์
COPY ./src /var/www/html/

# 7. ตั้งค่าสิทธิ์โฟลเดอร์
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# 8. สั่งรันทั้ง PHP-FPM และ Nginx พร้อมกัน
EXPOSE 80
CMD php-fpm -D && nginx -g "daemon off;"
