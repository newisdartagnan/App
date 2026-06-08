FROM php:8.0.1-apache

# ─── Sources Debian (archive pour cette vieille image) ───────────────────────
RUN sed -i 's/deb.debian.org/archive.debian.org/g'   /etc/apt/sources.list && \
    sed -i 's/security.debian.org/archive.debian.org/g' /etc/apt/sources.list && \
    sed -i '/stretch-updates/d' /etc/apt/sources.list

# ─── Dépendances système + extensions PHP ────────────────────────────────────
RUN apt-get update && apt-get install -y \
        libzip-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libgd-dev \
        unzip \
        cron \
        git \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install zip pdo pdo_mysql gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ─── Composer ────────────────────────────────────────────────────────────────
RUN curl -sS https://getcomposer.org/installer \
      | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version

# ─── Apache : activer mod_rewrite + AllowOverride All ────────────────────────
RUN a2enmod rewrite && \
    sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' \
        /etc/apache2/apache2.conf

# ─── Cron ────────────────────────────────────────────────────────────────────
RUN touch /var/log/cron.log && chmod 0644 /var/log/cron.log

# ─── Dossiers partagés ───────────────────────────────────────────────────────
RUN mkdir -p /var/www/html/uploads/resultats_pdf \
             /socket \
             /results/pdf \
    && chown -R www-data:www-data /var/www/html /socket /results \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /socket /results

# ─── Copier l'application AVANT d'installer les dépendances ──────────────────
COPY . /var/www/html/

# ─── Installer les dépendances Composer PENDANT le build ─────────────────────
RUN cd /var/www/html && \
    if [ -f "composer.json" ]; then \
        echo "📦 Installation des dépendances Composer..." && \
        composer install --no-interaction --optimize-autoloader && \
        echo "✅ Dépendances installées"; \
    else \
        echo "⚠️ Pas de composer.json trouvé"; \
    fi

# ─── Vérifier et forcer l'installation des librairies PDF si nécessaire ──────
RUN cd /var/www/html && \
    if [ ! -d "vendor/tecnickcom/tcpdf" ]; then \
        echo "📦 Installation de TCPDF..." && \
        composer require tecnickcom/tcpdf --no-interaction --optimize-autoloader; \
    fi && \
    if [ ! -d "vendor/setasign/fpdi" ]; then \
        echo "📦 Installation de FPDI..." && \
        composer require setasign/fpdi --no-interaction --optimize-autoloader; \
    fi && \
    if [ ! -d "vendor/mpdf/mpdf" ]; then \
        echo "📦 Installation de mPDF..." && \
        composer require mpdf/mpdf --no-interaction --optimize-autoloader; \
    fi

# ─── Créer les dossiers uploads avec bonnes permissions ──────────────────────
RUN mkdir -p /var/www/html/uploads/resultats_pdf \
    && chmod -R 755 /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads

# ─── Entrypoint (pour les tâches au démarrage seulement) ─────────────────────
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]