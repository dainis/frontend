FROM ubuntu:xenial

RUN apt-get update && apt-get install --assume-yes --quiet apache2 \
curl \
wget \
build-essential \
exiftran \
php7.0 \
libapache2-mod-php7.0 \
php7.0-curl \
php7.0-gd \
php7.0-mcrypt \
php7.0-mysql \
php-pear \
php-apcu \
libpcre3-dev \
php7.0-dev \
php-imagick

RUN a2enmod rewrite && a2enmod deflate && a2enmod expires && a2enmod headers

RUN pecl install oauth && mkdir -p /etc/php/7.0/apache2/conf.d && echo "extension=oauth.so" >> /etc/php/7.0/apache2/conf.d/oauth.ini

RUN a2dissite 000-default && \
  sed -e 's/file_uploads.*/file_uploads = On/g' -e 's/upload_max_filesize.*/upload_max_filesize = 16M/g' -e 's/post_max_size.*/post_max_size = 16M/g' /etc/php/7.0/apache2/php.ini > /etc/php/7.0/apache2/php.ini.tmp && \
  mv /etc/php/7.0/apache2/php.ini.tmp /etc/php/7.0/apache2/php.ini && \
  ln -sf /proc/self/fd/1 /var/log/apache2/access.log && \
  ln -sf /proc/self/fd/1 /var/log/apache2/error.log

ADD src/ /var/www/openphoto/src

RUN mkdir -p /var/www/openphoto/src/userdata /var/www/openphoto/src/html/assets/cache /var/www/openphoto/src/html/photos && \
cp /var/www/openphoto/src/configs/openphoto-vhost.conf /etc/apache2/sites-available/openphoto.conf && \
sed 's/\/path\/to\/openphoto\/html\/directory/\/var\/www\/openphoto\/src\/html/g' /var/www/openphoto/src/configs/openphoto-vhost.conf > /etc/apache2/sites-available/openphoto.conf && \
sed -i '/yourdomainname\.com/d' /etc/apache2/sites-available/openphoto.conf && \
a2ensite openphoto

CMD /bin/bash -c "source /etc/apache2/envvars && apache2 -DFOREGROUND"
