/*
 * webOS Chat in Accounts: the account type's own page (validator.customUI), shown inside
 * Accounts instead of its username and password form, since webOS Chat signs in with the
 * webOS Account and nobody types a pass phrase.
 *
 * Added from Add Account ("create"), it turns chat on: com.palm.webosarchive.chat/enable
 * makes the account, and the page says so. Opened from the account's Change Login Settings
 * ("modify"), it shows the screen name. Either way it hands Accounts no account to make
 * (returnValue false), as the service makes the webOS Chat account itself. Removing the account
 * is Accounts' own Remove Account; the service takes that as chat turned off.
 */
enyo.kind({
	name: "ChatSetup",
	kind: "VFlexBox",
	className: "enyo-bg",
	width: "100%",
	published: {
		params: null
	},
	components: [
		{kind: "Toolbar", pack: "center", className: "enyo-toolbar-light accounts-header", components: [
			{kind: "Image", src: "images/webos-chat-48x48.png"},
			{kind: "Control", content: $L("webOS Chat")}
		]},
		{className: "accounts-header-shadow"},
		{kind: "Scroller", flex: 1, components: [
			{kind: "Control", className: "box-center", components: [
				{name: "working", kind: "HFlexBox", align: "center", components: [
					{name: "spinner", kind: "Spinner"},
					{name: "workingText", className: "accounts-body-title"}
				]},
				{name: "title", className: "accounts-body-title"},
				{name: "text", className: "accounts-body-text", style: "margin-top: 8px;"}
			]}
		]},
		{className: "accounts-footer-shadow"},
		{kind: "Toolbar", className: "enyo-toolbar-light", components: [
			{name: "doneButton", kind: "Button", className: "accounts-toolbar-btn", caption: $L("Done"), onclick: "finish"}
		]},
		{kind: "CrossAppResult"},
		{name: "enable", kind: "PalmService", service: "palm://com.palm.webosarchive.chat/", method: "enable", onResponse: "enabled"}
	],

	create: function () {
		this.inherited(arguments);
		var p = this.params || {};
		if (p.mode === "modify" && p.account) {
			this.show($L("webOS Chat is on"),
				enyo.macroize($L("You chat as {$name}, the username of your webOS Account."), {name: p.account.username}));
		} else {
			this.busy($L("Turning on webOS Chat..."));
			this.$.enable.call({});
		}
	},

	busy: function (text) {
		this.$.workingText.setContent(text);
		this.$.working.show();
		this.$.spinner.show();
		this.$.title.setContent("");
		this.$.text.setContent("");
		this.$.doneButton.setDisabled(true);
	},

	show: function (title, text) {
		this.$.spinner.hide();
		this.$.working.hide();
		this.$.title.setContent(title);
		this.$.text.setContent(text);
		this.$.doneButton.setDisabled(false);
	},

	enabled: function (inSender, r) {
		var outcome = r && r.returnValue && r.outcome;
		if (outcome === "created" || outcome === "unchanged") {
			this.show($L("webOS Chat enabled"),
				enyo.macroize($L("You chat as {$name}, the username of your webOS Account. Your buddies find you by that name."), {name: r.screenName}));
		} else if (outcome === "signed-out") {
			this.show($L("Sign in to your webOS Account"),
				$L("webOS Chat uses your webOS Account. Sign in to it in Accounts, then add webOS Chat."));
		} else if (outcome === "username-required") {
			this.show($L("Choose a username"),
				$L("Your webOS Account still signs in with an email address. Choose a username for it at the App Museum, then add webOS Chat again."));
		} else {
			this.show($L("webOS Chat couldn't be turned on"),
				$L("Check the connection and try again."));
		}
	},

	finish: function () {
		this.$.crossAppResult.sendResult({returnValue: false});
	}
});
