FROM swoft/alphp:fpm
RUN apk add mutagen
ENTRYPOINT /run.sh & php /var/www/server.php