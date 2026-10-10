/*
 * A minimal libpurple AIM client for testing the server, after Pidgin's nullclient.
 *
 *   purple-client <server> <port> <screen name> <password> [buddy] [message]
 *
 * Signs in with the MD5 (BUCP) login and no TLS, as a webOS device does. Adds [buddy] to
 * the server-stored list, and once the buddy is online sends it [message]. Prints every
 * sign-on, presence change and message, and runs until killed.
 */
#include <purple.h>
#include <glib.h>
#include <stdio.h>
#include <string.h>
#include <signal.h>

#define UI_ID "purple-client"

static const char *g_buddy, *g_message;
static gboolean g_sent;

static guint add_input(gint fd, PurpleInputCondition cond, PurpleInputFunction func, gpointer data);
typedef struct { PurpleInputFunction f; guint r; gpointer d; } Closure;
static gboolean io_invoke(GIOChannel *s, GIOCondition c, gpointer data)
{
	Closure *cl = data; int pc = 0;
	if (c & (G_IO_IN | G_IO_HUP | G_IO_ERR)) pc |= PURPLE_INPUT_READ;
	if (c & (G_IO_OUT | G_IO_HUP | G_IO_ERR | G_IO_NVAL)) pc |= PURPLE_INPUT_WRITE;
	cl->f(cl->d, g_io_channel_unix_get_fd(s), pc);
	return TRUE;
}
static guint add_input(gint fd, PurpleInputCondition cond, PurpleInputFunction func, gpointer data)
{
	Closure *cl = g_new0(Closure, 1); GIOCondition c = 0; GIOChannel *ch;
	cl->f = func; cl->d = data;
	if (cond & PURPLE_INPUT_READ) c |= G_IO_IN | G_IO_HUP | G_IO_ERR;
	if (cond & PURPLE_INPUT_WRITE) c |= G_IO_OUT | G_IO_HUP | G_IO_ERR | G_IO_NVAL;
	ch = g_io_channel_unix_new(fd);
	cl->r = g_io_add_watch_full(ch, G_PRIORITY_DEFAULT, c, io_invoke, cl, g_free);
	g_io_channel_unref(ch);
	return cl->r;
}
static PurpleEventLoopUiOps loop_ops = {
	g_timeout_add, g_source_remove, add_input, g_source_remove, NULL, g_timeout_add_seconds,
	NULL, NULL, NULL
};

static void try_send(PurpleAccount *account)
{
	PurpleConversation *conv;
	if (g_sent || !g_buddy || !g_message) return;
	conv = purple_conversation_new(PURPLE_CONV_TYPE_IM, account, g_buddy);
	purple_conv_im_send(PURPLE_CONV_IM(conv), g_message);
	printf("SENT to %s: %s\n", g_buddy, g_message);
	g_sent = TRUE;
}

static void signed_on(PurpleConnection *gc, gpointer d)
{
	PurpleAccount *account = purple_connection_get_account(gc);
	printf("SIGNED ON as %s\n", purple_account_get_username(account));
	if (g_buddy && !purple_find_buddy(account, g_buddy)) {
		PurpleGroup *grp = purple_find_group("Buddies");
		PurpleBuddy *b;
		if (!grp) { grp = purple_group_new("Buddies"); purple_blist_add_group(grp, NULL); }
		b = purple_buddy_new(account, g_buddy, NULL);
		purple_blist_add_buddy(b, NULL, grp, NULL);
		purple_account_add_buddy(account, b);
		printf("ADDED buddy %s\n", g_buddy);
	}
}
static void signed_off(PurpleConnection *gc, gpointer d) { printf("SIGNED OFF\n"); }
static void conn_error(PurpleConnection *gc, PurpleConnectionError e, const char *desc, gpointer d)
{ printf("CONNECTION ERROR %d: %s\n", e, desc); }
static void buddy_on(PurpleBuddy *b, gpointer d)
{
	printf("BUDDY ONLINE %s\n", purple_buddy_get_name(b));
	if (g_buddy && !strcmp(purple_normalize(b->account, b->name), purple_normalize(b->account, g_buddy)))
		try_send(b->account);
}
static void buddy_off(PurpleBuddy *b, gpointer d) { printf("BUDDY OFFLINE %s\n", purple_buddy_get_name(b)); }
static void buddy_status(PurpleBuddy *b, PurpleStatus *o, PurpleStatus *n, gpointer d)
{ printf("BUDDY STATUS %s: %s\n", purple_buddy_get_name(b), purple_status_get_name(n)); }
static void received_im(PurpleAccount *a, char *who, char *msg, PurpleConversation *c, PurpleMessageFlags f, gpointer d)
{ printf("RECEIVED from %s: %s\n", who, msg); fflush(stdout); }
static void typing(PurpleAccount *a, const char *who, gpointer d) { printf("TYPING %s\n", who); }

static void debug_print(PurpleDebugLevel level, const char *cat, const char *arg)
{ if (g_getenv("PURPLE_DEBUG")) printf("debug %s: %s", cat ? cat : "", arg); }
static gboolean debug_enabled(PurpleDebugLevel l, const char *c) { return TRUE; }
static PurpleDebugUiOps debug_ops = { debug_print, debug_enabled, NULL, NULL, NULL, NULL };

int main(int argc, char *argv[])
{
	PurpleAccount *account; PurpleSavedStatus *st; GMainLoop *loop;
	static int handle;
	if (argc < 5) { fprintf(stderr, "usage: %s server port name password [buddy] [message]\n", argv[0]); return 2; }
	g_buddy = argc > 5 ? argv[5] : NULL; g_message = argc > 6 ? argv[6] : NULL;
	setvbuf(stdout, NULL, _IOLBF, 0);
	signal(SIGCHLD, SIG_IGN);
	loop = g_main_loop_new(NULL, FALSE);
	purple_util_set_user_dir("/tmp/purple-client");
	purple_debug_set_enabled(FALSE);
	purple_debug_set_ui_ops(&debug_ops);
	purple_eventloop_set_ui_ops(&loop_ops);
	if (!purple_core_init(UI_ID)) { fprintf(stderr, "libpurple init failed\n"); return 1; }
	purple_set_blist(purple_blist_new()); purple_blist_load();

	account = purple_account_new(argv[3], "prpl-aim");
	purple_account_set_password(account, argv[4]);
	purple_account_set_string(account, "server", argv[1]);
	purple_account_set_int(account, "port", atoi(argv[2]));
	purple_account_set_string(account, "login_type", "md5_login");
	purple_account_set_string(account, "encryption", "no_encryption");
	purple_accounts_add(account);
	purple_account_set_enabled(account, UI_ID, TRUE);

	purple_signal_connect(purple_connections_get_handle(), "signed-on", &handle, PURPLE_CALLBACK(signed_on), NULL);
	purple_signal_connect(purple_connections_get_handle(), "signed-off", &handle, PURPLE_CALLBACK(signed_off), NULL);
	purple_signal_connect(purple_connections_get_handle(), "connection-error", &handle, PURPLE_CALLBACK(conn_error), NULL);
	purple_signal_connect(purple_blist_get_handle(), "buddy-signed-on", &handle, PURPLE_CALLBACK(buddy_on), NULL);
	purple_signal_connect(purple_blist_get_handle(), "buddy-signed-off", &handle, PURPLE_CALLBACK(buddy_off), NULL);
	purple_signal_connect(purple_blist_get_handle(), "buddy-status-changed", &handle, PURPLE_CALLBACK(buddy_status), NULL);
	purple_signal_connect(purple_conversations_get_handle(), "received-im-msg", &handle, PURPLE_CALLBACK(received_im), NULL);
	purple_signal_connect(purple_conversations_get_handle(), "buddy-typing", &handle, PURPLE_CALLBACK(typing), NULL);

	st = purple_savedstatus_new(NULL, PURPLE_STATUS_AVAILABLE);
	purple_savedstatus_activate(st);
	g_main_loop_run(loop);
	return 0;
}
