# Build the static front-end, then serve it with the PHP API on Apache.
FROM node:22-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.3-apache
RUN a2enmod rewrite headers && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
COPY --from=builder /app/dist/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html/api/data /var/www/html/uploads
EXPOSE 80
