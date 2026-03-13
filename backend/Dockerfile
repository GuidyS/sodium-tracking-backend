# 1. Base Image
FROM php:8.0-apache

# 2. ติดตั้ง System Dependencies และ Library ที่ Composer ต้องการ (unzip, git)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd \
    && docker-php-ext-enable mysqli \
    && rm -rf /var/lib/apt/lists/*

# 3. ติดตั้ง Composer (ก๊อปปี้ตัวรันมาจาก Official Image)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. ตั้งค่าระบบ Apache (ล้างใหม่แล้วใช้ชุดนี้ครับ)
RUN a2enmod rewrite \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    # สร้างไฟล์คอนฟิกเพื่อบังคับโหลด mpm_prefork แค่ตัวเดียว
    && echo "LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so" > /etc/apache2/mods-available/mpm_prefork.load \
    # สั่งลบการเชื่อมโยง (symlink) ของ mpm ตัวอื่นๆ ที่อาจติดมาใน mods-enabled
    && find /etc/apache2/mods-enabled -name "mpm_*" -delete \
    # บังคับเปิดใช้งานเฉพาะ prefork เท่านั้น
    && ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load

# 5. กำหนดพื้นที่ทำงาน
WORKDIR /var/www/html

# 6. Copy ไฟล์ composer.json เข้าไปก่อน (ถ้ามี) เพื่อลง Library
# ถ้ายังไม่มีไฟล์ในเครื่อง ให้ข้ามส่วนนี้ไปก่อนได้ แล้วค่อยรันผ่าน terminal
# COPY composer.json ./ 
# RUN composer install --no-interaction --optimize-autoloader

# 7. Copy ไฟล์โปรเจกต์ทั้งหมด
COPY ./src /var/www/html/

# 8. ตั้งค่าสิทธิ์โฟลเดอร์
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# เพิ่มคำสั่งรันให้ Apache ทำงานใน Foreground (สำคัญสำหรับ Cloud)
CMD ["apache2-foreground"]

EXPOSE 80
