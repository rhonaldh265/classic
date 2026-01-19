# Use official PHP image
FROM php:8.2-apache

# Copy all project files to web root
COPY . /var/www/html/

# Expose port 80
EXPOSE 80

# Start Apache when container runs
CMD ["apache2-foreground"]
