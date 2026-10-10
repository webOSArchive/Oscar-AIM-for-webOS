# webOS Chat: an OSCAR (AIM) server

An AIM server for webOS devices, so webOS CE, Lunacy and stock webOS phones can chat through
the webOS Community account. It speaks the part of OSCAR that libpurple's AIM plugin uses:
the plugin every webOS release since 1.4.5 ships (libpurple 2.5.1 on stock webOS, 2.14 on CE).

The plan, the measurements behind it and the order of work are in the shared plan doc.

## Layout

| Path | What |
|---|---|
| `server/` | The server: PHP 8, no extensions beyond the standard build (PDO for MySQL in production) |
| `server/bin/oscard` | Entry point: `php server/bin/oscard [config.ini]` |
| `server/config.example.ini` | Settings; each can also be an `OSCAR_<KEY>` environment variable |
| `server/data/` | Development accounts (`accounts.json`), buddy lists and offline messages |
| `test/` | A libpurple 2.14.1 test client (Debian bullseye, the last libpurple with AIM), in Docker |
| `client/libaim/` | Builds the AIM plugin for webOS CE's libpurple 2.14.13 on the TouchPad |
| `ref/` | Pidgin 2.14.2 (the last release with OSCAR) and 2.14.13 sources; not committed |
| `build/` | Cross-build inputs and output; not committed |

## Run it

No PHP on the machine? Docker's official image does:

```sh
docker run -d --name oscard --network host --user $(id -u):$(id -g) \
    -v $PWD/server:/app -w /app -e OSCAR_ADVERTISE=<this host's IP>:5190 \
    php:8.3-cli php bin/oscard
```

`advertise` is the address clients are sent to for their second (BOS) connection, so it must
be reachable from the devices. Development accounts are in `server/data/accounts.json`: the
normalized screen name, its display form and its chat key (the AIM password).

## Test it

```sh
docker build -t oscar-purple-client test
docker run --rm --network host oscar-purple-client 127.0.0.1 5190 TouchPadTest webos-chat-test LunacyTest "hello"
docker run --rm --network host oscar-purple-client 127.0.0.1 5190 LunacyTest webos-chat-test TouchPadTest "hi back"
```

Each client signs in with the MD5 login and no TLS, as a webOS device does, adds the other as
a buddy, and messages it once it is online. Measured 2026-10-10: both sign in, see each other
come online, and receive each other's messages; a wrong key is refused ("Incorrect password").

## What the server does

| Family | Handled |
|---|---|
| 0x17 authorizer | MD5 key, BUCP login (both hash forms), cookie and BOS address |
| 0x01 generic | Families, versions, rate classes (v2 and v3 formats), own info, idle, client ready; new-service requests are refused |
| 0x02 locate | Rights, profile, away message, capabilities, user info queries |
| 0x03 buddy | Rights; buddy arrived and departed, sent to everyone who lists the user |
| 0x04 ICBM | Parameters, messages (channels 1 and 2 forwarded), delivery acks, typing, offline messages delivered at sign-on |
| 0x09 privacy | Rights |
| 0x13 feedbag | Rights, the stored list, add, modify, delete |

Not yet: buddy icons (0x10), chat rooms (0x0D, 0x0E), the permit and deny lists' effect on
presence, warnings, TLS (to be nginx's `stream` module in front), and the MySQL account store
against a live Museum database (`PdoAccounts` is written, not run).

## Deployment (production, planned)

- `oscar.webosarchive.org` points at the web server; the daemon runs under systemd on 5190.
- Accounts come from the App Museum's database (`accounts = pdo`), with a `chat_key_md5`
  column; see the plan doc.
- Stock devices reach it through one `/etc/hosts` line for `login.messaging.aol.com`.
