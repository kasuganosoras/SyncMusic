FROM twosee/swoole-coroutine

RUN apt update && apt install -y python3 python3-mutagen nginx

COPY ./entrypoint.sh /entrypoint.sh

ENTRYPOINT [ "/entrypoint.sh" ]