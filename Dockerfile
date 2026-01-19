FROM php:8.2-apache

# Enable Apache rewrite (common need)
RUN a2enmod rewrite

# Copy project files
COPY . /var/www/html/

# Configure Apache to use Render's PORT
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf \
 && sed -i 's/:80/:${PORT}/g' /etc/apache2/sites-available/000-default.conf

# Tell Apache to use the PORT env var
ENV PORT=10000

EXPOSE 10000

CMD ["apache2-foreground"]
