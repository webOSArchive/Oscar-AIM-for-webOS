/*
 * webOS Chat: the AIM account for the signed-in webOS Account.
 *
 * sync gets the webOS Account's token (com.palm.accountservices/getAccountToken), trades it
 * at the App Museum for the screen name and pass phrase (device.php getChatCredentials), and
 * makes sure there is one webOS Chat account (template org.webosarchive.chat) with them. The
 * account carries AIM's capability, so Palm's IM transport and libaim.so (which defaults to
 * oscar.webosarchive.org) sign it in like any AIM account; nobody types a pass phrase.
 *
 * It runs at start and hourly (configuration/activities), which follows a webOS Account
 * sign-in, a username change and a pass phrase replaced on the website. Signed out, or with
 * a username that is still an email, it changes nothing. ES5 and curl, as on a TouchPad
 * (node 0.4's TLS can't reach the Museum; the community account's service uses curl too).
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

	/** done(error, outcome): "signed-out", "username-required", "unchanged", "created". */
	sync: function (done) {
		Chat.call("palm://com.palm.accountservices/", "getAccountToken", {}, function (err, t) {
			if (err || !t || !t.token) {
				Chat.log("no webOS Account signed in; nothing to do");
				return done(null, "signed-out");
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
		// Called by the start and hourly activities; the service framework adopts the
		// activity and completes it when this command ends (the hourly one recurs).
		Chat.sync(function (err, outcome) {
			if (err) Chat.log("sync failed:", err);
			future.result = err ? { returnValue: false, errorText: String(err) } : { returnValue: true, outcome: outcome };
		});
	}
});
