# Deploying the webOS Chat server

How to run the OSCAR server at `oscar.webosarchive.org` on the Ubuntu web server. The daemon
is one long-running PHP process listening on TCP 5190. It doesn't run under nginx or PHP-FPM;
nginx only comes in later, as an optional TLS front (step 7).

## What it needs

- Ubuntu 22.04 or 24.04, with PHP 8.1 or later on the command line: `php-cli`. It uses no
  extensions beyond the standard build, plus `php-mysql` (PDO MySQL) once accounts come from the
  App Museum's database.
- TCP 5190 open to the internet. Every webOS device connects there.
- A DNS name: `oscar.webosarchive.org`, an A record (and AAAA, if the host has IPv6) for this
  server.

## 1. DNS

Point `oscar.webosarchive.org` at the server, and check it from elsewhere:

```sh
dig +short oscar.webosarchive.org
```

webOS CE and Lunacy reach the server by that name: their AIM plugin defaults to it. Stock
webOS devices reach it through a hosts line their redirect package writes (see the plan doc).
That line holds the server's IP address, so if the address changes, the package has to change
too.

## 2. Install

```sh
sudo apt install php-cli php-mysql
sudo useradd --system --home /var/lib/oscar --shell /usr/sbin/nologin oscar
sudo mkdir -p /opt/oscar /etc/oscar /var/lib/oscar
sudo git clone <this repository> /opt/oscar          # or copy the folder there
sudo chown -R oscar:oscar /var/lib/oscar
```

Only `server/` and `deploy/` are needed on the server. `test/`, `client/`, `ref/` and `build/`
are for development.

## 3. Configure

```sh
sudo cp /opt/oscar/server/config.example.ini /etc/oscar/config.ini
sudo chown root:oscar /etc/oscar/config.ini && sudo chmod 640 /etc/oscar/config.ini
```

Set, in `/etc/oscar/config.ini`:

| Key | Production value | Why |
|---|---|---|
| `listen` | `0.0.0.0:5190` | Every device connects here |
| `advertise` | `oscar.webosarchive.org:5190` | The address a client is sent to for its second (BOS) connection, resolved by ordinary DNS |
| `data_dir` | `/var/lib/oscar` | Buddy lists and offline messages, one JSON file per user |
| `accounts` | `file` to start, `pdo` once step 6 is done | Where sign-ins are checked |
| `debug` | `false` | `true` logs every SNAC in and out |

With `accounts = file`, users are in `/var/lib/oscar/accounts.json`, keyed by the screen name
with case and spaces removed:

```json
{
  "codepoet": {"screenName": "codepoet", "chatKey": "a-long-random-key"}
}
```

Every setting can also come from the environment, as `OSCAR_<KEY>` (for example
`OSCAR_ADVERTISE`).

## 4. Run it under systemd

```sh
sudo cp /opt/oscar/deploy/oscard.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now oscard
journalctl -u oscard -f        # "listening on 0.0.0.0:5190, BOS advertised as …"
```

The unit runs as `oscar`, may write only to `/var/lib/oscar`, and restarts the daemon if it
exits.

## 5. Open the port

```sh
sudo ufw allow 5190/tcp
```

Then check it from outside the server: `nc -vz oscar.webosarchive.org 5190`.

## 6. Accounts from the App Museum

This replaces `accounts.json` with the community accounts:

1. Apply `deploy/museum-chat-key.sql` to the Museum's database. It adds `chat_key_md5`, the hex
   MD5 of each account's chat key, to `accounts`.
2. Give the server a read-only database user (the commented lines in that file).
3. In `config.ini`: `accounts = pdo`, and `db_dsn`, `db_user`, `db_pass`.
4. `sudo systemctl restart oscard`.

The server then reads `username`, `chat_key_md5` and `status` (active accounts only) at each
sign-in, so a key rotated on the website works at the next sign-in. Still to build, on the
Museum side (see the plan doc): generating and rotating the key on the account page, and the
`device.php` method `getChatCredentials` that hands it to a signed-in CE or Lunacy device.

Screen names follow AIM's rules. A name of digits only is taken as ICQ, and some characters
are refused, so community usernames outside those rules need a mapping before they can chat.

## 7. TLS for the clients that can use it (optional)

Stock webOS devices can only speak plain TCP. webOS CE and Lunacy can use TLS once their AIM
plugin is pointed at a TLS port. nginx's stream module can terminate TLS in front of the daemon:

```sh
sudo apt install libnginx-mod-stream
sudo cp /opt/oscar/deploy/nginx-stream.conf /etc/nginx/oscar-stream.conf
# add at the top level of /etc/nginx/nginx.conf (outside http {}):
#     include /etc/nginx/oscar-stream.conf;
sudo certbot certonly --nginx -d oscar.webosarchive.org   # if there's no certificate yet
sudo nginx -t && sudo systemctl reload nginx
sudo ufw allow 5191/tcp
```

## Checking it works

The test client in `test/` is a libpurple 2.14.1 AIM client (Debian bullseye, the last libpurple
that has AIM). It signs in the way a webOS device does:

```sh
docker build -t oscar-purple-client test
docker run --rm --network host oscar-purple-client oscar.webosarchive.org 5190 <name> <chat key>
```

`SIGNED ON as <name>` means sign-in and the BOS session both work. Run two clients, each with
the other as a buddy and a message, to check presence and delivery
([README.md](README.md), "Test it").

## Running it

- **Logs:** `journalctl -u oscard`. Every connection, sign-in, refusal and drop is a line;
  `debug = true` adds every SNAC.
- **Updating:** `cd /opt/oscar && sudo git pull && sudo systemctl restart oscard`. A restart
  drops every session, and clients sign in again by themselves.
- **Backups:** `/var/lib/oscar` (buddy lists, offline messages, and `accounts.json` if used).
- **Limits:** one process with `stream_select`, which PHP caps at about 1,000 connections
  (FD_SETSIZE). Past that, the daemon needs an event loop that isn't select-based, or several
  instances. There is no rate limiting yet beyond what clients obey on their own.
- **Security:** stock webOS signs in with an MD5 challenge over plain TCP. That's why the AIM
  password is a chat key separate from the community account's password.
