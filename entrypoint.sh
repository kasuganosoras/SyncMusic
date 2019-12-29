#!/bin/bash

rm /var/www/html/*
cd /app
cp index.html /var/www/html/
cp -R face* /var/www/html/
cp search.php /var/www/html/

service nginx start

php server.php