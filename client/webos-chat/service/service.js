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

	log: function () {
		console.log("webos-chat: " + Array.prototype.slice.call(arguments).join(" "));
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

	/** The webOS Chat accounts on this device (there should be at most one). */
	chatAccounts: function (done) {
		Chat.call("palm://com.palm.service.accounts/", "listAccounts", { templateId: Chat.TEMPLATE }, function (err, r) {
			done(err, (r && r.results) || []);
		});
	},

	remove: function (accounts, done) {
		if (!accounts.length) return done();
		var a = accounts[0];
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
		}, function (err) {
			if (err) return done("createAccount failed: " + err);
			Chat.log("created the webOS Chat account for", cred.screenName);
			done(null, "created");
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
	 * done(error, outcome): "signed-out", "username-required", "unchanged", "created".
	 * $profileChanged: the device's webOS Account record has just changed.
	 */
	sync: function (profileChanged, done) {
		Chat.token(profileChanged ? 5 : 1, function (err, token) {
			if (err) return done(err);
			var t = { token: token };
			if (!t.token) {
				// Signed out: the webOS Chat account goes with the rest of the account's data.
				return Chat.chatAccounts(function (err, accounts) {
					if (err) return done("listAccounts failed: " + err);
					if (!accounts.length) Chat.log("no webOS Account signed in; nothing to do");
					Chat.remove(accounts, function () { done(null, "signed-out"); });
				});
			}
			Chat.fetchCredentials(t.token, function (err, status, body) {
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
					var keep = accounts.length === 1 ? accounts[0] : null;
					var after = function (same) {
						if (same) return done(null, "unchanged");
						Chat.remove(accounts, function () { Chat.create(body, done); });
					};
					if (keep) Chat.matches(keep, body, after); else after(false);
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
		Chat.sync(!!args.profileChanged, function (err, outcome) {
			if (err) Chat.log("sync failed:", err);
			future.result = err ? { returnValue: false, errorText: String(err) } : { returnValue: true, outcome: outcome };
		});
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
