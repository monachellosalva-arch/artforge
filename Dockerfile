FROM php:8.2-apache

# Installation des extensions nécessaires (SQLite et cURL)
RUN apt-get update && apt-get install -y libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_sqlite curl

# Activation du module de réécriture Apache
RUN a2enmod rewrite

# Copie de votre code
COPY . /var/www/html/

# Configuration des droits
RUN chown -R www-data:www-data /var/www/html/

# Création du dossier de données persistant
RUN mkdir -p /var/data && chown -R www-data:www-data /var/data

# Configuration d'Apache pour écouter sur le port 10000 (exigé par Render)
RUN sed -i 's/Listen 80/Listen 10000/g' /etc/apache2/ports.conf && \
    sed -i 's/:80/:10000/g' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
