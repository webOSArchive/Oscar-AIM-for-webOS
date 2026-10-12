/*
 * webOS Chat: the AIM account for the signed-in webOS Account.
 *
 * sync gets the webOS Account's token (com.palm.accountservices/getAccountToken), trades it
 * at the App Museum for the screen name and pass phrase (device.php getChatCredentials), and
 * makes sure there is one webOS Chat account (template org.webosarchive.chat) with them. The
 * account carries AIM's capability, so Palm's IM transport and libaim.so (which defaults to
 * oscar.webosarchive.org) sign it in like any AIM account; nobody types a pass phrase.
 *
 * It runs at start, hourly (a username or pass phrase changed on the website), and whenever
 * the device's webOS Account record changes (configuration/activities): signing in renames it
 * to the member, signing out back to a local name. The token itself (com.palm.palmprofile:1)
 * is only the account service's to read, so it is asked for, and since signing in renames the
 * record before it saves the token, a change with no token yet is asked about again for a
 * few seconds before it counts as signed out. Signed out, it removes the webOS Chat account, as signing out
 * clears the webOS Account's own data. With a username that is still an email, or when the
 * profile can't be read, it changes nothing. ES5 and curl, as on a TouchPad (node 0.4's TLS
 * can't reach the Museum; the community account's service uses curl too).
 *
 * The accounts service tells the IM transport about a new account once (onEnabled) and never
 * again, so an account made while the transport can't start would never sign in. On webOS CE
 * that is the first two minutes or so after a sign-in during first use, before the transport's
 * runtime is laid into cryptofs. So an account counts as made only once the transport has
 * written its login state for it, and one it never took is made again.
 *
 * Removing the webOS Chat account in Accounts turns chat off: the service remembers the account
 * it made (STATE), and when that account is gone without the service having removed it, it
 * makes no other until chat is added again in Accounts (the setup page calls enable). Signing
 * out of the webOS Account forgets that, as it forgets the rest of the account's data.
 */
var Foundations = IMPORTS.foundations;
var Class = Foundations.Class;
var Future = Foundations.Control.Future;
var PalmCall = Foundations.Comms.PalmCall;
if (typeof require === "undefined") {
	require = IMPORTS.require;
}

var Chat = {
	TEMPLATE: "org.webosarchive.chat",
	PROVIDER: "org.webosarchive.chat.aim",
	URL: "https://appcatalog.webosarchive.org/WebService/device.php?m=getChatCredentials",
	STATE: "/var/preferences/com.palm.webosarchive.chat/state.json",

	log: function () {
		console.log("webos-chat: " + Array.prototype.slice.call(arguments).join(" "));
	},

	/** A command's reply from (error, outcome, screenName). */
	reply: function (future) {
		return function (err, outcome, screenName) {
			if (err) Chat.log("failed:", err);
			future.result = err ? { returnValue: false, errorText: String(err) }
				: { returnValue: true, outcome: outcome, screenName: screenName || "" };
		};
	},

	/** A bus call as (error, result), errors as text. */
	call: function (uri, method, params, done) {
		PalmCall.call(uri, method, params).then(function (f) {
			var r;
			try { r = f.result; } catch (e) { return done(String(e && (e.errorText || e.message) || e)); }
			done(null, r);
		});
	},

	/** POSTs to the Museum with the account token: (error, httpStatus, parsedBody). */
	fetchCredentials: function (token, done) {
		var cp = require("child_process"), fs = require("fs");
		var base = "/tmp/webos-chat-" + process.pid + "-" + Date.now();
		var req = base + "-req.json", out = base + "-resp.json";
		try { fs.writeFileSync(req, "{}"); } catch (e) { return done("temp write failed: " + e); }
		var cmd = "/usr/bin/curl -s -S --max-time 30 -o '" + out + "' -w '%{http_code}' -X POST "
			+ "-H 'Content-Type: application/json' -H 'Connection: close' "
			+ "-H 'Authorization: PalmAuth token=" + String(token).replace(/[^A-Za-z0-9]/g, "") + "' "
			+ "--data-binary @'" + req + "' '" + Chat.URL + "'";
		cp.exec(cmd, { timeout: 35000 }, function (error, stdout) {
			var body = null, text = "";
			try { text = fs.readFileSync(out, "utf8"); fs.unlinkSync(out); } catch (e) {}
			try { fs.unlinkSync(req); } catch (e) {}
			try { body = JSON.parse(text); } catch (e) {}
			var status = parseInt(String(stdout).replace(/\D/g, ""), 10) || 0;
			if (error && !status) return done("curl failed: " + error);
			done(null, status, body);
		});
	},

	/** {accountId: the account this service made, off: chat turned off in Accounts}. */
	state: function () {
		try { return JSON.parse(require("fs").readFileSync(Chat.STATE, "utf8")) || {}; } catch (e) { return {}; }
	},

	saveState: function (st) {
		var fs = require("fs"), dir = Chat.STATE.replace(/\/[^\/]*$/, "");
		try {
			try { fs.mkdirSync(dir, 448); } catch (e) {}
			fs.writeFileSync(Chat.STATE + ".new", JSON.stringify(st));
			fs.renameSync(Chat.STATE + ".new", Chat.STATE);
		} catch (e) { Chat.log("saving the state failed:", e); }
	},

	/** The webOS Chat accounts on this device (there should be at most one). */
	chatAccounts: function (done) {
		Chat.call("palm://com.palm.service.accounts/", "listAccounts", { templateId: Chat.TEMPLATE }, function (err, r) {
			done(err, (r && r.results) || []);
		});
	},

	remove: function (accounts, done) {
		if (!accounts.length) return done();
		var a = accounts[0], st = Chat.state();
		// The service's own removals never count as chat turned off.
		if (st.accountId === a._id) { delete st.accountId; Chat.saveState(st); }
		Chat.log("removing the webOS Chat account for", a.username);
		Chat.call("palm://com.palm.service.accounts/", "deleteAccount", { accountId: a._id }, function (err) {
			if (err) Chat.log("deleteAccount failed:", err);
			Chat.remove(accounts.slice(1), done);
		});
	},

	create: function (cred, done) {
		Chat.call("palm://com.palm.service.accounts/", "createAccount", {
			templateId: Chat.TEMPLATE,
			username: cred.screenName,
			alias: cred.screenName,
			capabilityProviders: [{ id: Chat.PROVIDER }],
			credentials: { common: { password: cred.chatKey } }
		}, function (err, r) {
			var id = r && (r._id || (r.result && r.result._id));
			if (err || !id) return done("createAccount failed: " + (err || JSON.stringify(r)));
			Chat.log("created the webOS Chat account for", cred.screenName);
			done(null, id);
		});
	},

	/** Whether the stored account already has this screen name and pass phrase. */
	matches: function (account, cred, done) {
		if (String(account.username).toLowerCase() !== String(cred.screenName).toLowerCase()) return done(false);
		Chat.call("palm://com.palm.service.accounts/", "readCredentials", { accountId: account._id, name: "common" }, function (err, r) {
			done(!err && r && r.credentials && r.credentials.password === cred.chatKey);
		});
	},

	/**
	 * Whether the IM transport has taken the account: when the accounts service tells it about
	 * one (onEnabled) it writes the account's login state, which com.palm.* may read. Asked
	 * again every 3 s up to $tries times. Measured on webOS CE 3.1.0: that id may read it.
	 */
	taken: function (accountId, tries, done) {
		Chat.call("palm://com.palm.db/", "find", {
			query: { from: "com.palm.imloginstate.libpurple:1", where: [{ prop: "accountId", op: "=", val: accountId }] }
		}, function (err, r) {
			// A failed read is no evidence the transport missed it, and remaking it won't help.
			if (err) Chat.log("reading the login state failed:", err);
			if (err || (r && r.results && r.results.length)) return done(true);
			if (tries <= 1) return done(false);
			setTimeout(function () { Chat.taken(accountId, tries - 1, done); }, 3000);
		});
	},

	/**
	 * Makes the account and waits for the transport to take it. One it doesn't take in 30 s
	 * (the transport couldn't start) is removed and made again 20 s later, up to $tries times.
	 */
	createTaken: function (cred, tries, done) {
		Chat.create(cred, function (err, id) {
			if (err) return done(err);
			Chat.taken(id, 10, function (ok) {
				if (ok) {
					var st = Chat.state();
					st.accountId = id;
					Chat.saveState(st);
					return done(null, "created");
				}
				Chat.log("the IM transport didn't take the account", id);
				Chat.remove([{ _id: id, username: cred.screenName }], function () {
					if (tries <= 1) return done("the IM transport didn't take the account; trying again at the next run");
					setTimeout(function () { Chat.createTaken(cred, tries - 1, done); }, 20000);
				});
			});
		});
	},

	/**
	 * The account token, asked again every 5 s up to $tries times while there is none. A
	 * signed-out device answers NO_TOKEN ("Token could not be fetched"); that is "" here, and
	 * any other failure (no Community Account installed) is an error.
	 */
	token: function (tries, done) {
		Chat.call("palm://com.palm.accountservices/", "getAccountToken", {}, function (err, t) {
			var none = err && /NO_TOKEN|Token could not be fetched/.test(err);
			if ((err && !none) || (!err && !t)) return done("getAccountToken failed: " + (err || "no reply"));
			var token = none ? "" : t.token || "";
			if (token || tries <= 1) return done(null, token);
			setTimeout(function () { Chat.token(tries - 1, done); }, 5000);
		});
	},

	/**
	 * done(error, outcome, screenName): outcome "signed-out", "username-required", "off",
	 * "unchanged" or "created". $how.profileChanged: the device's webOS Account record has just
	 * changed. $how.enable: chat was added in Accounts, so it is on again.
	 */
	sync: function (how, done) {
		Chat.token(how.profileChanged ? 5 : 1, function (err, token) {
			if (err) return done(err);
			if (!token) {
				// Signed out: the webOS Chat account goes with the rest of the account's data,
				// and so does chat having been turned off.
				Chat.saveState({});
				return Chat.chatAccounts(function (err, accounts) {
					if (err) return done("listAccounts failed: " + err);
					if (!accounts.length) Chat.log("no webOS Account signed in; nothing to do");
					Chat.remove(accounts, function () { done(null, "signed-out"); });
				});
			}
			Chat.fetchCredentials(token, function (err, status, body) {
				if (err) return done(err);
				if (status === 409) {
					Chat.log("the webOS Account needs a username before it can chat");
					return done(null, "username-required");
				}
				if (status !== 200 || !body || !body.screenName || !body.chatKey) {
					return done("getChatCredentials answered " + status + " " + JSON.stringify(body));
				}
				Chat.chatAccounts(function (err, accounts) {
					if (err) return done("listAccounts failed: " + err);
					var st = Chat.state();
					var present = function (id) {
						for (var i = 0; i < accounts.length; i++) if (accounts[i]._id === id) return true;
						return false;
					};
					if (how.enable) {
						st.off = false;
					} else if (st.accountId && !present(st.accountId)) {
						Chat.log("the webOS Chat account was removed in Accounts; chat is off until it is added again");
						st.off = true;
						delete st.accountId;
					}
					Chat.saveState(st);
					if (st.off) return done(null, "off", body.screenName);
					var finish = function (err, outcome) { done(err, outcome, body.screenName); };
					var keep = accounts.length === 1 ? accounts[0] : null;
					var after = function (same) {
						if (same) {
							// One made before the service kept state is taken as its own.
							if (st.accountId !== keep._id) { st.accountId = keep._id; Chat.saveState(st); }
							return finish(null, "unchanged");
						}
						Chat.remove(accounts, function () { Chat.createTaken(body, 5, finish); });
					};
					if (!keep) return after(false);
					Chat.matches(keep, body, function (same) {
						if (!same) return after(false);
						// An account the transport never took never signs in, so it is made again.
						Chat.taken(keep._id, 1, function (ok) {
							if (!ok) Chat.log("the IM transport never took the account; making it again");
							after(ok);
						});
					});
				});
			});
		});
	}
};

var ServiceAssistant = Class.create({
	setup: function () {}
});

var SyncCommandAssistant = Class.create({
	run: function (future) {
		// Called by the activities; the service framework adopts the activity and, when this
		// command ends, has complete() below end it.
		var args = this.controller.args || {};
		Chat.sync({ profileChanged: !!args.profileChanged }, Chat.reply(future));
	},

	// An activity completed without restart is over, so the hourly and profile-watch
	// activities, whose callbacks say "recur", are completed with it; the start one isn't.
	// A trigger that answered with an error (a watch db8 refused) would only fire again at
	// once, so that activity is ended instead, and says why.
	complete: function (activity) {
		var args = (this.controller && this.controller.args) || {};
		var trigger = args.$activity && args.$activity.trigger;
		var failed = trigger && trigger.returnValue === false;
		if (failed) Chat.log("activity trigger failed, ending it:", trigger.errorText || JSON.stringify(trigger));
		return activity.complete(!!args.recur && !failed);
	}
});

// The setup page in Accounts (org.webosarchive.chat.setup): chat added.
var EnableCommandAssistant = Class.create({
	run: function (future) {
		Chat.sync({ enable: true }, Chat.reply(future));
	}
});

