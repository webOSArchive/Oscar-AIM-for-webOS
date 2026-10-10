# A libpurple 2.14.1 client with AIM (Debian bullseye: the last libpurple that has it).
FROM debian:bullseye-slim
RUN printf "deb http://archive.debian.org/debian bullseye main\ndeb http://archive.debian.org/debian bullseye-updates main\ndeb http://archive.debian.org/debian-security bullseye-security main\n" > /etc/apt/sources.list && rm -f /etc/apt/sources.list.d/* && apt-get -o Acquire::Check-Valid-Until=false update && apt-get install -y --no-install-recommends gcc libc6-dev pkg-config libpurple-dev libglib2.0-dev && rm -rf /var/lib/apt/lists/*
COPY purple-client.c /src/
RUN gcc -O1 -o /usr/local/bin/purple-client /src/purple-client.c $(pkg-config --cflags --libs purple glib-2.0)
ENTRYPOINT ["purple-client"]
