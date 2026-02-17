FROM nginx:alpine

WORKDIR /var/www
COPY . /var/www

COPY .docker/nginx.conf /etc/nginx/conf.d/default.conf
